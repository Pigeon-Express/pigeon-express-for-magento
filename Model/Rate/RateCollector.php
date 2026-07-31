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

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Model\Quote\Address\RateRequest;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Api\RateClientInterface;
use PigeonExpress\Shipping\Exception\ApiException;
use PigeonExpress\Shipping\Model\Package\PackageBuilder;
use PigeonExpress\Shipping\Model\QuoteAddressLocationPersistor;
use PigeonExpress\Shipping\Model\ResourceModel\City\CollectionFactory as CityCollectionFactory;
use PigeonExpress\Shipping\Model\Service\CodFeeResolver;
use PigeonExpress\Shipping\Model\Service\ServiceCodesBuilder;
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

    /** @var PackageBuilder */
    private $packageBuilder;

    /** @var LocationToApiIdResolver */
    private $locationToApiIdResolver;

    /** @var CityCollectionFactory */
    private $cityCollectionFactory;

    /** @var CodFeeResolver */
    private $codFeeResolver;

    /** @var ServiceCodesBuilder */
    private $serviceCodesBuilder;

    public function __construct(
        ConfigInterface $config,
        RateClientInterface $rateClient,
        LoggerInterface $logger,
        QuoteAddressLocationPersistor $locationPersistor,
        PackageBuilder $packageBuilder,
        LocationToApiIdResolver $locationToApiIdResolver,
        CityCollectionFactory $cityCollectionFactory,
        CodFeeResolver $codFeeResolver,
        ServiceCodesBuilder $serviceCodesBuilder,
        ?CheckoutSession $checkoutSession = null
    ) {
        $this->config = $config;
        $this->rateClient = $rateClient;
        $this->logger = $logger;
        $this->locationPersistor = $locationPersistor;
        $this->packageBuilder = $packageBuilder;
        $this->locationToApiIdResolver = $locationToApiIdResolver;
        $this->cityCollectionFactory = $cityCollectionFactory;
        $this->codFeeResolver = $codFeeResolver;
        $this->serviceCodesBuilder = $serviceCodesBuilder;
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

            // While we are already talking to the API, find out what it charges
            // for COD on this cart so the total collector does not have to guess.
            $this->resolveCodFee($payload, $calc, $storeId);

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

        $lines = $this->buildPackageLines($request);

        // APS requires all cart products to have dimensions configured.
        if ($apiDeliveryType === 'locker' && !$this->packageBuilder->hasCompleteDimensions($lines, $storeId)) {
            if ($this->config->isLoggingEnabled($storeId)) {
                $this->logger->info('[PigeonExpress] APS hidden: one or more products missing dimensions', [
                    'storeId' => $storeId,
                ]);
            }
            throw new ApiException(__('APS delivery requires all products to have dimensions (length/width/height) configured.'));
        }

        $payload = [
            'pickup_type' => $configPickupType,
            'delivery_type' => $apiDeliveryType,
            'packages' => $this->packageBuilder->build($lines, $storeId, (float) $request->getPackageWeight()),
            'service_type' => 'standard',
            'who_pays' => 'sender',
        ];

        // COD is deliberately excluded: the quote total collector adds that fee,
        // so including it here would charge the customer twice.
        $serviceCodes = $this->serviceCodesBuilder->build(
            $deliveryType,
            $apiDeliveryType,
            $storeId,
            0.0,
            false,
            (float) $request->getPackageValueWithDiscount()
        );
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
                throw new ApiException(__('Pickup location: please select a pickup office in the Pigeon Express carrier configuration.'));
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
            throw new ApiException(__('Pickup location: the pickup address city is not configured.'));
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
     * Turn RateRequest items into package lines for PackageBuilder.
     *
     * One entry per cart line, carrying its qty. PackageBuilder sums dimensions
     * once per line and weight per unit, which is what rating has always done.
     *
     * @return array<int, array{product_id:int, qty:float}>
     */
    private function buildPackageLines(RateRequest $request): array
    {
        $items = $request->getAllItems();
        if (!is_array($items) || empty($items)) {
            return [];
        }

        $lines = [];
        foreach ($items as $item) {
            if (!is_object($item) || !method_exists($item, 'getProduct')) {
                continue;
            }
            $product = $item->getProduct();
            if (!$product) {
                continue;
            }
            $productId = (int) $product->getId();
            if ($productId <= 0) {
                continue;
            }
            $qty = method_exists($item, 'getQty') ? (float) $item->getQty() : 1.0;
            $lines[] = ['product_id' => $productId, 'qty' => $qty > 0 ? $qty : 1.0];
        }

        return $lines;
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
     * Ask the API what the COD fee is for this cart and hand it to the resolver.
     *
     * The API exposes no COD percentage, so the fee is the difference the
     * calculate endpoint reports between an identical shipment with and without
     * a cod_amount. Nothing is computed locally.
     *
     * @param array<string,mixed> $basePayload The COD free payload already priced
     * @param array<string,mixed> $baseCalc    The API response for that payload
     */
    private function resolveCodFee(array $basePayload, array $baseCalc, int $storeId): void
    {
        $codAmount = $this->getCodAmountForCalculation($storeId);
        if ($codAmount <= 0.0) {
            return;
        }

        try {
            $codPayload = $basePayload;
            $codPayload['service_codes'] = ($codPayload['service_codes'] ?? []) + ['cod_amount' => $codAmount];

            $codCalc = $this->rateClient->calculate($codPayload, $storeId);

            $fee = round(
                (float) ($codCalc['total_service_fees'] ?? 0.0) - (float) ($baseCalc['total_service_fees'] ?? 0.0),
                2
            );

            if ($fee < 0.0) {
                $fee = 0.0;
            }

            $this->codFeeResolver->rememberFee($storeId, $codAmount, $fee);
        } catch (\Throwable $e) {
            // Leave the resolver to fall back to the last API derived rate.
            if ($this->config->isLoggingEnabled($storeId)) {
                $this->logger->warning('[PigeonExpress] COD fee lookup failed: ' . $e->getMessage(), [
                    'storeId' => $storeId,
                    'cod_amount' => $codAmount,
                ]);
            }
        }
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

