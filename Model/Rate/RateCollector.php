<?php
/**
 * Pigeon Express rate collector: builds API payload, calls API, applies fallbacks.
 *
 * Magento calls carrier->collectRates without payment context; for checkout we best-effort
 * read selected payment method from checkout session quote to include COD in calculation.
 *
 * @copyright Copyright (c). All rights reserved.
 */
declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Rate;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Model\Quote\Address\RateRequest;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Api\RateClientInterface;
use PigeonExpress\Shipping\Exception\ApiException;
use PigeonExpress\Shipping\Model\QuoteAddressLocationPersistor;
use PigeonExpress\Shipping\Model\ResourceModel\City\CollectionFactory as CityCollectionFactory;
use Psr\Log\LoggerInterface;

class RateCollector
{
    /** @var ConfigInterface */
    private $config;

    /** @var RateClientInterface */
    private $rateClient;

    /** @var LoggerInterface */
    private $logger;

    /** @var CheckoutSession|null */
    private $checkoutSession;

    /** @var QuoteAddressLocationPersistor */
    private $locationPersistor;

    /** @var ProductCollectionFactory */
    private $productCollectionFactory;

    /** @var LocationToApiIdResolver */
    private $locationToApiIdResolver;

    /** @var CityCollectionFactory */
    private $cityCollectionFactory;

    public function __construct(
        ConfigInterface $config,
        RateClientInterface $rateClient,
        LoggerInterface $logger,
        QuoteAddressLocationPersistor $locationPersistor,
        ProductCollectionFactory $productCollectionFactory,
        LocationToApiIdResolver $locationToApiIdResolver,
        CityCollectionFactory $cityCollectionFactory,
        ?CheckoutSession $checkoutSession = null
    ) {
        $this->config = $config;
        $this->rateClient = $rateClient;
        $this->logger = $logger;
        $this->locationPersistor = $locationPersistor;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->locationToApiIdResolver = $locationToApiIdResolver;
        $this->cityCollectionFactory = $cityCollectionFactory;
        $this->checkoutSession = $checkoutSession;
    }

    public function getRatePrice(RateRequest $request, string $deliveryType, int $storeId): float
    {
        $pricingMode = $this->config->getDeliveryTypePricingMode($deliveryType, $storeId);
        if ($this->config->isLoggingEnabled($storeId)) {
            $this->logger->info('[PigeonExpress] RateCollector::getRatePrice', [
                'storeId' => $storeId,
                'deliveryType' => $deliveryType,
                'pricingMode' => $pricingMode,
                'package_weight' => $this->calculateCartWeight($request, $storeId),
                'package_value' => $request->getPackageValueWithDiscount(),
            ]);
        }
        if ($pricingMode !== 'dynamic') {
            return (float) $this->config->getDeliveryTypeFlatRate($deliveryType, $storeId);
        }

        try {
            $payload = $this->buildCalculatePayload($request, $deliveryType, $storeId);
            $calc = $this->rateClient->calculate($payload, $storeId);
            if ($this->config->isLoggingEnabled($storeId)) {
                $this->logger->info('[PigeonExpress] RateCollector::getRatePrice API result', [
                    'storeId' => $storeId,
                    'deliveryType' => $deliveryType,
                    'payload' => $payload,
                    'calculation' => $calc,
                ]);
            }
            return (float) ($calc['total_price'] ?? 0.0);
        } catch (ApiException $e) {
            // Special case: missing product dimensions → surface to carrier so method becomes unavailable.
            $msg = (string) $e->getMessage();
            if (strpos($msg, 'Product dimensions (length/width/height) are not configured') !== false) {
                if ($this->config->isLoggingEnabled($storeId)) {
                    $this->logger->warning('[PigeonExpress] Dynamic rate unavailable due to missing product dimensions', [
                        'deliveryType' => $deliveryType,
                        'storeId' => $storeId,
                    ]);
                }
                throw $e;
            }

            // Other API errors → graceful fallback to flat rate.
            if ($this->config->isLoggingEnabled($storeId)) {
                $this->logger->warning('[PigeonExpress] Dynamic rate failed, falling back to flat rate: ' . $msg, [
                    'deliveryType' => $deliveryType,
                    'storeId' => $storeId,
                ]);
            }
            return (float) $this->config->getDeliveryTypeFlatRate($deliveryType, $storeId);
        } catch (\Throwable $e) {
            if ($this->config->isLoggingEnabled($storeId)) {
                $this->logger->warning('[PigeonExpress] Dynamic rate failed, falling back to flat rate: ' . $e->getMessage(), [
                    'deliveryType' => $deliveryType,
                    'storeId' => $storeId,
                ]);
            }
            return (float) $this->config->getDeliveryTypeFlatRate($deliveryType, $storeId);
        }
    }

