<?php
/**
 * Build Pigeon Express shipment payload from a Magento order and submit via API.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Api\ShipmentClientInterface;
use PigeonExpress\Shipping\Api\ShipmentSenderInterface;
use PigeonExpress\Shipping\Exception\ApiException;
use PigeonExpress\Shipping\Model\Api\AddressResolver;
use PigeonExpress\Shipping\Model\Package\PackageBuilder;
use PigeonExpress\Shipping\Model\Service\ServiceCodesBuilder;
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

    /** @var PackageBuilder */
    private $packageBuilder;

    /** @var ServiceCodesBuilder */
    private $serviceCodesBuilder;

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
        PackageBuilder $packageBuilder,
        ServiceCodesBuilder $serviceCodesBuilder,
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
        $this->packageBuilder = $packageBuilder;
        $this->serviceCodesBuilder = $serviceCodesBuilder;
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

        // Build packages using the same geometry rules as rating.
        $packages = $this->buildPackages($order, $storeId);

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
            'packages'       => $packages,
        ];

        if ($instructions !== '') {
            $payload['note'] = $instructions;
        }

        // Every additional service, including the COD amount, belongs inside
        // service_codes. CreateShipmentRequest has no top level cod_amount and no
        // sms_notification field, so sending them there meant the courier was
        // never told to collect the money.
        $serviceCodes = $this->serviceCodesBuilder->build(
            $deliveryType,
            $apiDeliveryType,
            $storeId,
            $codAmount,
            true,
            $this->detectGoodsValue($order)
        );
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
     * Build the packages array for the shipment, using the same geometry rules
     * as rating so the waybill matches what the customer was quoted.
     *
     * @return array<int, array{weight:float, length:float, width:float, height:float}>
     */
    private function buildPackages(OrderInterface $order, int $storeId): array
    {
        return $this->packageBuilder->build(
            $this->buildPackageLines($order),
            $storeId,
            (float) $order->getWeight()
        );
    }

    /**
     * Turn order items into package lines for PackageBuilder.
     *
     * Child items of configurable and bundle products are skipped so their
     * geometry is not counted twice.
     *
     * @return array<int, array{product_id:int, qty:float}>
     */
    private function buildPackageLines(OrderInterface $order): array
    {
        $items = $order->getItems();
        if (!is_array($items) || empty($items)) {
            return [];
        }

        $lines = [];
        foreach ($items as $item) {
            if ($item->getParentItemId()) {
                continue;
            }
            $productId = (int) $item->getProductId();
            if ($productId <= 0) {
                continue;
            }
            $qty = (float) $item->getQtyOrdered();
            $lines[] = ['product_id' => $productId, 'qty' => $qty > 0 ? $qty : 1.0];
        }

        return $lines;
    }


    /**
     * Value of the goods for insurance purposes.
     *
     * Products only. Shipping is not insured by the carrier, and including it
     * would inflate the sum insured on every order.
     */
    private function detectGoodsValue(OrderInterface $order): float
    {
        $subtotal = (float) $order->getSubtotal();

        return $subtotal > 0.0 ? round($subtotal, 2) : 0.0;
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
        // Strip spaces, dashes, parentheses and dots, keeping digits and a leading +
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

        // 9 raw digits, so assume Bulgarian and prepend +359
        if (preg_match('/^\d{9}$/', $clean)) {
            return '+359' . $clean;
        }

        return $clean;
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
