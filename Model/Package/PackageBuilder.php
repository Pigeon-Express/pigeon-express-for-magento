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
use PigeonExpress\Shipping\Exception\MissingDimensionsException;
use PigeonExpress\Shipping\Exception\UnshippableException;
use PigeonExpress\Shipping\Model\Service\CarrierLimits;
use Psr\Log\LoggerInterface;

class PackageBuilder
{
    /**
     * Parcels one waybill may carry. Beyond this the shipment needs a second
     * reference number. This one is Pigeon Express's own operating rule rather
     * than something the API reports, so it lives here.
     */
    public const MAX_PACKAGES_PER_SHIPMENT = 10;

    /** API requires a positive dimension. */
    public const MIN_DIMENSION = 1.0;

    /** API requires a positive weight. */
    public const MIN_WEIGHT = 0.1;

    /** @var ConfigInterface */
    private $config;

    /** @var ProductCollectionFactory */
    private $productCollectionFactory;

    /** @var LoggerInterface */
    private $logger;

    /** @var CarrierLimits */
    private $limits;

    public function __construct(
        ConfigInterface $config,
        ProductCollectionFactory $productCollectionFactory,
        LoggerInterface $logger,
        CarrierLimits $limits
    ) {
        $this->config = $config;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->logger = $logger;
        $this->limits = $limits;
    }

    /**
     * Build the packages array for a calculate or create payload.
     *
     * @param array<int, array{product_id:int, qty:float}> $lines One entry per cart or order line
     * @param float|null $fallbackWeight Used when no weight can be derived from products
     * @param float|null $recordedWeight The weight already known for this shipment, which is
     *                                   used as given rather than looked up
     * @return array<int, array{weight:float, length:float, width:float, height:float}>
     * @throws ApiException When dimensions cannot be determined and no defaults are configured
     * @throws UnshippableException When the parcel is too heavy to be carried
     */
    public function build(
        array $lines,
        int $storeId,
        string $deliveryType = ConfigInterface::DELIVERY_TYPE_ADDRESS,
        ?float $fallbackWeight = null,
        ?float $recordedWeight = null
    ): array {
        $dimensions = $this->resolveDimensions($lines, $storeId, $deliveryType);

        // An order carries its own weight, recorded when it was placed. Reading
        // the catalogue instead would let a product edited months later change
        // what a past shipment says it weighs.
        $weight = $recordedWeight !== null && $recordedWeight > 0.0
            ? $recordedWeight
            : $this->resolveWeight($lines, $storeId, $fallbackWeight);

        return $this->split(
            $weight,
            $dimensions,
            $this->countPieces($lines),
            $this->maxWeightFor($deliveryType, $storeId)
        );
    }

    /**
     * The bounds on one parcel, as the carrier last described them.
     *
     * @return array{weight:float, length:float, width:float, height:float}
     */
    public function limitsFor(string $deliveryType, int $storeId = 0): array
    {
        return $this->limits->all($deliveryType, $storeId);
    }

    /**
     * Heaviest single parcel this delivery type is known to carry.
     */
    public function maxWeightFor(string $deliveryType, int $storeId = 0): float
    {
        return $this->limits->maxWeight($deliveryType, $storeId);
    }

    /**
     * The locker compartment, which the API does not check.
     *
     * Everything else about a parcel is the carrier's to judge, and it says so
     * when it refuses. This one it never says, because a locker accepts the
     * booking and only the recipient finds out. Configurable rather than fixed:
     * lockers get replaced.
     *
     * @return array{length:float, width:float, height:float}|null
     */
    public function compartmentFor(string $deliveryType, int $storeId = 0): ?array
    {
        if ($deliveryType !== ConfigInterface::DELIVERY_TYPE_APS) {
            return null;
        }

        $compartment = $this->config->getLockerCompartment($storeId);

        return $compartment['length'] > 0 && $compartment['width'] > 0 && $compartment['height'] > 0
            ? $compartment
            : null;
    }

    /**
     * How many physical items there are, or null when that cannot be known.
     *
     * This is what bounds splitting. Weight divides on paper; objects do not.
     *
     * @param array<int, array{product_id:int, qty:float}> $lines
     */
    private function countPieces(array $lines): ?int
    {
        if (empty($lines)) {
            return null;
        }

        $pieces = 0;
        foreach ($lines as $line) {
            $pieces += (int) ceil(max(1.0, (float) $line['qty']));
        }

        return max(1, $pieces);
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
    public function resolveDimensions(
        array $lines,
        int $storeId,
        string $deliveryType = ConfigInterface::DELIVERY_TYPE_ADDRESS
    ): array {
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
            return $this->requireDefaults($defaults, $hasDefaults, $deliveryType);
        }

        if (empty($lines)) {
            return $this->requireDefaults($defaults, $hasDefaults, $deliveryType);
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
                    throw new MissingDimensionsException(
                        __('Product dimensions (length/width/height) are not configured for this store.')
                    );
                }

                $total['length'] += $defaults['length'];
                $total['width']  += $defaults['width'];
                $total['height'] += $defaults['height'];
            }

