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
use PigeonExpress\Shipping\Api\RateClientInterface;
use PigeonExpress\Shipping\Api\ShipmentClientInterface;
use PigeonExpress\Shipping\Api\ShipmentSenderInterface;
use PigeonExpress\Shipping\Exception\ApiException;
use PigeonExpress\Shipping\Model\Package\PackageBuilder;
use PigeonExpress\Shipping\Model\Shipment\AddressForm;
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

    /** @var RateClientInterface */
    private $rateClient;

    /** @var OrderAddressLocationPersistor */
    private $locationPersistor;

    /** @var LocationToApiIdResolver */
    private $locationToApiIdResolver;

    /** @var AddressForm */
    private $addressForm;

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

    /** @var \PigeonExpress\Shipping\Api\FreeShippingInterface */
    private $freeShipping;

    /** @var \PigeonExpress\Shipping\Model\Shipment\LabelContents */
    private $labelContents;

    public function __construct(
        ConfigInterface $config,
        ShipmentClientInterface $shipmentClient,
        RateClientInterface $rateClient,
        OrderAddressLocationPersistor $locationPersistor,
        LocationToApiIdResolver $locationToApiIdResolver,
        AddressForm $addressForm,
        ShipmentCollectionFactory $shipmentCollectionFactory,
        PackageBuilder $packageBuilder,
        ServiceCodesBuilder $serviceCodesBuilder,
        LoggerInterface $logger,
        ShipmentFactory $shipmentFactory,
        \PigeonExpress\Shipping\Model\ResourceModel\Shipment $shipmentResource,
        \PigeonExpress\Shipping\Api\FreeShippingInterface $freeShipping,
        \PigeonExpress\Shipping\Model\Shipment\LabelContents $labelContents
    ) {
        $this->config = $config;
        $this->shipmentClient = $shipmentClient;
        $this->rateClient = $rateClient;
        $this->locationPersistor = $locationPersistor;
        $this->locationToApiIdResolver = $locationToApiIdResolver;
        $this->addressForm = $addressForm;
        $this->shipmentCollectionFactory = $shipmentCollectionFactory;
        $this->packageBuilder = $packageBuilder;
        $this->serviceCodesBuilder = $serviceCodesBuilder;
        $this->logger = $logger;
        $this->shipmentFactory = $shipmentFactory;
        $this->shipmentResource = $shipmentResource;
        $this->freeShipping = $freeShipping;
        $this->labelContents = $labelContents;
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

        // What the admin filled in on the order screen, or what the order itself
        // says when nothing was submitted. Either way it goes through the same
        // checks, so a shipment created by any route is checked the same way.
        $fields = isset($overrides['address_fields']) && is_array($overrides['address_fields'])
            ? $overrides['address_fields']
            : $this->addressForm->fromOrder(
                $address,
                $instructions,
                $deliveryType !== '' ? $deliveryType : ConfigInterface::DELIVERY_TYPE_ADDRESS,
                $locationId,
                (string) ($locationData['location_name'] ?? '')
            );

        $checked = $this->addressForm->validate($fields, $storeId, !empty($locationData['free_shipping']));
        if ($this->addressForm->hasErrors($checked['issues'])) {
            throw new LocalizedException(__($this->addressForm->firstError($checked['issues'])));
        }
        $fields = $checked['fields'];

        // The admin may have redirected the parcel, for instance because the
        // customer rang to ask for an office instead of their home. The order
        // keeps what was chosen at checkout; only the waybill changes.
        $deliveryType = $this->addressForm->deliveryType($fields);
        $locationId = $fields['location_id'];

        // Built only now: a locker takes a smaller, lighter parcel than a door
        // does, so the limits depend on where the admin has just decided to send
        // it rather than on what was chosen at checkout.
        $packages = $this->buildPackages($order, $storeId, $deliveryType);

        $receiverName = $fields['contact_name'] !== '' ? $fields['contact_name'] : 'Customer';
        $receiverPhone = $fields['contact_phone'];
        $instructions = $fields['note'];

        // COD detection via configured payment methods.
        $codAmount = $this->detectCodAmount($order);

        // Build pickup from config.
        $pickupType = $this->config->getPickupType($storeId);
        $pickupPayload = $this->buildPickupPayload($pickupType, $storeId);

        // Build delivery payload and determine API delivery type.
        [$apiDeliveryType, $deliveryPayload] = $this->buildDeliveryPayload(
            $deliveryType,
            $locationId,
            $fields
        );

        // Assemble final payload.
        $payload = [
            'receiver_name'  => $receiverName,
            'receiver_phone' => $receiverPhone,
            'pickup_type'    => $pickupType,
            'delivery_type'  => $apiDeliveryType,
            'service_type'   => 'standard',
            'who_pays'       => $fields['who_pays'],
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

        $payload['inventory_items'] = $this->labelContents->build($order, $storeId);

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
     * What Pigeon Express charges today to carry this order as the form describes it.
     *
     * Deliberately separate from what the customer paid. Redirecting a parcel to
     * an office costs something different from carrying it to a door, and a
     * merchant deciding whether to agree to that should be able to see the
     * difference rather than discover it on an invoice. Nothing is written back
     * to the order: this only answers a question.
     *
     * @param array<string, string> $fields Checked shipment form values
     * @return array{price: float, currency: string}
     * @throws LocalizedException
     * @throws ApiException
     */
    public function priceFor(OrderInterface $order, array $fields): array
    {
        $storeId = (int) $order->getStoreId();
        $deliveryType = $this->addressForm->deliveryType($fields);

        [$apiDeliveryType, $deliveryPayload] = $this->buildDeliveryPayload(
            $deliveryType,
            $fields['location_id'] ?? '',
            $fields
        );

        $pickupType = $this->config->getPickupType($storeId);

        $payload = [
            'pickup_type'   => $pickupType,
            'delivery_type' => $apiDeliveryType,
            'service_type'  => 'standard',
            'who_pays'      => $this->addressForm->whoPays(
                $fields,
                $storeId,
                $this->freeShipping->wasAppliedToOrder($order)
            ),
            'packages'      => $this->buildPackages($order, $storeId, $deliveryType),
        ];

        // COD is excluded on purpose. Its fee is charged to the customer through
        // the order total, not to the merchant through the shipping price, so
        // including it here would quote a figure nobody is asked to pay.
        $serviceCodes = $this->serviceCodesBuilder->build(
            $deliveryType,
            $apiDeliveryType,
            $storeId,
            0.0,
            false,
            $this->detectGoodsValue($order)
        );
        if (!empty($serviceCodes)) {
            $payload['service_codes'] = $serviceCodes;
        }

        $payload = array_merge(
            $payload,
            $this->buildPickupPayload($pickupType, $storeId),
            $deliveryPayload
        );

        $calculation = $this->rateClient->calculate($payload, $storeId);

        return [
            'price'    => (float) ($calculation['total_price'] ?? 0.0),
            'currency' => (string) ($calculation['currency'] ?? ''),
        ];
    }

    /**
     * The live shipment for an order, if there is one.
     *
     * Cancelled shipments are deliberately ignored. Once Pigeon Express has
     * cancelled a waybill the order has nothing in transit, so the merchant must
     * be able to create a fresh one. The cancelled row is kept as history and a
     * new row is created alongside it, rather than the old one being reused.
     */
    private function findExistingShipment(int $orderId): ?Shipment
    {
        $collection = $this->shipmentCollectionFactory->create();
        $collection->addFieldToFilter('order_id', ['eq' => $orderId]);
        $collection->addFieldToFilter('status', ['neq' => ShipmentCanceller::STATUS_CANCELLED]);
        // Newest first, so a re-sent order reports its current waybill.
        $collection->setOrder('entity_id', 'DESC');
        $collection->setPageSize(1);
        /** @var Shipment $item */
        $item = $collection->getFirstItem();
        if (!$item || !$item->getId()) {
            return null;
        }
        return $item;
    }

    /**
     * @inheritDoc
     */
    public function packagesFor(OrderInterface $order, string $deliveryType = ''): array
    {
        return $this->buildPackages(
            $order,
            (int) $order->getStoreId(),
            $deliveryType !== '' ? $deliveryType : ConfigInterface::DELIVERY_TYPE_ADDRESS
        );
    }

    /**
     * @inheritDoc
     */
    public function codAmountFor(OrderInterface $order): float
    {
        return $this->detectCodAmount($order);
    }

    /**
     * Build the packages array for the shipment, using the same geometry rules
     * as rating so the waybill matches what the customer was quoted.
     *
     * @return array<int, array{weight:float, length:float, width:float, height:float}>
     */
    private function buildPackages(
        OrderInterface $order,
        int $storeId,
        string $deliveryType = ConfigInterface::DELIVERY_TYPE_ADDRESS
    ): array {
        // The order's own weight, recorded when it was placed, is what shipped.
        // The catalogue is not: a product edited since would silently rewrite
        // the weight on a waybill for an order that left months ago.
        $recorded = (float) $order->getWeight();

        return $this->packageBuilder->build(
            $this->buildPackageLines($order),
            $storeId,
            $deliveryType,
            $recorded,
            $recorded > 0.0 ? $recorded : null
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
     * The recipient is named on the address itself, not only at the top of the
     * payload. Pigeon Express matches customers by phone, and an address block
     * without a contact lets it substitute an address it already holds for that
     * number, silently overriding what the customer entered.
     *
     * @param array<string, string> $fields Checked shipment form values
     * @return array{0:string, 1:array<string,mixed>}
     * @throws LocalizedException
     * @throws ApiException
     */
    private function buildDeliveryPayload(
        string $deliveryType,
        string $locationId,
        array $fields
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

        return ['address', ['delivery_address' => $this->addressForm->toApiAddress($fields)]];
    }


}
