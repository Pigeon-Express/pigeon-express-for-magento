<?php
/**
 * Change the shipment of an order at Pigeon Express, keeping its waybill
 * number (PUT /shipments/{ref}).
 *
 * As in the WooCommerce plugin (LabelService::update_shipment_for_order()):
 *
 * - Taken under the same per-order lock as a create, so a change cannot race
 *   a create or a second click.
 * - Allowed only while the shipment is newly registered, as far as the
 *   module knows. A status not read yet is no reason to refuse: the carrier
 *   says so itself, and the status is then read so the panel shows why.
 * - The body is built exactly as for create, from the order and the form,
 *   because the carrier clears whatever a change does not carry. Only the
 *   keys the carrier ignores on a change are left out.
 * - A change answered with the carrier's replay of an identical change sent
 *   less than 30 seconds earlier is not recorded: the shipment is read again
 *   and the merchant is asked to save again in 30 seconds
 *   (refuseReplayedChange(), from ApiClient::refuse_replayed_change).
 * - After it: the new payload and form are stored, the details are kept from
 *   the carrier's answer, and the order gets a comment saying the waybill
 *   number stays and the label must be printed again.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PigeonExpress\Shipping\Api\ShipmentClientInterface;
use PigeonExpress\Shipping\Exception\NotEditableException;
use PigeonExpress\Shipping\Exception\ReplayedShipmentException;
use PigeonExpress\Shipping\Model\Api\ShipmentClient;
use PigeonExpress\Shipping\Model\ResourceModel\Shipment as ShipmentResource;
use PigeonExpress\Shipping\Model\Shipment\HandInResolver;
use PigeonExpress\Shipping\Model\Shipment\OrderLock;
use PigeonExpress\Shipping\Model\Status\ShipmentDetailsStore;
use PigeonExpress\Shipping\Model\Status\StatusCodes;
use PigeonExpress\Shipping\Model\Status\StatusRefresher;
use Psr\Log\LoggerInterface;

class ShipmentEditor
{
    /**
     * Keys of the create body that the carrier ignores on a change: the
     * pickup end, the order number and the label settings stay as created.
     * Left out so the request says only what it changes; everything else is
     * sent, because a change clears whatever it does not carry.
     */
    public const UPDATE_IGNORED_KEYS = [
        'pickup_type',
        'pickup_office_id',
        'pickup_address',
        'sender_address',
        'sender_address_id',
        'external_reference',
        'label_format',
        'hide_content_on_label',
    ];

    /** @var ShipmentSender */
    private $sender;

    /** @var ShipmentClientInterface */
    private $client;

    /** @var ShipmentCanceller */
    private $canceller;

    /** @var ShipmentResource */
    private $resource;

    /** @var ShipmentFactory */
    private $shipmentFactory;

    /** @var OrderLock */
    private $lock;

    /** @var ShipmentDetailsStore */
    private $details;

    /** @var StatusRefresher */
    private $refresher;

    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var LoggerInterface */
    private $logger;

    /** @var HandInResolver */
    private $handIn;

    public function __construct(
        ShipmentSender $sender,
        ShipmentClientInterface $client,
        ShipmentCanceller $canceller,
        ShipmentResource $resource,
        ShipmentFactory $shipmentFactory,
        OrderLock $lock,
        ShipmentDetailsStore $details,
        StatusRefresher $refresher,
        OrderRepositoryInterface $orderRepository,
        LoggerInterface $logger,
        HandInResolver $handIn
    ) {
        $this->handIn = $handIn;
        $this->sender = $sender;
        $this->client = $client;
        $this->canceller = $canceller;
        $this->resource = $resource;
        $this->shipmentFactory = $shipmentFactory;
        $this->lock = $lock;
        $this->details = $details;
        $this->refresher = $refresher;
        $this->orderRepository = $orderRepository;
        $this->logger = $logger;
    }

    /**
     * Why the shipment cannot be changed, or '' when it can as far as the
     * module knows.
     */
    public function editBlockReason(?Shipment $shipment): string
    {
        if ($shipment === null || !$shipment->getId() || (string) $shipment->getReferenceNumber() === '') {
            return (string) __('This order has no Pigeon Express shipment.');
        }
        if ($shipment->getStatus() === ShipmentCanceller::STATUS_CANCELLED) {
            return (string) __('This shipment has already been cancelled.');
        }
        if (!StatusCodes::isStillRegistered($shipment->getStatusCode())) {
            return (string) __(
                'It can be changed only until the courier picks it up or it is handed in at an office.'
            );
        }

        return '';
    }

    /**
     * @param array<string, mixed> $fields The checked shipment form
     * @throws LocalizedException
     */
    public function update(OrderInterface $order, array $fields): Shipment
    {
        $orderId = (int) $order->getId();

        if (!$this->lock->acquire($orderId)) {
            throw new LocalizedException(__(
                'The Pigeon Express shipment of this order is being created or changed right now. '
                . 'Reload the order in a moment.'
            ));
        }

        try {
            return $this->updateLocked($order, $fields);
        } finally {
            $this->lock->release($orderId);
        }
    }

    /**
     * @param array<string, mixed> $fields
     * @throws LocalizedException
     */
    private function updateLocked(OrderInterface $order, array $fields): Shipment
    {
        $orderId = (int) $order->getId();
        $storeId = (int) $order->getStoreId();
        $row = $this->canceller->findShipment($orderId);

        $blocked = $this->editBlockReason($row);
        if ($blocked !== '') {
            throw new NotEditableException(__($blocked));
        }
        /** @var Shipment $row */
        $reference = (string) $row->getReferenceNumber();
        $oldPayload = json_decode((string) $row->getPayload(), true);
        $oldPayload = is_array($oldPayload) ? $oldPayload : [];

        $built = $this->sender->payloadFor($order, ['address_fields' => $fields]);
        $payload = $built['payload'];
        foreach (self::UPDATE_IGNORED_KEYS as $key) {
            unset($payload[$key]);
        }

        $this->refuseLockerMisfit($oldPayload, $payload, $storeId);

        try {
            $result = $this->client->update($reference, $payload, $storeId);
        } catch (NotEditableException $e) {
            // Read the status, so the panel shows why and stops offering it.
            try {
                $this->refresher->refreshShipments([$row]);
            } catch (\Throwable $ignored) {
                // The refusal is what the merchant needs to see.
            }
            throw $e;
        }

        // The very same change was sent less than 30 seconds ago and the
        // carrier answered with that earlier result, without acting. The
        // shipment may have changed in between (A, then B, then A again), so
        // the answer cannot be taken for what it is now.
        if ((string) ($result['replay'] ?? '') === ShipmentClient::REPLAY_EARLIER) {
            $this->refuseReplayedChange($row, $storeId);
        }

        $fresh = $this->shipmentFactory->create();
        $this->resource->load($fresh, (int) $row->getId());
        if (!$fresh->getId() || (string) $fresh->getReferenceNumber() !== $reference
            || $fresh->getStatus() === ShipmentCanceller::STATUS_CANCELLED
        ) {
            throw new LocalizedException(__('The shipment on this order changed in the meantime. Reload the order.'));
        }

        // What the waybill now carries: the change, with the pickup end and
        // the other create-only fields as they were created.
        $stored = $payload;
        foreach (self::UPDATE_IGNORED_KEYS as $key) {
            if (array_key_exists($key, $oldPayload)) {
                $stored[$key] = $oldPayload[$key];
            }
        }
        $fresh->setPayload((string) json_encode($stored));
        $fresh->setFormFields((string) json_encode($built['fields']));
        $this->resource->save($fresh);

        $details = $result['details'] ?? null;
        if (is_object($details) && method_exists($details, 'toArray')) {
            $data = $details->toArray();
            if (is_array($data) && $data !== []) {
                $this->details->remember($fresh, $data);
            }
        }

        $this->comment($orderId, $reference, $this->describeChanges($oldPayload, $stored));

        return $fresh;
    }

    /**
     * A shipment handed in at a parcel locker keeps that pickup: a change
     * cannot move it. A change after which it no longer fits a locker is
     * refused before it is sent, as in WooCommerce
     * (PickupPoint::change_problem).
     *
     * @param array<string, mixed> $created What the shipment was created with
     * @param array<string, mixed> $change The body of the change
     * @throws LocalizedException
     */
    public function refuseLockerMisfit(array $created, array $change, int $storeId): void
    {
        if ((string) ($created['pickup_type'] ?? '') !== 'locker') {
            return;
        }

        $packages = is_array($change['packages'] ?? null) ? $change['packages'] : [];
        $services = is_array($change['service_codes'] ?? null) ? $change['service_codes'] : [];
        $decision = $this->handIn->decide($packages, $services, $storeId);
        if ($decision['reasons'] === []) {
            return;
        }

        throw new LocalizedException(__(
            'This shipment is handed in at a parcel locker, and with these changes it no longer fits one (%1). '
            . 'Where a shipment is handed in cannot be changed: cancel it and create a new one.',
            $this->handIn->reasonText($decision['reasons'], $decision['services'], $storeId)
        ));
    }

    /**
     * A change the carrier answered with its replay of an identical earlier
     * one: nothing of it is recorded (not the form, the payload or a
     * comment), the shipment is read again so the details on the order are
     * what Pigeon Express has now, and the merchant is asked to save again
     * once the 30 seconds have passed, when the carrier acts on it.
     *
     * @throws ReplayedShipmentException Always
     */
    public function refuseReplayedChange(Shipment $row, int $storeId): void
    {
        $this->logger->warning(
            '[PigeonExpress] Update of ' . $row->getReferenceNumber() . ' is a replay of an earlier identical change; '
            . 'not recorded'
        );

        try {
            $fresh = $this->shipmentFactory->create();
            $this->resource->load($fresh, (int) $row->getId());
            if ($fresh->getId() && (string) $fresh->getReferenceNumber() === (string) $row->getReferenceNumber()
                && $fresh->getStatus() !== ShipmentCanceller::STATUS_CANCELLED
            ) {
                $this->details->fetch($fresh, $storeId);
            }
        } catch (\Throwable $e) {
            $this->logger->info('[PigeonExpress] Read-back after a replayed change failed: ' . $e->getMessage());
        }

        throw new ReplayedShipmentException(__(
            'Pigeon Express did not make this change: the same change was sent less than half a minute ago. '
            . 'Wait 30 seconds and save again.'
        ));
    }

    /**
     * What changed, in words, for the order comment.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return string[]
     */
    public function describeChanges(array $before, array $after): array
    {
        $lines = [];
        $simple = [
            'receiver_name'  => __('Receiver name'),
            'receiver_phone' => __('Receiver phone'),
            'delivery_type'  => __('Delivery type'),
            'note'           => __('Note for the courier'),
        ];
        foreach ($simple as $key => $label) {
            $old = is_scalar($before[$key] ?? null) ? (string) $before[$key] : '';
            $new = is_scalar($after[$key] ?? null) ? (string) $after[$key] : '';
            if ($old !== $new) {
                $lines[] = (string) __('%1: %2 to %3', $label, $old !== '' ? $old : '-', $new !== '' ? $new : '-');
            }
        }

        $weight = static function (array $payload): float {
            $total = 0.0;
            foreach ((array) ($payload['packages'] ?? []) as $package) {
                if (is_array($package)) {
                    $total += (float) ($package['weight'] ?? 0);
                }
            }

            return round($total, 3);
        };
        if ($weight($before) !== $weight($after)) {
            $lines[] = (string) __('Weight: %1 kg to %2 kg', $weight($before), $weight($after));
        }
        if (count((array) ($before['packages'] ?? [])) !== count((array) ($after['packages'] ?? []))) {
            $lines[] = (string) __(
                'Packages: %1 to %2',
                count((array) ($before['packages'] ?? [])),
                count((array) ($after['packages'] ?? []))
            );
        }

        $codBefore = (float) (($before['service_codes'] ?? [])['cod_amount'] ?? 0);
        $codAfter = (float) (($after['service_codes'] ?? [])['cod_amount'] ?? 0);
        if (round($codBefore, 2) !== round($codAfter, 2)) {
            $lines[] = (string) __('Cash on delivery: %1 to %2', number_format($codBefore, 2, '.', ''), number_format($codAfter, 2, '.', ''));
        }

        if ((($before['delivery_address'] ?? null) != ($after['delivery_address'] ?? null))
            || (($before['delivery_office_id'] ?? null) != ($after['delivery_office_id'] ?? null))
        ) {
            $lines[] = (string) __('Destination changed.');
        }

        if ((($before['service_codes'] ?? null) != ($after['service_codes'] ?? null)) && $codBefore === $codAfter) {
            $lines[] = (string) __('Additional services changed.');
        }

        return $lines;
    }

    /**
     * @param string[] $lines
     */
    private function comment(int $orderId, string $reference, array $lines): void
    {
        $lines[] = (string) __('Print the label again: the one printed before no longer matches the shipment.');
        $text = (string) __(
            'Pigeon Express shipment %1 was changed. The waybill number stays the same.',
            $reference
        ) . "\n" . implode("\n", $lines);

        try {
            $order = $this->orderRepository->get($orderId);
            $order->addCommentToStatusHistory(nl2br(htmlspecialchars($text, ENT_QUOTES)), false);
            $this->orderRepository->save($order);
        } catch (\Throwable $e) {
            $this->logger->warning('[PigeonExpress] Change comment not added to order #' . $orderId . ': '
                . $e->getMessage());
        }
    }
}