            return $this->normalizeDimensions($total, $deliveryType);
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($this->config->isLoggingEnabled($storeId)) {
                $this->logger->warning('[PigeonExpress] Failed to read product dimensions: ' . $e->getMessage());
            }
        }

        return $this->requireDefaults($defaults, $hasDefaults, $deliveryType);
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
     * Splitting is bounded by how many items there actually are. Arithmetic will
     * happily turn one 41 kg item into two 20.5 kg parcels, but nobody can hand
     * the courier half a washing machine, and a waybill describing two parcels
     * when one arrives is worse than being told it cannot be shipped.
     *
     * @param array{length:float, width:float, height:float} $dimensions
     * @param int|null $maxPieces Items available to split across, null when unknown
     * @return array<int, array{weight:float, length:float, width:float, height:float}>
     * @throws UnshippableException
     */
    public function split(
        float $totalWeight,
        array $dimensions,
        ?int $maxPieces = null,
        ?float $maxPackageWeight = null
    ): array {
        $cap = $maxPackageWeight ?? $this->maxWeightFor(ConfigInterface::DELIVERY_TYPE_ADDRESS);
        $weight = $this->normalizeWeight($totalWeight);

        if ($weight <= $cap) {
            return [$dimensions + ['weight' => $weight]];
        }

        $needed = (int) ceil($weight / $cap);

        if ($maxPieces !== null && $needed > $maxPieces) {
            throw new UnshippableException(__(
                'This shipment weighs %1 kg across %2 item(s). Pigeon Express carries at most %3 kg per '
                . 'parcel, and an item cannot be split between parcels. Send it in separate orders, or '
                . 'arrange it with Pigeon Express directly.',
                round($weight, 2),
                $maxPieces,
                $cap
            ));
        }

        $packages = [];
        $remaining = $weight;
        while ($remaining > 0) {
            $packageWeight = min($remaining, $cap);
            $packages[] = $dimensions + ['weight' => round($packageWeight, 3)];
            $remaining = round($remaining - $packageWeight, 3);
        }

        if (count($packages) > self::MAX_PACKAGES_PER_SHIPMENT) {
            // Deliberately not split across two waybills. A second reference
            // number would have to be created, cancelled and labelled alongside
            // the first, and half of that going wrong leaves a worse mess than
            // telling the merchant plainly that this one needs handling by hand.
            throw new UnshippableException(__(
                'This order needs %1 parcels and one Pigeon Express waybill carries at most %2. '
                . 'Split it into separate orders, or arrange the shipment with Pigeon Express directly.',
                count($packages),
                self::MAX_PACKAGES_PER_SHIPMENT
            ));
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

    public function normalizeDimension(float $value, ?float $max = null): float
    {
        if ($value < self::MIN_DIMENSION) {
            return self::MIN_DIMENSION;
        }

        $ceiling = $max ?? $this->limitsFor(ConfigInterface::DELIVERY_TYPE_ADDRESS)['length'];

        return $value > $ceiling ? $ceiling : $value;
    }

    /**
     * Whether a parcel fits a locker compartment.
     *
     * Compared as boxes rather than axis by axis, because a parcel can be turned
     * round: 44 x 61 x 37 goes into a 61 x 37 x 44 compartment perfectly well.
     *
     * @param array{length:float, width:float, height:float} $dimensions
     * @param array{length:float, width:float, height:float} $limits
     */
    public function fitsWithin(array $dimensions, array $limits): bool
    {
        $parcel = [$dimensions['length'], $dimensions['width'], $dimensions['height']];
        $space = [$limits['length'], $limits['width'], $limits['height']];

        rsort($parcel);
        rsort($space);

        foreach ($parcel as $i => $side) {
            if ($side > $space[$i]) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{length:float, width:float, height:float} $dimensions
     * @return array{length:float, width:float, height:float}
     */
    private function normalizeDimensions(
        array $dimensions,
        string $deliveryType = ConfigInterface::DELIVERY_TYPE_ADDRESS
    ): array {
        $limits = $this->limitsFor($deliveryType);
        $compartment = $this->compartmentFor($deliveryType);

        // A locker compartment is a physical space, so an oversized parcel is
        // refused rather than trimmed to fit on paper. The API does not check
        // this, which is exactly why it has to be checked here: the parcel would
        // be accepted, dispatched, and then not go into the locker.
        if ($compartment !== null && !$this->fitsWithin($dimensions, $compartment)) {
            throw new UnshippableException(__(
                'This parcel measures %1 x %2 x %3 cm. A Pigeon Express locker compartment is '
                . '%4 x %5 x %6 cm, so it will not fit. Choose delivery to an address or an office.',
                round($dimensions['length']),
                round($dimensions['width']),
                round($dimensions['height']),
                $compartment['width'],
                $compartment['height'],
                $compartment['length']
            ));
        }

        return [
            'length' => $this->normalizeDimension($dimensions['length'], $limits['length']),
            'width'  => $this->normalizeDimension($dimensions['width'], $limits['width']),
            'height' => $this->normalizeDimension($dimensions['height'], $limits['height']),
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
    private function requireDefaults(
        array $defaults,
        bool $hasDefaults,
        string $deliveryType = ConfigInterface::DELIVERY_TYPE_ADDRESS
    ): array {
        if (!$hasDefaults) {
            throw new MissingDimensionsException(
                __('Product dimensions (length/width/height) are not configured for this store.')
            );
        }

        return $this->normalizeDimensions($defaults, $deliveryType);
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
