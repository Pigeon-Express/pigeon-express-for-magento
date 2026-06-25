<?php
/**
 * Build Pigeon Express shipment payload from a Magento order and submit via API.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Api\ShipmentClientInterface;
use PigeonExpress\Shipping\Api\ShipmentSenderInterface;
use PigeonExpress\Shipping\Exception\ApiException;
use PigeonExpress\Shipping\Model\Api\AddressResolver;
use PigeonExpress\Shipping\Model\Rate\LocationToApiIdResolver;
use PigeonExpress\Shipping\Model\ResourceModel\Shipment\CollectionFactory as ShipmentCollectionFactory;
use Psr\Log\LoggerInterface;

class ShipmentSender implements ShipmentSenderInterface
{
    /** @var ConfigInterface */
    private $config;

    /** @var ShipmentClientInterface */
    private $shipmentClient;

    /** @var OrderAddressLocationPersistor */
    private $locationPersistor;

    /** @var LocationToApiIdResolver */
    private $locationToApiIdResolver;

    /** @var AddressResolver */
    private $addressResolver;

    /** @var ShipmentCollectionFactory */
    private $shipmentCollectionFactory;

    /** @var ProductCollectionFactory */
    private $productCollectionFactory;

    /** @var LoggerInterface */
    private $logger;

    /** @var ShipmentFactory */
    private $shipmentFactory;

    /** @var \PigeonExpress\Shipping\Model\ResourceModel\Shipment */
    private $shipmentResource;

    public function __construct(
        ConfigInterface $config,
        ShipmentClientInterface $shipmentClient,
        OrderAddressLocationPersistor $locationPersistor,
        LocationToApiIdResolver $locationToApiIdResolver,
        AddressResolver $addressResolver,
        ShipmentCollectionFactory $shipmentCollectionFactory,
        ProductCollectionFactory $productCollectionFactory,
        LoggerInterface $logger,
        ShipmentFactory $shipmentFactory,
        \PigeonExpress\Shipping\Model\ResourceModel\Shipment $shipmentResource
    ) {
        $this->config = $config;
        $this->shipmentClient = $shipmentClient;
        $this->locationPersistor = $locationPersistor;
        $this->locationToApiIdResolver = $locationToApiIdResolver;
        $this->addressResolver = $addressResolver;
        $this->shipmentCollectionFactory = $shipmentCollectionFactory;
        $this->productCollectionFactory = $productCollectionFactory;
        $this->logger = $logger;
        $this->shipmentFactory = $shipmentFactory;
        $this->shipmentResource = $shipmentResource;
    }

    /**
     * @inheritDoc
     */
    public function send(OrderInterface $order, array $overrides = []): Shipment
    {
        if (!$order->getId()) {
            throw new LocalizedException(__('Cannot send shipment: order has no ID.'));
        }

        $storeId = (int) $order->getStoreId();

        // Guard: check carrier.
        $shippingMethod = (string) $order->getShippingMethod();
        if (strpos($shippingMethod, 'pigeonexpress_') !== 0) {
            throw new LocalizedException(__('Order does not use Pigeon Express shipping.'));
        }

        // Guard: check not already sent.
        $existing = $this->findExistingShipment((int) $order->getId());
        if ($existing !== null) {
            throw new LocalizedException(
                __('Shipment already created for this order (reference: %1).', $existing->getReferenceNumber())
            );
        }

        if ($this->config->isLoggingEnabled($storeId)) {
            $this->logger->info('[PigeonExpress] ShipmentSender::send start', [
                'order_id'        => $order->getId(),
                'shipping_method' => $shippingMethod,
                'storeId'         => $storeId,
            ]);
        }

        // Get shipping address.
        $address = $order->getShippingAddress();
        if (!$address) {
            throw new LocalizedException(__('Order has no shipping address.'));
        }

        // Read PE location data.
        $locationData = $this->locationPersistor->getByAddressId((int) $address->getId());
        $deliveryType = $locationData['delivery_type'] ?? '';
        $locationId   = $locationData['location_id'] ?? '';
        $instructions = $locationData['instructions'] ?? '';

        // Build packages.
        $packageData = $this->buildPackage($order, $storeId);

        // Receiver info.
        $receiverName = trim($address->getFirstname() . ' ' . $address->getLastname());
        if ($receiverName === '') {
            $receiverName = 'Customer';
        }
        $receiverPhone = isset($overrides['receiver_phone']) && $overrides['receiver_phone'] !== ''
            ? (string) $overrides['receiver_phone']
            : (string) $address->getTelephone();
        if ($receiverPhone === '') {
            throw new LocalizedException(__('Shipping address is missing telephone number.'));
        }
        $receiverPhone = $this->normalizePhone($receiverPhone);

        // COD detection via configured payment methods.
        $codAmount = $this->detectCodAmount($order);

        // Build pickup from config.
        $pickupType = $this->config->getPickupType($storeId);
        $pickupPayload = $this->buildPickupPayload($pickupType, $storeId);

        // Build delivery payload and determine API delivery type.
        [$apiDeliveryType, $deliveryPayload] = $this->buildDeliveryPayload(
            $deliveryType,
            $locationId,
            $address,
            $storeId
        );

        // Assemble final payload.
        $payload = [
            'receiver_name'  => $receiverName,
            'receiver_phone' => $receiverPhone,
            'pickup_type'    => $pickupType,
            'delivery_type'  => $apiDeliveryType,
            'service_type'   => 'standard',
            'who_pays'       => 'sender',
            'packages'       => [$packageData],
        ];

        if ($codAmount > 0) {
            $payload['cod_amount'] = $codAmount;
        }

        if ($instructions !== '') {
            $payload['note'] = $instructions;
        }

        // Locker (APS) delivery requires SMS notification per PE API.
        if ($apiDeliveryType === 'locker') {
            $payload['sms_notification'] = true;
        }

        // Build service_codes using the same API codes as the calculate endpoint.
        $serviceCodes = $this->buildServiceCodes($deliveryType, $apiDeliveryType, $storeId);
        if (!empty($serviceCodes)) {
            $payload['service_codes'] = $serviceCodes;
        }

        $payload['inventory_items'] = $this->buildInventoryItems($order);

        $payload = array_merge($payload, $pickupPayload, $deliveryPayload);

        if ($this->config->isLoggingEnabled($storeId)) {
            $this->logger->info('[PigeonExpress] ShipmentSender::send payload', [
                'order_id' => $order->getId(),
                'storeId'  => $storeId,
                'payload'  => $payload,
            ]);
        }

        // Call API.
        $result = $this->shipmentClient->create($payload, $storeId);

        // Save to DB.
        /** @var Shipment $shipment */
        $shipment = $this->shipmentFactory->create();
        $shipment->setOrderId((int) $order->getId());
        $shipment->setReferenceNumber($result['reference_number'] ?? '');
        $shipment->setTrackingNumber($result['tracking_number'] ?? null);
        $shipment->setStatus($result['status'] ?? 'sent');
        $shipment->setDeliveryPrice(isset($result['delivery_price']) ? (float) $result['delivery_price'] : null);
        $shipment->setPayload(json_encode($payload));
        $shipment->setResponse(json_encode($result));
        $this->shipmentResource->save($shipment);

        if ($this->config->isLoggingEnabled($storeId)) {
            $this->logger->info('[PigeonExpress] ShipmentSender::send success', [
                'order_id'         => $order->getId(),
                'reference_number' => $result['reference_number'],
                'tracking_number'  => $result['tracking_number'] ?? null,
            ]);
        }

        return $shipment;
    }

    /**
     * Check if a shipment record already exists for the given order.
     */
    private function findExistingShipment(int $orderId): ?Shipment
    {
        $collection = $this->shipmentCollectionFactory->create();
        $collection->addFieldToFilter('order_id', ['eq' => $orderId]);
        $collection->setPageSize(1);
        /** @var Shipment $item */
        $item = $collection->getFirstItem();
        if (!$item || !$item->getId()) {
            return null;
        }
        return $item;
    }

    /**
     * Build package array from order weight and dimensions.
     *
     * @return array{weight: float, length: float, width: float, height: float}
     */
    private function buildPackage(OrderInterface $order, int $storeId): array
    {
        $rawWeight = $this->calculateOrderWeight($order, $storeId);
        if ($rawWeight <= 0.0) {
            $weight = 0.5;
        } elseif ($rawWeight < 0.1) {
            $weight = 0.1;
        } else {
            $weight = round($rawWeight, 3);
        }

        $dimensions = $this->buildPackageDimensions($order, $storeId);

        return [
            'weight' => $weight,
            'length' => $dimensions['length'],
            'width'  => $dimensions['width'],
            'height' => $dimensions['height'],
        ];
    }

    /**
     * Build package dimensions from product attributes; silently use defaults on failure.
     *
     * @return array{length: float, width: float, height: float}
     */
    private function buildPackageDimensions(OrderInterface $order, int $storeId): array
    {
        $default = ['length' => 10.0, 'width' => 10.0, 'height' => 10.0];

        $lengthAttr = $this->config->getLengthAttributeCode($storeId);
        $widthAttr  = $this->config->getWidthAttributeCode($storeId);
        $heightAttr = $this->config->getHeightAttributeCode($storeId);

        if (!$lengthAttr || !$widthAttr || !$heightAttr) {
            return $default;
        }

        try {
            $items = $order->getItems();
            if (!is_array($items) || empty($items)) {
                return $default;
            }

            $productIds = [];
            foreach ($items as $item) {
                $productId = (int) $item->getProductId();
                if ($productId > 0) {
                    $productIds[] = $productId;
                }
            }

            if (empty($productIds)) {
                return $default;
            }

            $collection = $this->productCollectionFactory->create();
            $collection->setStoreId($storeId);
            $collection->addAttributeToSelect([$lengthAttr, $widthAttr, $heightAttr]);
            $collection->addFieldToFilter('entity_id', ['in' => array_unique($productIds)]);
            $collection->load();

            foreach ($productIds as $id) {
                $product = $collection->getItemById($id);
                if (!$product) {
                    continue;
                }
                $l = $product->getData($lengthAttr);
                $w = $product->getData($widthAttr);
                $h = $product->getData($heightAttr);
                if ($l !== null && $l !== '' && (float) $l > 0
                    && $w !== null && $w !== '' && (float) $w > 0
                    && $h !== null && $h !== '' && (float) $h > 0
                ) {
                    return [
                        'length' => $this->normalizeDimension((float) $l),
                        'width'  => $this->normalizeDimension((float) $w),
                        'height' => $this->normalizeDimension((float) $h),
                    ];
                }
            }
        } catch (\Throwable $e) {
            if ($this->config->isLoggingEnabled($storeId)) {
                $this->logger->warning('[PigeonExpress] ShipmentSender: failed to read product dimensions: ' . $e->getMessage());
            }
        }

        return $default;
    }

    /**
     * Calculate total order weight using configured weight attribute.
     * Falls back to $order->getWeight() if attribute not configured or value missing.
     */
    private function calculateOrderWeight(OrderInterface $order, int $storeId): float
    {
        $weightAttr = $this->config->getWeightAttributeCode($storeId);
        if (!$weightAttr) {
            return (float) ($order->getWeight() ?? 0);
        }

        try {
            $items = $order->getItems();
            if (!is_array($items) || empty($items)) {
                return (float) ($order->getWeight() ?? 0);
            }

            $productIds = [];
            $qtyMap = [];
            foreach ($items as $item) {
                $productId = (int) $item->getProductId();
                if ($productId > 0) {
                    $productIds[] = $productId;
                    $qtyMap[$productId] = ($qtyMap[$productId] ?? 0) + (float) $item->getQtyOrdered();
                }
            }

            if (empty($productIds)) {
                return (float) ($order->getWeight() ?? 0);
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
                    return (float) ($order->getWeight() ?? 0);
                }
                $w = $product->getData($weightAttr);
                if ($w === null || $w === '' || (float) $w <= 0) {
                    return (float) ($order->getWeight() ?? 0);
                }
                $total += (float) $w * ($qtyMap[$id] ?? 1);
            }

            return $total > 0 ? $total : (float) ($order->getWeight() ?? 0);
        } catch (\Throwable $e) {
            if ($this->config->isLoggingEnabled($storeId)) {
                $this->logger->warning('[PigeonExpress] ShipmentSender: calculateOrderWeight failed: ' . $e->getMessage());
            }
            return (float) ($order->getWeight() ?? 0);
        }
    }

    private function normalizeDimension(float $value): float
    {
        if ($value < 1.0) {
            return 1.0;
        }
        if ($value > 200.0) {
            return 200.0;
        }
        return $value;
    }

    /**
     * Detect COD amount from payment method using configured COD methods list.
     * Returns order subtotal (products only, no shipping, no COD fee).
     */
    private function detectCodAmount(OrderInterface $order): float
    {
        try {
            $storeId = (int) $order->getStoreId();
            $codMethods = $this->config->getCodPaymentMethods($storeId);
            if (empty($codMethods)) {
                return 0.0;
            }
            $method = (string) $order->getPayment()->getMethod();
            if (in_array($method, $codMethods, true)) {
                return max(0.0, round((float) $order->getSubtotal(), 2));
            }
        } catch (\Throwable $e) {
            // Payment not available; treat as non-COD.
        }
        return 0.0;
    }

    /**
     * Build pickup portion of payload.
     *
     * @return array<string,mixed>
     * @throws LocalizedException
     * @throws ApiException
     */
    private function buildPickupPayload(string $pickupType, int $storeId): array
    {
        if ($pickupType === ConfigInterface::PICKUP_TYPE_OFFICE) {
            $pickupOfficeId = $this->config->getPickupOfficeId($storeId);
            if ($pickupOfficeId === null || $pickupOfficeId <= 0) {
                throw new LocalizedException(__('Pickup office is not configured.'));
            }
            return ['pickup_office_id' => $pickupOfficeId];
        }

        // PICKUP_TYPE_ADDRESS
        $cityId = $this->config->getPickupAddressCityId($storeId);
        if ($cityId === null || $cityId === '') {
            throw new LocalizedException(__('Pickup address city is not configured.'));
        }
        $streetNumber = $this->config->getPickupAddressStreetNumber($storeId);
        $streetNumber = ($streetNumber !== null && $streetNumber !== '') ? $streetNumber : '1';
        $result = [
            'city_id'       => (int) $cityId,
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
        return ['pickup_address' => $result];
    }

    /**
     * Build delivery portion of payload and return [apiDeliveryType, payloadFragment].
     *
     * @param string $deliveryType
     * @param string $locationId
     * @param \Magento\Sales\Api\Data\OrderAddressInterface $address
     * @param int $storeId
     * @return array{0:string, 1:array<string,mixed>}
     * @throws LocalizedException
     * @throws ApiException
     */
    private function buildDeliveryPayload(
        string $deliveryType,
        string $locationId,
        \Magento\Sales\Api\Data\OrderAddressInterface $address,
        int $storeId
    ): array {
        if ($deliveryType === ConfigInterface::DELIVERY_TYPE_OFFICE
            || $deliveryType === ConfigInterface::DELIVERY_TYPE_APS
        ) {
            $apiDeliveryType = $deliveryType === ConfigInterface::DELIVERY_TYPE_APS ? 'locker' : 'office';
            $apiId = $this->locationToApiIdResolver->resolveToApiId($deliveryType, $locationId);
            if ($apiId === null) {
                throw new LocalizedException(__('Could not resolve Pigeon Express delivery location.'));
            }
            return [$apiDeliveryType, ['delivery_office_id' => $apiId]];
        }

        // Default: address delivery.
        $streetRaw = $address->getStreet();
        $resolved = $this->addressResolver->resolve([
            'city'     => $address->getCity(),
            'street'   => $streetRaw,
            'postcode' => $address->getPostcode(),
        ], $storeId);
        return ['address', ['delivery_address' => $resolved]];
    }

    /**
     * Build inventory_items array from order items (required by PE API).
     *
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @return array
     */
    /**
     * Normalize phone to international format for PE API.
     *
     * Bulgarian local (08XXXXXXXXX / 10 digits starting with 0) → +3598XXXXXXXX
     * 00359... → +359...
     * Already +... → kept as-is
     */
    public static function normalizePhone(string $raw): string
    {
        $raw = trim($raw);
        // Strip spaces, dashes, parentheses, dots — keep digits and leading +
        $clean = preg_replace('/[\s\-\(\)\.]/', '', $raw);

        if (strpos($clean, '+') === 0) {
            return $clean;
        }

        // 00XXXXX → +XXXXX
        if (strpos($clean, '00') === 0) {
            return '+' . substr($clean, 2);
        }

        // Bulgarian local: 0XXXXXXXXX (10 digits starting with 0) → +359XXXXXXXXX
        if (preg_match('/^0(\d{9})$/', $clean, $m)) {
            return '+359' . $m[1];
        }

        // 9 raw digits — assume Bulgarian, prepend +359
        if (preg_match('/^\d{9}$/', $clean)) {
            return '+359' . $clean;
        }

        return $clean;
    }

    /**
     * Build service_codes array using the same API codes as the calculate endpoint.
     *
     * @return array<string,mixed>
     */
    private function buildServiceCodes(string $deliveryType, string $apiDeliveryType, int $storeId): array
    {
        $serviceCodes = [];

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

        if ($apiDeliveryType !== 'locker') {
            $reviewAndTest = $this->config->getReviewAndTest($deliveryType, $storeId);
            if ($reviewAndTest === 'review' || $reviewAndTest === 'test') {
                $serviceCodes['shipment_test_before_payment'] = true;
            }
        }

        $declaredValue = $this->config->getDeclaredValue($storeId);
        if ($declaredValue !== null) {
            $serviceCodes['declared_value'] = $declaredValue;
        }

        return $serviceCodes;
    }

    private function buildInventoryItems(\Magento\Sales\Api\Data\OrderInterface $order): array
    {
        $items = [];
        foreach ($order->getItems() as $item) {
            // Skip child items (e.g. simple product under configurable).
            if ($item->getParentItemId()) {
                continue;
            }
            $qty = (int) $item->getQtyOrdered();
            if ($qty <= 0) {
                $qty = 1;
            }
            $price = round((float) $item->getPrice(), 2);
            if ($price <= 0) {
                $price = 0.01;
            }
            $name = (string) $item->getName();
            $items[] = [
                'name'        => $name,
                'description' => $name,
                'quantity'    => $qty,
                'price'       => $price,
            ];
        }

        // Fallback: API requires at least one item.
        if (empty($items)) {
            $items[] = ['name' => 'Product', 'description' => 'Product', 'quantity' => 1, 'price' => 0.01];
        }

        return $items;
    }
}