    /**
     * @return array<string,mixed>
     * @throws ApiException
     */
    private function buildCalculatePayload(RateRequest $request, string $deliveryType, int $storeId): array
    {
        $apiDeliveryType = $deliveryType === ConfigInterface::DELIVERY_TYPE_APS ? 'locker' : $deliveryType;
        $configPickupType = $this->config->getPickupType($storeId);

        // APS requires all cart products to have dimensions configured.
        if ($apiDeliveryType === 'locker' && !$this->cartHasDimensions($request, $storeId)) {
            if ($this->config->isLoggingEnabled($storeId)) {
                $this->logger->info('[PigeonExpress] APS hidden: one or more products missing dimensions', [
                    'storeId' => $storeId,
                ]);
            }
            throw new ApiException(__('APS delivery requires all products to have dimensions (length/width/height) configured.'));
        }

        $dimensions = $this->buildPackageDimensions($request, $storeId);

        $payload = [
            'pickup_type' => $configPickupType,
            'delivery_type' => $apiDeliveryType,
            'packages' => $this->buildPackages($this->calculateCartWeight($request, $storeId), $dimensions),
            'service_type' => 'standard',
            'who_pays' => 'sender',
        ];

        $serviceCodes = $this->buildServiceCodes($deliveryType, $apiDeliveryType, $storeId);
        if (!empty($serviceCodes)) {
            $payload['service_codes'] = $serviceCodes;
        }

        // Pickup: from config only (never from quote).
        if ($configPickupType === ConfigInterface::PICKUP_TYPE_OFFICE) {
            $pickupOfficeId = $this->config->getPickupOfficeId($storeId);
            if ($pickupOfficeId === null || $pickupOfficeId <= 0) {
                if ($this->config->isLoggingEnabled($storeId)) {
                    $this->logger->warning('[PigeonExpress] Pickup type is Office but no pickup office selected in config; rate unavailable.');
                }
                throw new ApiException(__('Place of shipment: please select a pickup office in Pigeon Express carrier configuration.'));
            }
            $payload['pickup_office_id'] = $pickupOfficeId;
        } elseif ($configPickupType === ConfigInterface::PICKUP_TYPE_ADDRESS) {
            $payload['pickup_address'] = $this->buildPickupAddressFromConfig($storeId);
        }

        // Delivery: from quote (customer choice). Quote stores entity_id; API expects api_id.
        // Falls back to first active office/APS from DB when no location selected yet.
        if ($apiDeliveryType === 'office' || $apiDeliveryType === 'locker') {
            $entityId = $this->getSelectedLocationId($request, $storeId);
            $deliveryApiId = null;
            if ($entityId !== null && $entityId !== '') {
                $deliveryApiId = $this->locationToApiIdResolver->resolveToApiId($deliveryType, $entityId);
            }
            if ($deliveryApiId === null) {
                $deliveryApiId = $apiDeliveryType === 'locker'
                    ? $this->locationToApiIdResolver->getFirstActiveApsApiId()
                    : $this->locationToApiIdResolver->getFirstActiveOfficeApiId();
            }
            if ($deliveryApiId !== null) {
                $payload['delivery_office_id'] = $deliveryApiId;
            }
            if ($this->config->isLoggingEnabled($storeId)) {
                $this->logger->info('[PigeonExpress] RateCollector::buildCalculatePayload office/locker', [
                    'storeId' => $storeId,
                    'deliveryType' => $deliveryType,
                    'entity_id' => $entityId ?? null,
                    'delivery_office_id' => $deliveryApiId,
                    'payload' => $payload,
                ]);
            }
            return $payload;
        }

        if ($apiDeliveryType === 'address') {
            $destCity = (string) $request->getDestCity();
            $destPostcode = $request->getDestPostcode();

            // Try to get city from checkout session if RateRequest is empty.
            if (($destCity === '' || stripos($destCity, 'PigeonExpress') !== false)
                && $this->checkoutSession !== null
            ) {
                try {
                    $quote = $this->checkoutSession->getQuote();
                    $addr = $quote ? $quote->getShippingAddress() : null;
                    if ($addr) {
                        $quoteCity = (string) $addr->getCity();
                        if ($quoteCity !== '' && stripos($quoteCity, 'PigeonExpress') === false) {
                            $destCity = $quoteCity;
                            if ($destPostcode === null || $destPostcode === '') {
                                $destPostcode = $addr->getPostcode();
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    // ignore
                }
            }

            // Resolve city_id: try by name/postcode from DB, then fallback to first available city.
            $cityApiId = null;
            if ($destCity !== '' && stripos($destCity, 'PigeonExpress') === false) {
                $cityApiId = $this->resolveCityApiId($destCity, $destPostcode);
            }
            if ($cityApiId === null) {
                $cityApiId = $this->getFirstCityApiId();
            }
            if ($cityApiId !== null) {
                $payload['delivery_address'] = ['city_id' => $cityApiId];
            }

            if ($this->config->isLoggingEnabled($storeId)) {
                $this->logger->info('[PigeonExpress] RateCollector::buildCalculatePayload address', [
                    'storeId' => $storeId,
                    'deliveryType' => $deliveryType,
                    'destCity' => $destCity,
                    'delivery_address' => $payload['delivery_address'] ?? null,
                    'payload' => $payload,
                ]);
            }
            return $payload;
        }

        throw new ApiException(__('Unsupported delivery type "%1".', $deliveryType));
    }

    /**
     * Build pickup_address payload from config (when pickup type is Address).
     *
     * @return array{city_id:int, street_number:string, street_id?:int, street_name?:string, postal_code?:string}
     * @throws ApiException
     */
    private function buildPickupAddressFromConfig(int $storeId): array
    {
        $cityId = $this->config->getPickupAddressCityId($storeId);
        if ($cityId === null || $cityId === '') {
            throw new ApiException(__('Place of shipment: pickup address city is not configured.'));
        }
        $streetNumber = $this->config->getPickupAddressStreetNumber($storeId);
        $streetNumber = $streetNumber !== null && $streetNumber !== '' ? $streetNumber : '1';
        $result = [
            'city_id' => (int) $cityId,
            'street_number' => $streetNumber,
        ];
        $streetId = $this->config->getPickupAddressStreetId($storeId);
        if ($streetId !== null && $streetId !== '') {
            $result['street_id'] = (int) $streetId;
        } else {
            $streetLabel = $this->config->getPickupAddressStreetLabel($storeId);
            if ($streetLabel !== null && $streetLabel !== '') {
                $result['street_name'] = $streetLabel;
            }
            $additionalInfo = trim($streetLabel ?? '') !== '' ? trim($streetLabel) : 'Address';
            if (strlen($additionalInfo) < 3) {
                $additionalInfo = 'Address';
            }
            $result['additional_info'] = $additionalInfo;
        }
        $postalCode = $this->config->getPickupAddressPostalCode($storeId);
        if ($postalCode !== null && $postalCode !== '') {
            $result['postal_code'] = $postalCode;
        }
        return $result;
    }

    /**
     * Build package dimensions (length/width/height in cm) using configured product attributes.
     * Falls back to admin-configured defaults when a product has no dimensions.
     * If defaults are 0 and no product has dimensions, throws ApiException (method becomes unavailable).
     * Address and Office delivery always use this — APS must call cartHasDimensions() first.
     *
     * @return array{length: float, width: float, height: float}
     * @throws ApiException
     */
    private function buildPackageDimensions(RateRequest $request, int $storeId): array
    {
        $lengthAttr = $this->config->getLengthAttributeCode($storeId);
        $widthAttr  = $this->config->getWidthAttributeCode($storeId);
        $heightAttr = $this->config->getHeightAttributeCode($storeId);

        $defaultLength = $this->config->getDefaultLength($storeId);
        $defaultWidth  = $this->config->getDefaultWidth($storeId);
        $defaultHeight = $this->config->getDefaultHeight($storeId);
        $hasConfigDefaults = $defaultLength > 0 && $defaultWidth > 0 && $defaultHeight > 0;

        if (!$lengthAttr || !$widthAttr || !$heightAttr) {
            if ($hasConfigDefaults) {
                return [
                    'length' => $this->normalizeDimension($defaultLength),
                    'width'  => $this->normalizeDimension($defaultWidth),
                    'height' => $this->normalizeDimension($defaultHeight),
                ];
            }
            throw new ApiException(__('Product dimensions (length/width/height) are not configured for this store.'));
        }

        try {
            $productIds = $this->extractProductIds($request);
            if (empty($productIds)) {
                if ($hasConfigDefaults) {
                    return [
                        'length' => $this->normalizeDimension($defaultLength),
                        'width'  => $this->normalizeDimension($defaultWidth),
                        'height' => $this->normalizeDimension($defaultHeight),
                    ];
                }
                throw new ApiException(__('Product dimensions (length/width/height) are not configured for this store.'));
            }

            $collection = $this->productCollectionFactory->create();
            $collection->setStoreId($storeId);
            $collection->addAttributeToSelect([$lengthAttr, $widthAttr, $heightAttr]);
            $collection->addFieldToFilter('entity_id', ['in' => array_unique($productIds)]);
            $collection->load();

            $totalLength = 0.0;
            $totalWidth  = 0.0;
            $totalHeight = 0.0;

            // $productIds may contain duplicates — each occurrence represents qty=1.
            foreach ($productIds as $id) {
                $product = $collection->getItemById($id);
                $l = $product ? $product->getData($lengthAttr) : null;
                $w = $product ? $product->getData($widthAttr) : null;
                $h = $product ? $product->getData($heightAttr) : null;

                if ($l !== null && $l !== '' && (float) $l > 0
                    && $w !== null && $w !== '' && (float) $w > 0
                    && $h !== null && $h !== '' && (float) $h > 0
                ) {
                    $totalLength += (float) $l;
                    $totalWidth  += (float) $w;
                    $totalHeight += (float) $h;
                } else {
                    if (!$hasConfigDefaults) {
                        throw new ApiException(__('Product dimensions (length/width/height) are not configured for this store.'));
                    }
                    $totalLength += $defaultLength;
                    $totalWidth  += $defaultWidth;
                    $totalHeight += $defaultHeight;
                }
            }

            return [
                'length' => $this->normalizeDimension($totalLength),
                'width'  => $this->normalizeDimension($totalWidth),
                'height' => $this->normalizeDimension($totalHeight),
            ];
        } catch (ApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($this->config->isLoggingEnabled($storeId)) {
                $this->logger->warning('[PigeonExpress] Failed to read product dimensions: ' . $e->getMessage());
            }
        }

        if ($hasConfigDefaults) {
            return [
                'length' => $this->normalizeDimension($defaultLength),
                'width'  => $this->normalizeDimension($defaultWidth),
                'height' => $this->normalizeDimension($defaultHeight),
            ];
        }
        throw new ApiException(__('Product dimensions (length/width/height) are not configured for this store.'));
    }

    /**
     * Returns true when every product in the cart has valid dimensions configured,
     * or when admin-configured default dimensions (> 0) cover products that are missing values.
     * Used to gate APS delivery: if any product is missing dimensions and no default is set, APS is hidden.
     */
    private function cartHasDimensions(RateRequest $request, int $storeId): bool
    {
        $lengthAttr = $this->config->getLengthAttributeCode($storeId);
        $widthAttr  = $this->config->getWidthAttributeCode($storeId);
        $heightAttr = $this->config->getHeightAttributeCode($storeId);

        $hasConfigDefaults = $this->config->getDefaultLength($storeId) > 0
            && $this->config->getDefaultWidth($storeId) > 0
            && $this->config->getDefaultHeight($storeId) > 0;

        if ($this->config->isLoggingEnabled($storeId)) {
            $this->logger->info('[PigeonExpress] cartHasDimensions attr codes', [
                'storeId'          => $storeId,
                'lengthAttr'       => $lengthAttr,
                'widthAttr'        => $widthAttr,
                'heightAttr'       => $heightAttr,
                'hasConfigDefaults' => $hasConfigDefaults,
            ]);
        }

        if (!$lengthAttr || !$widthAttr || !$heightAttr) {
            return $hasConfigDefaults;
        }

        try {
            $productIds = $this->extractProductIds($request);
            if (empty($productIds)) {
                return $hasConfigDefaults;
            }

            $collection = $this->productCollectionFactory->create();
            $collection->setStoreId($storeId);
            $collection->addAttributeToSelect([$lengthAttr, $widthAttr, $heightAttr]);
            $collection->addFieldToFilter('entity_id', ['in' => array_unique($productIds)]);
            $collection->load();

            foreach ($productIds as $id) {
                $product = $collection->getItemById($id);
                if (!$product) {
                    if ($this->config->isLoggingEnabled($storeId)) {
                        $this->logger->warning('[PigeonExpress] cartHasDimensions product not found', ['id' => $id]);
                    }
                    if (!$hasConfigDefaults) {
                        return false;
                    }
                    continue;
                }
                $l = $product->getData($lengthAttr);
                $w = $product->getData($widthAttr);
                $h = $product->getData($heightAttr);
                if ($this->config->isLoggingEnabled($storeId)) {
                    $this->logger->info('[PigeonExpress] cartHasDimensions product values', [
                        'product_id' => $id,
                        'sku'        => $product->getSku(),
                        $lengthAttr  => $l,
                        $widthAttr   => $w,
                        $heightAttr  => $h,
                    ]);
                }
                if ($l === null || $l === '' || (float) $l <= 0
                    || $w === null || $w === '' || (float) $w <= 0
                    || $h === null || $h === '' || (float) $h <= 0
                ) {
                    if (!$hasConfigDefaults) {
                        return false;
                    }
                }
            }
            return true;
        } catch (\Throwable $e) {
            if ($this->config->isLoggingEnabled($storeId)) {
                $this->logger->warning('[PigeonExpress] cartHasDimensions check failed: ' . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Extract unique product IDs from RateRequest items.
     *
     * @return int[]
     */
    private function extractProductIds(RateRequest $request): array
    {
        $items = $request->getAllItems();
        if (!is_array($items) || empty($items)) {
            return [];
        }
        $ids = [];
        foreach ($items as $item) {
            if (!is_object($item) || !method_exists($item, 'getProduct')) {
                continue;
            }
            $product = $item->getProduct();
            if (!$product) {
                continue;
            }
            $id = (int) $product->getId();
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    private function normalizeDimension(float $value): float
    {
        // API requires length/width/height >= 1 and <= 200.
        if ($value < 1.0) {
            return 1.0;
        }
        if ($value > 200.0) {
            return 200.0;
        }
        return $value;
    }

    private function normalizeWeight(float $weight): float
    {
        if ($weight <= 0.0 || $weight < 0.1) {
            return 0.1;
        }
        return round($weight, 3);
    }

    /**
     * Split total cart weight into packages of max 30 kg each.
     * All packages share the same dimensions.
     *
     * @param array{length: float, width: float, height: float} $dimensions
     * @return array<int, array{weight: float, length: float, width: float, height: float}>
     */
    private function buildPackages(float $totalWeight, array $dimensions): array
    {
        $maxWeight = 30.0;
        $weight = $this->normalizeWeight($totalWeight);

        if ($weight <= $maxWeight) {
            return [[
                'weight' => $weight,
                'length' => $dimensions['length'],
                'width'  => $dimensions['width'],
                'height' => $dimensions['height'],
            ]];
        }

        $packages = [];
        $remaining = $weight;
        while ($remaining > 0) {
            $packageWeight = min($remaining, $maxWeight);
            $packages[] = [
                'weight' => round($packageWeight, 3),
                'length' => $dimensions['length'],
                'width'  => $dimensions['width'],
                'height' => $dimensions['height'],
            ];
            $remaining = round($remaining - $packageWeight, 3);
        }
        return $packages;
    }

    /**
     * Calculate total cart weight using configured weight attribute.
     * Falls back to default_weight config if attribute not configured or product value missing.
     * Falls back to $request->getPackageWeight() if default_weight is also 0.
     */
    private function calculateCartWeight(RateRequest $request, int $storeId): float
    {
        $weightAttr = $this->config->getWeightAttributeCode($storeId);
        $defaultWeight = $this->config->getDefaultWeight($storeId);

        if (!$weightAttr) {
            if ($defaultWeight > 0) {
                // Count items and multiply by default weight
                $items = $request->getAllItems();
                if (is_array($items) && !empty($items)) {
                    $total = 0.0;
                    foreach ($items as $item) {
                        if (!is_object($item)) {
                            continue;
                        }
                        $total += $defaultWeight * (float) $item->getQty();
                    }
                    if ($total > 0) {
                        return $total;
                    }
                }
            }
            return (float) $request->getPackageWeight();
        }

        try {
            $items = $request->getAllItems();
            if (!is_array($items) || empty($items)) {
                return (float) $request->getPackageWeight();
            }

            $productIds = [];
            $qtyMap = [];
            foreach ($items as $item) {
                if (!is_object($item) || !method_exists($item, 'getProduct')) {
                    continue;
                }
                $product = $item->getProduct();
                if (!$product) {
                    continue;
                }
                $id = (int) $product->getId();
                if ($id > 0) {
                    $productIds[] = $id;
                    $qtyMap[$id] = ($qtyMap[$id] ?? 0) + (float) $item->getQty();
                }
            }

            if (empty($productIds)) {
                return (float) $request->getPackageWeight();
            }

            $collection = $this->productCollectionFactory->create();
            $collection->setStoreId($storeId);
            $collection->addAttributeToSelect([$weightAttr]);
            $collection->addFieldToFilter('entity_id', ['in' => array_unique($productIds)]);
            $collection->load();

            $total = 0.0;
            foreach (array_unique($productIds) as $id) {
                $product = $collection->getItemById($id);
                if (!$product) {
                    return (float) $request->getPackageWeight();
                }
                $w = $product->getData($weightAttr);
                if ($w === null || $w === '' || (float) $w <= 0) {
                    if ($defaultWeight > 0) {
                        $total += $defaultWeight * ($qtyMap[$id] ?? 1);
                        continue;
                    }
                    return (float) $request->getPackageWeight();
                }
                $total += (float) $w * ($qtyMap[$id] ?? 1);
            }

            return $total > 0 ? $total : (float) $request->getPackageWeight();
        } catch (\Throwable $e) {
            if ($this->config->isLoggingEnabled($storeId)) {
                $this->logger->warning('[PigeonExpress] calculateCartWeight failed, using package weight: ' . $e->getMessage());
            }
            return (float) $request->getPackageWeight();
        }
    }

    /**
     * Try to resolve location id for office/APS from:
     * 1) Quote shipping address (via items in RateRequest)
     * 2) Persisted table pigeonexpress_quote_address
     * 3) Fallback: checkout session quote
     */
    private function getSelectedLocationId(RateRequest $request, int $storeId): ?string
    {
        // 1 + 2: from RateRequest items
        try {
            $items = $request->getAllItems();
            if (is_array($items)) {
                foreach ($items as $item) {
                    if (!is_object($item) || !method_exists($item, 'getQuote')) {
                        continue;
                    }
                    $quote = $item->getQuote();
                    if (!$quote || !method_exists($quote, 'getShippingAddress')) {
                        continue;
                    }
                    $addr = $quote->getShippingAddress();
                    if (!$addr) {
                        continue;
                    }
                    $ext = $addr->getExtensionAttributes();
                    if ($ext && method_exists($ext, 'getPigeonexpressLocationId')) {
                        $id = $ext->getPigeonexpressLocationId();
                        if ($id !== null && $id !== '') {
                            return (string) $id;
                        }
                    }
                    if ($addr->getId()) {
                        $row = $this->locationPersistor->getByAddressId((int) $addr->getId());
                        if ($row && !empty($row['location_id'])) {
                            return (string) $row['location_id'];
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            if ($this->config->isLoggingEnabled($storeId)) {
                $this->logger->warning('[PigeonExpress] getSelectedLocationId from RateRequest failed: ' . $e->getMessage());
            }
        }

        // 3: fallback – checkout session quote
        if ($this->checkoutSession === null) {
            return null;
        }
        try {
            $quote = $this->checkoutSession->getQuote();
            if (!$quote) {
                return null;
            }
            $addr = $quote->getShippingAddress();
            if (!$addr) {
                return null;
            }
            $ext = $addr->getExtensionAttributes();
            if (!$ext || !method_exists($ext, 'getPigeonexpressLocationId')) {
                return null;
            }
            $id = $ext->getPigeonexpressLocationId();
            return $id !== null ? (string) $id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Try to resolve city api_id from local DB by name or postcode.
     */
    private function resolveCityApiId(string $cityName, ?string $postcode): ?int
    {
        try {
            if ($postcode !== null && $postcode !== '') {
                $collection = $this->cityCollectionFactory->create();
                $collection->addFieldToFilter('postal_code', $postcode);
                $collection->setPageSize(1);
                $item = $collection->getFirstItem();
                if ($item->getId()) {
                    return (int) $item->getApiId();
                }
            }

            $connection = $this->cityCollectionFactory->create()->getResource()->getConnection();
            $table = $this->cityCollectionFactory->create()->getResource()->getMainTable();

            $select = $connection->select()
                ->from($table, ['api_id'])
                ->where('LOWER(name) = LOWER(?)', $cityName)
                ->limit(1);
            $apiId = $connection->fetchOne($select);
            if ($apiId !== false && $apiId !== null) {
                return (int) $apiId;
            }

            $select = $connection->select()
                ->from($table, ['api_id'])
                ->where('LOWER(name_en) = LOWER(?)', $cityName)
                ->limit(1);
            $apiId = $connection->fetchOne($select);
            if ($apiId !== false && $apiId !== null) {
                return (int) $apiId;
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return null;
    }

    /**
     * Build service_codes object for the calculate endpoint.
     * API expects an associative object {"code": true} or {"code": numeric_value}.
     *
     * @return array<string,mixed>
     */
    private function buildServiceCodes(string $deliveryType, string $apiDeliveryType, int $storeId): array
    {
        $serviceCodes = [];

        // Boolean services: map from config code → actual API code (from GET /additional-services).
        $booleanMap = [
            'pos_payment'          => 'cod_card_fee',
            'special_packaging'    => 'requires_special_packaging',
            'return_at_my_expense' => 'paper_return_receipt',
            'requires_signature'   => 'ID_verification_and_document_signature',
            'return_receipt'       => 'return_receipt',
            'return_documents'     => 'service_return_documents',
        ];
        foreach ($booleanMap as $configCode => $apiCode) {
            if ($this->config->isAdditionalServiceEnabled($configCode, $storeId)) {
                $serviceCodes[$apiCode] = true;
            }
        }

        // Review / test — API only has shipment_test_before_payment (not available for locker).
        if ($apiDeliveryType !== 'locker') {
            $reviewTest = $this->config->getReviewAndTest($deliveryType, $storeId);
            if ($reviewTest === 'review' || $reviewTest === 'test') {
                $serviceCodes['shipment_test_before_payment'] = true;
            }
        }


        // Declared value (fixed amount from config).
        $declaredValue = $this->config->getDeclaredValue($storeId);
        if ($declaredValue !== null) {
            $serviceCodes['declared_value'] = $declaredValue;
        }

        // COD amount: include if customer selected a COD payment method.
        $codAmount = $this->getCodAmountForCalculation($storeId);
        if ($codAmount > 0.0) {
            $serviceCodes['cod_amount'] = $codAmount;
        }

        return $serviceCodes;
    }

    /**
     * Get COD amount (quote subtotal) when the selected payment method is a configured COD method.
     * Returns 0.0 if no COD payment method is selected or checkout session is unavailable.
     */
    private function getCodAmountForCalculation(int $storeId): float
    {
        if ($this->checkoutSession === null) {
            return 0.0;
        }
        try {
            $quote = $this->checkoutSession->getQuote();
            if (!$quote) {
                return 0.0;
            }
            $paymentMethod = $quote->getPayment() ? $quote->getPayment()->getMethod() : null;
            if (!$paymentMethod) {
                return 0.0;
            }
            $codMethods = $this->config->getCodPaymentMethods($storeId);
            if (!in_array($paymentMethod, $codMethods, true)) {
                return 0.0;
            }
            return round((float) $quote->getSubtotal(), 2);
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    /**
     * Get api_id of any city from DB (used as placeholder when customer hasn't entered city yet).
     */
    private function getFirstCityApiId(): ?int
    {
        try {
            $collection = $this->cityCollectionFactory->create();
            $collection->setPageSize(1);
            $item = $collection->getFirstItem();
            return $item->getId() ? (int) $item->getApiId() : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

}

