<?php
/**
 * Single source of truth for package weight, dimensions and splitting.
 *
 * Rating and shipment creation must agree. They previously did not: the rate
 * summed dimensions across order lines and split the cart at 30 kg, while the
 * waybill took the first product that happened to have dimensions and built one
 * package with a hardcoded 10x10x10 fallback. The customer was quoted one
 * parcel and the courier was told about a different one.
 *
 * The behaviour here is deliberately the rating behaviour, unchanged, so that
 * existing quotes keep their prices. Shipment creation now follows it.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Package;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Exception\ApiException;
use Psr\Log\LoggerInterface;

class PackageBuilder
{
    /** API rejects packages over 30 kg. */
    public const MAX_PACKAGE_WEIGHT = 30.0;

    /** API requires each dimension between 1 and 200 cm. */
    public const MIN_DIMENSION = 1.0;
    public const MAX_DIMENSION = 200.0;

    /** API requires a positive weight. */
    public const MIN_WEIGHT = 0.1;

    /** @var ConfigInterface */
    private $config;

    /** @var ProductCollectionFactory */
    private $productCollectionFactory;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        ConfigInterface $config,
        ProductCollectionFactory $productCollectionFactory,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->logger = $logger;
    }

    /**
     * Build the packages array for a calculate or create payload.
     *
     * @param array<int, array{product_id:int, qty:float}> $lines One entry per cart or order line
     * @param float|null $fallbackWeight Used when no weight can be derived from products
     * @return array<int, array{weight:float, length:float, width:float, height:float}>
     * @throws ApiException When dimensions cannot be determined and no defaults are configured
     */
    public function build(array $lines, int $storeId, ?float $fallbackWeight = null): array
    {
        $dimensions = $this->resolveDimensions($lines, $storeId);
        $weight = $this->resolveWeight($lines, $storeId, $fallbackWeight);

        return $this->split($weight, $dimensions);
    }

    /**
     * Whether every line has usable dimensions, either on the product or via
     * configured defaults. Gates APS delivery, which the API refuses without
     * real dimensions.
     */
    public function hasCompleteDimensions(array $lines, int $storeId): bool
    {
        $lengthAttr = $this->config->getLengthAttributeCode($storeId);
        $widthAttr  = $this->config->getWidthAttributeCode($storeId);
        $heightAttr = $this->config->getHeightAttributeCode($storeId);

        $hasDefaults = $this->hasConfiguredDefaults($storeId);

        if (!$lengthAttr || !$widthAttr || !$heightAttr) {
            return $hasDefaults;
        }

        if (empty($lines)) {
            return $hasDefaults;
        }

        try {
            $products = $this->loadProducts($lines, [$lengthAttr, $widthAttr, $heightAttr], $storeId);

            foreach ($lines as $line) {
                $product = $products[$line['product_id']] ?? null;
                if ($product === null) {
                    if (!$hasDefaults) {
                        return false;
                    }
                    continue;
                }
                if (!$this->hasPositiveDimensions($product, $lengthAttr, $widthAttr, $heightAttr) && !$hasDefaults) {
                    return false;
                }
            }

            return true;
        } catch (\Throwable $e) {
            if ($this->config->isLoggingEnabled($storeId)) {
                $this->logger->warning('[PigeonExpress] Dimension check failed: ' . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Sum dimensions across lines, falling back to configured defaults per line.
     *
     * One addition per line, not per unit. This matches what rating has always
     * done, so changing it would move quoted prices.
     *
     * @param array<int, array{product_id:int, qty:float}> $lines
     * @return array{length:float, width:float, height:float}
     * @throws ApiException
     */
    public function resolveDimensions(array $lines, int $storeId): array
    {
        $lengthAttr = $this->config->getLengthAttributeCode($storeId);
        $widthAttr  = $this->config->getWidthAttributeCode($storeId);
        $heightAttr = $this->config->getHeightAttributeCode($storeId);

        $defaults = [
            'length' => $this->config->getDefaultLength($storeId),
            'width'  => $this->config->getDefaultWidth($storeId),
            'height' => $this->config->getDefaultHeight($storeId),
        ];
        $hasDefaults = $this->hasConfiguredDefaults($storeId);

        if (!$lengthAttr || !$widthAttr || !$heightAttr) {
            return $this->requireDefaults($defaults, $hasDefaults);
        }

        if (empty($lines)) {
            return $this->requireDefaults($defaults, $hasDefaults);
        }

        try {
            $products = $this->loadProducts($lines, [$lengthAttr, $widthAttr, $heightAttr], $storeId);

            $total = ['length' => 0.0, 'width' => 0.0, 'height' => 0.0];

            foreach ($lines as $line) {
                $product = $products[$line['product_id']] ?? null;
                if ($product !== null
                    && $this->hasPositiveDimensions($product, $lengthAttr, $widthAttr, $heightAttr)
                ) {
                    $total['length'] += (float) $product->getData($lengthAttr);
                    $total['width']  += (float) $product->getData($widthAttr);
                    $total['height'] += (float) $product->getData($heightAttr);
                    continue;
                }

                if (!$hasDefaults) {
                    throw new ApiException(
                        __('Product dimensions (length/width/height) are not configured for this store.')
                    );
                }

                $total['length'] += $defaults['length'];
                $total['width']  += $defaults['width'];
                $total['height'] += $defaults['height'];
            }

            return $this->normalizeDimensions($total);
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($this->config->isLoggingEnabled($storeId)) {
                $this->logger->warning('[PigeonExpress] Failed to read product dimensions: ' . $e->getMessage());
            }
        }

        return $this->requireDefaults($defaults, $hasDefaults);
    }

    /**
     * Total weight across lines, per unit, falling back to the configured
     * default weight and then to the caller supplied figure.
     *
     * @param array<int, array{product_id:int, qty:float}> $lines
     */
    public function resolveWeight(array $lines, int $storeId, ?float $fallbackWeight = null): float
    {
        $fallback = $fallbackWeight !== null ? (float) $fallbackWeight : 0.0;
        $weightAttr = $this->config->getWeightAttributeCode($storeId);
        $defaultWeight = $this->config->getDefaultWeight($storeId);

        if (empty($lines)) {
            return $fallback;
        }

        if (!$weightAttr) {
            if ($defaultWeight > 0) {
                $total = 0.0;
                foreach ($lines as $line) {
                    $total += $defaultWeight * $line['qty'];
                }
                if ($total > 0) {
                    return $total;
                }
            }
            return $fallback;
        }

        try {
            $products = $this->loadProducts($lines, [$weightAttr], $storeId);

            $total = 0.0;
            foreach ($lines as $line) {
                $product = $products[$line['product_id']] ?? null;
                if ($product === null) {
                    if ($defaultWeight > 0) {
                        $total += $defaultWeight * $line['qty'];
                        continue;
                    }
                    return $fallback;
                }
                $w = $product->getData($weightAttr);
                if ($w === null || $w === '' || (float) $w <= 0) {
                    if ($defaultWeight > 0) {
                        $total += $defaultWeight * $line['qty'];
                        continue;
                    }
                    return $fallback;
                }
                $total += (float) $w * $line['qty'];
            }

            return $total > 0 ? $total : $fallback;
        } catch (\Throwable $e) {
            if ($this->config->isLoggingEnabled($storeId)) {
                $this->logger->warning('[PigeonExpress] Failed to read product weight: ' . $e->getMessage());
            }
            return $fallback;
        }
    }

    /**
     * Split a total weight into packages of at most MAX_PACKAGE_WEIGHT.
     * All packages share the same dimensions.
     *
     * @param array{length:float, width:float, height:float} $dimensions
     * @return array<int, array{weight:float, length:float, width:float, height:float}>
     */
    public function split(float $totalWeight, array $dimensions): array
    {
        $weight = $this->normalizeWeight($totalWeight);

        if ($weight <= self::MAX_PACKAGE_WEIGHT) {
            return [$dimensions + ['weight' => $weight]];
        }

        $packages = [];
        $remaining = $weight;
        while ($remaining > 0) {
            $packageWeight = min($remaining, self::MAX_PACKAGE_WEIGHT);
            $packages[] = $dimensions + ['weight' => round($packageWeight, 3)];
            $remaining = round($remaining - $packageWeight, 3);
        }

        return $packages;
    }

    public function normalizeWeight(float $weight): float
    {
        if ($weight <= 0.0 || $weight < self::MIN_WEIGHT) {
            return self::MIN_WEIGHT;
        }
        return round($weight, 3);
    }

    public function normalizeDimension(float $value): float
    {
        if ($value < self::MIN_DIMENSION) {
            return self::MIN_DIMENSION;
        }
        if ($value > self::MAX_DIMENSION) {
            return self::MAX_DIMENSION;
        }
        return $value;
    }

    /**
     * @param array{length:float, width:float, height:float} $dimensions
     * @return array{length:float, width:float, height:float}
     */
    private function normalizeDimensions(array $dimensions): array
    {
        return [
            'length' => $this->normalizeDimension($dimensions['length']),
            'width'  => $this->normalizeDimension($dimensions['width']),
            'height' => $this->normalizeDimension($dimensions['height']),
        ];
    }

    private function hasConfiguredDefaults(int $storeId): bool
    {
        return $this->config->getDefaultLength($storeId) > 0
            && $this->config->getDefaultWidth($storeId) > 0
            && $this->config->getDefaultHeight($storeId) > 0;
    }

    /**
     * @param array{length:float, width:float, height:float} $defaults
     * @return array{length:float, width:float, height:float}
     * @throws ApiException
     */
    private function requireDefaults(array $defaults, bool $hasDefaults): array
    {
        if (!$hasDefaults) {
            throw new ApiException(
                __('Product dimensions (length/width/height) are not configured for this store.')
            );
        }

        return $this->normalizeDimensions($defaults);
    }

    /**
     * @param array<int, array{product_id:int, qty:float}> $lines
     * @param string[] $attributes
     * @return array<int, \Magento\Catalog\Model\Product>
     */
    private function loadProducts(array $lines, array $attributes, int $storeId): array
    {
        $ids = [];
        foreach ($lines as $line) {
            if ($line['product_id'] > 0) {
                $ids[] = $line['product_id'];
            }
        }
        $ids = array_unique($ids);

        if (empty($ids)) {
            return [];
        }

        $collection = $this->productCollectionFactory->create();
        $collection->setStoreId($storeId);
        $collection->addAttributeToSelect($attributes);
        $collection->addFieldToFilter('entity_id', ['in' => $ids]);
        $collection->load();

        $byId = [];
        foreach ($ids as $id) {
            $product = $collection->getItemById($id);
            if ($product !== null) {
                $byId[$id] = $product;
            }
        }

        return $byId;
    }

    /**
     * @param \Magento\Catalog\Model\Product $product
     */
    private function hasPositiveDimensions($product, string $lengthAttr, string $widthAttr, string $heightAttr): bool
    {
        foreach ([$lengthAttr, $widthAttr, $heightAttr] as $attr) {
            $v = $product->getData($attr);
            if ($v === null || $v === '' || (float) $v <= 0) {
                return false;
            }
        }

        return true;
    }
}
