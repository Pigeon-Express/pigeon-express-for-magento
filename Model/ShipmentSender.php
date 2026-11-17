<?php
/**
 * Build Pigeon Express shipment payload from a Magento order and submit via API.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model;

use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Api\RateClientInterface;
use PigeonExpress\Shipping\Api\ShipmentClientInterface;
use PigeonExpress\Shipping\Api\ShipmentSenderInterface;
use PigeonExpress\Shipping\Exception\ApiException;
use PigeonExpress\Shipping\Exception\OrderLockedException;
use PigeonExpress\Shipping\Exception\ReplayedShipmentException;
use PigeonExpress\Shipping\Model\Api\ShipmentClient;
use PigeonExpress\Shipping\Model\Shipment\OrderLock;
use PigeonExpress\Shipping\Model\Shipment\WhoPaysFacts;
use PigeonExpress\Shipping\Model\Status\ShipmentDetailsStore;
use PigeonExpress\Shipping\Model\Status\StatusCodes;
use PigeonExpress\Shipping\Model\Package\PackageBuilder;
use PigeonExpress\Shipping\Model\Phone\BulgarianPhone;
use PigeonExpress\Shipping\Model\Pickup\PickupPayload;
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

    /** @var OrderLock */
    private $orderLock;

    /** @var PickupPayload */
    private $pickupPayload;

    /** @var EventManager */
    private $eventManager;

    /** @var ShipmentDetailsStore */
    private $detailsStore;

    /** @var WhoPaysFacts|null */
    private $whoPaysFacts;

    /** Dispatched once a new shipment is stored, whichever way it was created. */
    public const EVENT_CREATED = 'pigeonexpress_shipment_created';

    /** Status code Pigeon Express gives a cancelled shipment. */
    public const API_STATUS_CANCELLED = 'shipment_cancelled';

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
        \PigeonExpress\Shipping\Model\Shipment\LabelContents $labelContents,
        OrderLock $orderLock,
        PickupPayload $pickupPayload,
        EventManager $eventManager,
        ShipmentDetailsStore $detailsStore,
        ?WhoPaysFacts $whoPaysFacts = null
    ) {
        $this->whoPaysFacts = $whoPaysFacts;
        $this->eventManager = $eventManager;
        $this->detailsStore = $detailsStore;
        $this->pickupPayload = $pickupPayload;
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
        $this->orderLock = $orderLock;
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

        // One create per order at a time. Without this, two Send requests
        // arriving together both find no shipment and both create one.
        $orderId = (int) $order->getId();
        if (!$this->orderLock->acquire($orderId)) {
            throw new OrderLockedException(__(
                'A shipment for this order is being created right now. Reload the order in a moment.'
            ));
        }

        try {
            return $this->sendLocked($order, $overrides, $storeId, $shippingMethod);
        } finally {
            $this->orderLock->release($orderId);
        }
    }

    /**
     * send(), once this request holds the order's lock.
     *
     * @param array<string, mixed> $overrides
     * @throws LocalizedException
     */
    private function sendLocked(
        OrderInterface $order,
        array $overrides,
        int $storeId,
        string $shippingMethod
    ): Shipment {
        // Guard: check not already sent. Read inside the lock, so a request
        // that waited for another sees the shipment it created.
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

        $built = $this->payloadFor($order, $overrides);
        $payload = $built['payload'];
        $fields = $built['fields'];

        if ($this->config->isLoggingEnabled($storeId)) {
            $this->logger->info('[PigeonExpress] ShipmentSender::send payload', [
                'order_id' => $order->getId(),
                'storeId'  => $storeId,
                'payload'  => $payload,
            ]);
        }

        // Call API. Sent once: see ShipmentClient::create().
        $result = $this->shipmentClient->create($payload, $storeId);

        // A replayed answer may be for a shipment cancelled since.
        if ((string) ($result['replay'] ?? ShipmentClient::REPLAY_NONE) !== ShipmentClient::REPLAY_NONE) {
            $this->refuseReplayedCancelled(
                (int) $order->getId(),
                (string) ($result['reference_number'] ?? ''),
                $storeId,
                (string) $result['replay']
            );
        }

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
        // Kept so that, should this waybill be cancelled, the form for the next
        // one opens with what was sent rather than with the order's defaults.
        $shipment->setFormFields(json_encode($fields));
        // Every shipment starts as "Created", as in WooCommerce, so the panel
        // shows a status at once. No check time: the carrier was not asked.
        $shipment->setData('status_code', StatusCodes::REGISTERED);
        $shipment->setData('status_at', gmdate('Y-m-d H:i:s'));
        $this->shipmentResource->save($shipment);

        $this->afterCreate($order, $shipment, $storeId);

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
     * Tell the rest of the shop, and read what Pigeon Express charged. Neither
     * may undo a shipment that exists: failures are logged and left.
     */
    private function afterCreate(OrderInterface $order, Shipment $shipment, int $storeId): void
    {
        try {
            $this->eventManager->dispatch(self::EVENT_CREATED, [
                'order'        => $order,
                'shipment_row' => $shipment->getData(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[PigeonExpress] Shipment created observer failed: ' . $e->getMessage());
        }

        try {
            $this->detailsStore->fetch($shipment, $storeId);
        } catch (\Throwable $e) {
            $this->logger->info('[PigeonExpress] Details of ' . $shipment->getReferenceNumber()
                . ' not read after create: ' . $e->getMessage());
        }
    }

    /**
     * The create body for this order, and the checked form it was built from.
     *
     * The same body serves a change of the shipment (PUT /shipments/{ref}):
     * the carrier clears whatever a change does not carry, so services, cash
     * on delivery, declared value, packages, note and return office all go
     * again. See ShipmentEditor.
     *
     * @param array<string, mixed> $overrides `address_fields`: the checked shipment form
     * @return array{payload: array<string, mixed>, fields: array<string, mixed>}
     * @throws LocalizedException
     */
    public function payloadFor(OrderInterface $order, array $overrides = []): array
    {
        $storeId = (int) $order->getStoreId();

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
        $unattended = !(isset($overrides['address_fields']) && is_array($overrides['address_fields']));
        $fields = !$unattended
            ? $overrides['address_fields']
            : $this->addressForm->fromOrder(
                $address,
                $instructions,
                $deliveryType !== '' ? $deliveryType : ConfigInterface::DELIVERY_TYPE_ADDRESS,
                $locationId,
                (string) ($locationData['location_name'] ?? '')
            );

        // The same facts the order screen uses, so a shipment created in bulk
        // or by cron bills the same side the panel would have suggested: a
        // fixed price COD order bills the sender, as in WooCommerce.
        [$nothingToCollect, $fixedPrice] = $this->whoPaysInputs($order, $locationData);
        $checked = $this->addressForm->validate($fields, $storeId, $nothingToCollect, $fixedPrice);
        if ($this->addressForm->hasErrors($checked['issues'])) {
            throw new LocalizedException(__($this->addressForm->firstError($checked['issues'])));
        }
        if ($unattended) {
            $this->refuseGuessedStreet($fields, $checked);
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
        $packages = $this->buildPackages($order, $storeId, $deliveryType, $fields);

        $receiverName = $fields['contact_name'] !== '' ? $fields['contact_name'] : 'Customer';
        $receiverPhone = $fields['contact_phone'];
        $instructions = $fields['note'];

        // What the courier collects: the admin's figure when they gave one,
        // otherwise what the customer was shown at checkout.
        $codAmount = $this->addressForm->codAmount($fields)
            ?? $this->detectCodAmount($order, $fields['who_pays']);

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
            'delivery_type'  => $apiDeliveryType,
            'service_type'   => 'standard',
            'who_pays'       => $fields['who_pays'],
            'packages'       => $packages,
        ];

        // The customer's email, as WooCommerce sends the billing email, so
        // Pigeon Express can send its own notices. Left out when it is not a
        // usable address (a marketplace placeholder, say).
        $email = trim((string) $order->getCustomerEmail());
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $payload['receiver_email'] = $email;
        }

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
            $this->detectGoodsValue($order),
            $this->serviceOverrides($fields)
        );
        if (!empty($serviceCodes)) {
            $payload['service_codes'] = $serviceCodes;
        }

        $payload['inventory_items'] = $this->labelContents->build($order, $storeId);

        // Where the shipment is handed in: with the locker pickup type that
        // depends on the packages and services just decided.
        $pickupPayload = $this->pickupPayload->build($storeId, $packages, $serviceCodes);

        $payload += $this->returnFields($storeId);

        // The order number, which Pigeon Express keeps with the shipment and
        // echoes back, so a waybill can be traced to its order. Shown nowhere
        // here; it is for the carrier's side.
        $externalReference = trim((string) $order->getIncrementId());
        if ($externalReference !== '') {
            $payload['external_reference'] = mb_substr(
                $externalReference,
                0,
                ShipmentClient::EXTERNAL_REFERENCE_MAX
            );
        }

        // Sent only when switched on: the carrier defaults it to false, so a
        // store that does not use it keeps sending what it sent before. The
        // carrier stores it with the shipment, so later reprints keep it too.
        if ($this->config->isHideContentOnLabel($storeId)) {
            $payload['hide_content_on_label'] = true;
        }

        $payload = array_merge($payload, $pickupPayload, $deliveryPayload);

        return ['payload' => $payload, 'fields' => $fields];
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

        [$nothingToCollect, $fixedPrice] = $this->whoPaysInputs($order);
        $whoPays = $this->addressForm->whoPays($fields, $storeId, $nothingToCollect, $fixedPrice);

        $packages = $this->buildPackages($order, $storeId, $deliveryType, $fields);
        $payload = [
            'delivery_type' => $apiDeliveryType,
            'service_type'  => 'standard',
            'who_pays'      => $whoPays,
            'packages'      => $packages,
        ];

        // COD is included, exactly as send() includes it. The payment method of a
        // placed order cannot change, so the waybill Create makes will carry the
        // COD amount and be charged its fee; a quote without it would understate
        // what a change of delivery agreed on the phone really costs.
        $serviceCodes = $this->serviceCodesBuilder->build(
            $deliveryType,
            $apiDeliveryType,
            $storeId,
            $this->addressForm->codAmount($fields) ?? $this->detectCodAmount($order, $whoPays),
            true,
            $this->detectGoodsValue($order),
            $this->serviceOverrides($fields)
        );
        if (!empty($serviceCodes)) {
            $payload['service_codes'] = $serviceCodes;
        }

        $payload = array_merge(
            $payload,
            $this->pickupPayload->build($storeId, $packages, $serviceCodes, true),
            $deliveryPayload
        );

        $calculation = $this->rateClient->calculate($payload, $storeId);

        return [
            'price'    => (float) ($calculation['total_price'] ?? 0.0),
            'currency' => (string) ($calculation['currency'] ?? ''),
        ];
    }

    /**
     * A shipment nobody reviewed (cron, bulk create) is not sent to a street
     * that was guessed, as in WooCommerce (ShipmentDefaults::overrides_for).
     *
     * Fine when the customer gave the address through the Pigeon Express
     * fields at checkout (the order carries the city id), or when the street
     * of the free text matched exactly one street of the carrier. A street
     * that matched nothing, or more than one, stays with the merchant.
     *
     * @param array<string, mixed> $fromOrder The form as read from the order, before validate()
     * @param array{fields: array<string, mixed>, issues: array<string, mixed>} $checked
     * @throws LocalizedException
     */
    private function refuseGuessedStreet(array $fromOrder, array $checked): void
    {
        if ($this->addressForm->deliveryType($checked['fields']) !== ConfigInterface::DELIVERY_TYPE_ADDRESS) {
            return;
        }

        if ((string) ($fromOrder['city_id'] ?? '') !== '') {
            return;
        }

        if ((string) ($checked['fields']['street_id'] ?? '') === '' || isset($checked['issues']['street'])) {
            throw new LocalizedException(__('Pick the destination street before creating the shipment.'));
        }
    }

    /**
     * "Nothing left to collect" and "fixed price" for AddressForm::validate(),
     * from WhoPaysFacts when it is wired (always, outside unit tests).
     *
     * @param array<string, mixed>|null $locationData
     * @return array{0: bool, 1: bool}
     */
    private function whoPaysInputs(OrderInterface $order, ?array $locationData = null): array
    {
        if ($this->whoPaysFacts !== null) {
            $facts = $this->whoPaysFacts->forOrder($order);

            return [$this->whoPaysFacts->nothingToCollect($order), !empty($facts['fixed'])];
        }

        $free = $locationData !== null
            ? !empty($locationData['free_shipping'])
            : $this->freeShipping->wasAppliedToOrder($order);

        return [$free || !$this->isCashOnDelivery($order), false];
    }

    /**
     * After a create the carrier answered with its stored answer to an
     * identical earlier create: refuse it when that shipment has been
     * cancelled since, so nothing is stored for a waybill that does not exist.
     *
     * The carrier answers a create identical to one made less than 30 seconds
     * earlier with that first answer, whatever became of the shipment: created,
     * cancelled and created again within that time comes back as the cancelled
     * one. Known locally when it was cancelled from this order; otherwise the
     * carrier is asked. When it cannot be asked the shipment is kept: a real
     * one left off the order would be created a second time, and a cancelled
     * one kept by mistake is cleared with Cancel.
     *
     * @throws ReplayedShipmentException
     */
    public function refuseReplayedCancelled(int $orderId, string $reference, int $storeId, string $replay = ''): void
    {
        if ($reference === '') {
            return;
        }

        $cancelled = $this->isCancelledLocally($orderId, $reference);

        if (!$cancelled) {
            try {
                $status = $this->shipmentClient->get($reference, $storeId)->getStatus();
                $code = is_object($status) && method_exists($status, 'getCode') ? (string) $status->getCode() : '';
                $cancelled = $code === self::API_STATUS_CANCELLED;
            } catch (\Throwable $e) {
                $this->logger->warning(
                    '[PigeonExpress] Create of ' . $reference . ' was a replay (' . $replay
                    . '); read-back failed, kept: ' . $e->getMessage()
                );

                return;
            }
        }

        if (!$cancelled) {
            if ($this->config->isLoggingEnabled($storeId)) {
                $this->logger->info('[PigeonExpress] Create of ' . $reference . ' was a replay (' . $replay . '); kept');
            }

            return;
        }

        $this->logger->warning(
            '[PigeonExpress] Create of ' . $reference . ' was a replay (' . $replay
            . ') of a cancelled shipment for order #' . $orderId . '; nothing stored'
        );

        throw new ReplayedShipmentException(__(
            'The same shipment was sent again less than half a minute after the one that was cancelled. '
            . 'Nothing was saved. Wait 30 seconds and create it again.'
        ));
    }

    private function isCancelledLocally(int $orderId, string $reference): bool
    {
        $collection = $this->shipmentCollectionFactory->create();
        $collection->addFieldToFilter('order_id', ['eq' => $orderId]);
        $collection->addFieldToFilter('reference_number', ['eq' => $reference]);
        $collection->addFieldToFilter('status', ['eq' => ShipmentCanceller::STATUS_CANCELLED]);
        $collection->setPageSize(1);

        return (bool) $collection->getFirstItem()->getId();
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
    public function codAmountFor(OrderInterface $order, string $whoPays = ''): float
    {
        return $this->detectCodAmount($order, $whoPays);
    }

    /**
     * @inheritDoc
     */
    public function isCashOnDelivery(OrderInterface $order): bool
    {
        try {
            $codMethods = $this->config->getCodPaymentMethods((int) $order->getStoreId());
            if (empty($codMethods) || !$order->getPayment()) {
                return false;
            }

            return in_array((string) $order->getPayment()->getMethod(), $codMethods, true);
        } catch (\Throwable $e) {
            // Payment not available; treat as non-COD.
            return false;
        }
    }

    /**
     * Build the packages array for the shipment, using the same geometry rules
     * as rating so the waybill matches what the customer was quoted.
     *
     * The admin's own figures from the shipment form win when they gave them.
     *
     * @param array<string, string> $fields Checked shipment form values
     * @return array<int, array{weight:float, length:float, width:float, height:float}>
     */
    private function buildPackages(
        OrderInterface $order,
        int $storeId,
        string $deliveryType = ConfigInterface::DELIVERY_TYPE_ADDRESS,
        array $fields = []
    ): array {
        return $this->packageBuilder->finish(
            $this->rawPackages($order, $storeId, $deliveryType, $fields),
            $this->labelContents->packageDescription($order, $storeId),
            $storeId
        );
    }

    /**
     * The packages before their description and fragile mark: the form's
     * package rows, else the form's one size, else worked out from the order.
     *
     * @param array<string, mixed> $fields
     * @return array<int, array<string, mixed>>
     */
    private function rawPackages(OrderInterface $order, int $storeId, string $deliveryType, array $fields): array
    {
        // The package rows of the shipment form replace the older single
        // weight, count and size fields when they are there.
        if (isset($fields['packages']) && is_array($fields['packages']) && $fields['packages'] !== []) {
            $rows = $this->packageBuilder->fromForm($fields['packages'], $deliveryType, $storeId);
            if ($rows !== null) {
                return $rows;
            }
        }

        $parcel = $this->addressForm->parcel($fields);
        if ($parcel !== null) {
            return $this->packageBuilder->manual(
                $parcel['weight'],
                ['length' => $parcel['length'], 'width' => $parcel['width'], 'height' => $parcel['height']],
                $parcel['parcels'],
                $deliveryType,
                $storeId
            );
        }

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
     * The shipment form's service choices, for the keys it sent. A key the
     * form did not send leaves the setting in charge.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function serviceOverrides(array $fields): array
    {
        $overrides = [];

        if (array_key_exists('declared_value', $fields) && $fields['declared_value'] !== ''
            && $fields['declared_value'] !== null
        ) {
            $overrides[ServiceCodesBuilder::OVERRIDE_DECLARED_VALUE] = filter_var(
                $fields['declared_value'],
                FILTER_VALIDATE_BOOLEAN
            );
            $amount = $fields['declared_value_amount'] ?? null;
            if (is_string($amount)) {
                $amount = str_replace(',', '.', trim($amount));
            }
            if (is_numeric($amount) && (float) $amount > 0.0) {
                $overrides[ServiceCodesBuilder::OVERRIDE_DECLARED_VALUE_AMOUNT] = round((float) $amount, 2);
            }
        }

        $review = (string) ($fields['review'] ?? '');
        if (in_array($review, [
            ConfigInterface::REVIEW_NONE,
            ConfigInterface::REVIEW_VIEW,
            ConfigInterface::REVIEW_VIEW_TEST,
        ], true)) {
            $overrides[ServiceCodesBuilder::OVERRIDE_REVIEW] = $review;
        }

        $receipt = (string) ($fields['return_receipt'] ?? '');
        if (in_array($receipt, [
            ConfigInterface::RECEIPT_NONE,
            ConfigInterface::RECEIPT_ELECTRONIC,
            ConfigInterface::RECEIPT_PAPER,
            ConfigInterface::RECEIPT_BOTH,
        ], true)) {
            $overrides[ServiceCodesBuilder::OVERRIDE_RETURN_RECEIPT] = $receipt;
        }

        return $overrides;
    }

    /**
     * What happens to a shipment that cannot be delivered, from the Returns
     * settings. Nothing when the account decides, which is what every
     * shipment sent before these settings did. Create only.
     *
     * @return array<string, mixed>
     */
    private function returnFields(int $storeId): array
    {
        $out = [];

        if ($this->config->isReturnAtMyExpense($storeId)) {
            $out['return_at_my_expense'] = true;
        }

        $officeId = $this->config->getReturnOfficeId($storeId);
        if ($officeId !== null) {
            $out['return_office_id'] = $officeId;
        }

        return $out;
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
     * Bulgarian numbers follow BulgarianPhone (the WooCommerce rules), so a
     * landline such as 021234567 becomes +35921234567, not +359021234567.
     * A number given with another country code is kept as typed.
     */
    public static function normalizePhone(string $raw): string
    {
        // Strip spaces, dashes, parentheses and dots, keeping digits and a leading +
        $clean = (string) preg_replace('/[\s\-\(\)\.]/', '', trim($raw));
        if ($clean === '') {
            return '';
        }

        if (strpos($clean, '+') === 0 && strpos($clean, '+359') !== 0) {
            return $clean;
        }

        $normalized = BulgarianPhone::normalize($clean);
        if ($normalized === null) {
            return $clean;
        }

        return strpos($normalized, '359') === 0 ? '+' . $normalized : $normalized;
    }

    /**
     * Value of the goods for insurance purposes.
     *
     * What the customer paid less the delivery, as in WooCommerce
     * (BodyBuilder::goods_total): tax and discounts on the goods are
     * honoured, and shipping (which holds the COD fee) is not insured.
     */
    private function detectGoodsValue(OrderInterface $order): float
    {
        return self::goodsValue($order);
    }

    /**
     * See detectGoodsValue(). Public so the order panel shows the same figure.
     */
    public static function goodsValue(OrderInterface $order): float
    {
        $goods = (float) $order->getGrandTotal()
            - ((float) $order->getShippingInclTax() - abs((float) $order->getShippingDiscountAmount()));

        return $goods > 0.0 ? round($goods, 2) : 0.0;
    }

    /**
     * What the courier collects on a cash on delivery order, 0 otherwise.
     *
     * The order total the customer saw at checkout, so discounts and coupons
     * are honoured. When the recipient pays the carrier for the delivery, the
     * courier charges that on top by itself, so the shipping the shop charged
     * (which already includes the COD fee) comes off; otherwise the customer
     * would pay for the delivery twice.
     */
    private function detectCodAmount(OrderInterface $order, string $whoPays = ''): float
    {
        if (!$this->isCashOnDelivery($order)) {
            return 0.0;
        }

        if ($whoPays === '') {
            $whoPays = $this->addressForm->whoPays(
                [],
                (int) $order->getStoreId(),
                $this->freeShipping->wasAppliedToOrder($order)
            );
        }

        $amount = (float) $order->getGrandTotal();
        if ($whoPays === ConfigInterface::WHO_PAYS_RECEIVER) {
            // Net of any discount on the shipping itself, which grand total
            // has already taken off.
            $amount -= (float) $order->getShippingInclTax() - abs((float) $order->getShippingDiscountAmount());
        }

        return max(0.0, round($amount, 2));
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
