<?php
/**
 * The native Magento shipment that goes with a Pigeon Express waybill.
 *
 * When a waybill is created, the order's items are shipped in Magento too,
 * with the waybill number as the tracking number (carrier "pigeonexpress").
 * That is what makes Magento show the order as shipped, put the number in its
 * own shipment email, and offer "Track your order" to the customer.
 *
 * Magento cannot delete a shipment. When the waybill is cancelled only its
 * tracking number is removed; the shipment stays, and the next waybill of the
 * order is added to it rather than to a second shipment Magento would refuse
 * anyway (its items already count as shipped).
 *
 * Never throws: the waybill exists whatever happens here, and the merchant is
 * told in an order comment what was and was not done.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Magento;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\ShipmentTrackCreationInterfaceFactory;
use Magento\Sales\Api\Data\ShipmentTrackInterfaceFactory;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Api\ShipmentTrackRepositoryInterface;
use Magento\Sales\Api\ShipOrderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\Shipment\Track\CollectionFactory as TrackCollectionFactory;
use PigeonExpress\Shipping\Api\ConfigInterface;
use Psr\Log\LoggerInterface;

class NativeShipment
{
    public const TRACK_TITLE = 'Pigeon Express';

    /** What createFor() did. */
    public const RESULT_DISABLED = 'disabled';
    public const RESULT_NO_NUMBER = 'no_number';
    public const RESULT_ALREADY_LINKED = 'already_linked';
    public const RESULT_CREATED = 'created';
    public const RESULT_REUSED = 'reused';
    public const RESULT_SKIPPED = 'skipped';
    public const RESULT_FAILED = 'failed';

    /** Why an order cannot be shipped in Magento. */
    public const REASON_VIRTUAL = 'virtual';
    public const REASON_SHIPPED = 'shipped';
    public const REASON_CANNOT_SHIP = 'cannot_ship';

    /** @var Settings */
    private $settings;

    /** @var ShipOrderInterface */
    private $shipOrder;

    /** @var ShipmentTrackCreationInterfaceFactory */
    private $trackCreationFactory;

    /** @var ShipmentTrackInterfaceFactory */
    private $trackFactory;

    /** @var ShipmentTrackRepositoryInterface */
    private $trackRepository;

    /** @var ShipmentRepositoryInterface */
    private $shipmentRepository;

    /** @var TrackCollectionFactory */
    private $trackCollectionFactory;

    /** @var ShipmentLink */
    private $link;

    /** @var OrderComment */
    private $comment;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        Settings $settings,
        ShipOrderInterface $shipOrder,
        ShipmentTrackCreationInterfaceFactory $trackCreationFactory,
        ShipmentTrackInterfaceFactory $trackFactory,
        ShipmentTrackRepositoryInterface $trackRepository,
        ShipmentRepositoryInterface $shipmentRepository,
        TrackCollectionFactory $trackCollectionFactory,
        ShipmentLink $link,
        OrderComment $comment,
        LoggerInterface $logger
    ) {
        $this->settings = $settings;
        $this->shipOrder = $shipOrder;
        $this->trackCreationFactory = $trackCreationFactory;
        $this->trackFactory = $trackFactory;
        $this->trackRepository = $trackRepository;
        $this->shipmentRepository = $shipmentRepository;
        $this->trackCollectionFactory = $trackCollectionFactory;
        $this->link = $link;
        $this->comment = $comment;
        $this->logger = $logger;
    }

    /**
     * Ship the order in Magento for a waybill just created.
     *
     * @param array<string, mixed> $row The pigeonexpress_shipment row
     * @return string One of the RESULT_* values
     */
    public function createFor(OrderInterface $order, array $row): string
    {
        if (!$this->settings->isCreateMagentoShipment((int) $order->getStoreId())) {
            return self::RESULT_DISABLED;
        }

        return $this->ship($order, $row, false)['result'];
    }

    /**
     * Ship the order in Magento once its waybill is delivered, for "Complete
     * the order". Done whatever "Create the Magento shipment" says, without
     * the shipment email (the parcel has already arrived) and without an
     * order comment: the caller writes the one note about the delivery.
     *
     * @param array<string, mixed> $row The pigeonexpress_shipment row
     * @return array{result: string, shipment: string, error: string} The
     *         RESULT_* value, the Magento shipment number when there is one,
     *         and the error Magento reported, if any.
     */
    public function shipOnDelivery(OrderInterface $order, array $row): array
    {
        return $this->ship($order, $row, true);
    }

    /**
     * @param array<string, mixed> $row
     * @param bool $quiet No email and no comment (shipOnDelivery)
     * @return array{result: string, shipment: string, error: string}
     */
    private function ship(OrderInterface $order, array $row, bool $quiet): array
    {
        $storeId = (int) $order->getStoreId();
        $number = trim((string) ($row['reference_number'] ?? ''));
        $rowId = (int) ($row['entity_id'] ?? 0);

        if ($number === '') {
            return $this->outcome(self::RESULT_NO_NUMBER);
        }

        // Never a second Magento shipment for the same waybill, however often
        // the event is dispatched.
        if (!empty($row['magento_shipment_id']) || $this->link->linkedTo($rowId) !== null) {
            return $this->outcome(self::RESULT_ALREADY_LINKED);
        }

        $orderId = (int) $order->getEntityId();
        $existing = $this->findTracks($orderId, $number);
        if ($existing !== []) {
            $this->link->link($rowId, (int) $existing[0]->getParentId());

            return $this->outcome(self::RESULT_ALREADY_LINKED);
        }

        try {
            $reason = $this->skipReason($order);

            if ($reason === null) {
                $shipmentId = (int) $this->shipOrder->execute(
                    $orderId,
                    [],
                    !$quiet && $this->settings->isShipmentEmailEnabled($storeId),
                    false,
                    null,
                    [$this->newTrackCreation($number)]
                );
                $this->link->link($rowId, $shipmentId);
                $shipment = $this->shipmentNumber($shipmentId);
                if (!$quiet) {
                    $this->comment->add($order, (string) __(
                        'Pigeon Express waybill %1: Magento shipment #%2 was created with this tracking number.',
                        $number,
                        $shipment
                    ));
                }

                return $this->outcome(self::RESULT_CREATED, $shipment);
            }

            // The items were shipped with an earlier waybill of this order,
            // since cancelled: the shipment is still there, so the new number
            // goes into it.
            $previous = $reason === self::REASON_SHIPPED ? $this->previousShipment($order, $rowId) : null;
            if ($previous !== null) {
                $this->addTrack($previous, $orderId, $number);
                $this->link->link($rowId, $previous);
                $shipment = $this->shipmentNumber($previous);
                if (!$quiet) {
                    $this->comment->add($order, (string) __(
                        'Pigeon Express waybill %1: this tracking number was added to Magento shipment #%2, '
                        . 'which holds the items since an earlier waybill.',
                        $number,
                        $shipment
                    ));
                }

                return $this->outcome(self::RESULT_REUSED, $shipment);
            }

            if (!$quiet) {
                $this->comment->add($order, (string) __(
                    'Pigeon Express waybill %1: no Magento shipment was created, because %2',
                    $number,
                    $this->reasonText($reason)
                ));
            }

            return $this->outcome(self::RESULT_SKIPPED, '', $this->reasonText($reason));
        } catch (\Throwable $e) {
            $this->logger->error('[PigeonExpress] Magento shipment not created: ' . $e->getMessage(), [
                'order_id' => $orderId,
                'reference_number' => $number,
            ]);

            // Magento saves the shipment before it sends the shipment email, so
            // a failure there (a mail server, a search engine that is down)
            // comes after the shipment exists. Keep the link, say what failed.
            $saved = $this->safeFindTracks($orderId, $number);
            if ($saved !== []) {
                $shipmentId = (int) $saved[0]->getParentId();
                $this->link->link($rowId, $shipmentId);
                $shipment = $this->shipmentNumber($shipmentId);
                if (!$quiet) {
                    $this->comment->add($order, (string) __(
                        'Pigeon Express waybill %1: Magento shipment #%2 was created with this tracking number, '
                        . 'but Magento then reported an error: %3',
                        $number,
                        $shipment,
                        $e->getMessage()
                    ));
                }

                return $this->outcome(self::RESULT_CREATED, $shipment, $e->getMessage());
            }

            if (!$quiet) {
                $this->comment->add($order, (string) __(
                    'Pigeon Express waybill %1: the Magento shipment could not be created: %2',
                    $number,
                    $e->getMessage()
                ));
            }

            return $this->outcome(self::RESULT_FAILED, '', $e->getMessage());
        }
    }

    /**
     * @return array{result: string, shipment: string, error: string}
     */
    private function outcome(string $result, string $shipment = '', string $error = ''): array
    {
        return ['result' => $result, 'shipment' => $shipment, 'error' => $error];
    }

    /**
     * Take the tracking number of a cancelled waybill off its Magento shipment.
     *
     * Done whatever the setting says: a number that no longer exists at the
     * carrier must not be shown to the customer.
     *
     * @param array<string, mixed> $row
     * @return int How many tracking numbers were removed
     */
    public function removeTrackFor(OrderInterface $order, array $row): int
    {
        $number = trim((string) ($row['reference_number'] ?? ''));
        $orderId = (int) $order->getEntityId();
        if ($number === '' || $orderId <= 0) {
            return 0;
        }

        $removed = 0;
        $shipments = [];
        try {
            foreach ($this->findTracks($orderId, $number) as $track) {
                $shipments[] = $this->shipmentNumber((int) $track->getParentId());
                $this->trackRepository->delete($track);
                $removed++;
            }
        } catch (\Throwable $e) {
            $this->logger->error('[PigeonExpress] Tracking number not removed: ' . $e->getMessage(), [
                'order_id' => $orderId,
                'reference_number' => $number,
            ]);
            $this->comment->add($order, (string) __(
                'Pigeon Express waybill %1 was cancelled, but its tracking number could not be removed '
                . 'from the Magento shipment: %2. Remove it by hand on the shipment page.',
                $number,
                $e->getMessage()
            ));

            return $removed;
        }

        if ($removed > 0) {
            $this->comment->add($order, (string) __(
                'Pigeon Express waybill %1 was cancelled, so its tracking number was removed from Magento '
                . 'shipment #%2. Magento cannot delete a shipment: it stays on the order and its items '
                . 'still count as shipped. A new waybill for this order is added to the same shipment.',
                $number,
                implode(', #', array_unique($shipments))
            ));
        }

        return $removed;
    }

    /**
     * Put the new number of a redirected waybill in place of the old one on
     * its Magento shipment, and link the new row to that shipment.
     *
     * Done whatever "Create the Magento shipment" says, like removeTrackFor():
     * the old number no longer follows the parcel, and the customer's "Track
     * your order" must show the one that does. When the order has no Magento
     * shipment with the old number, nothing is added: there was none to keep
     * up to date.
     *
     * @param array<string, mixed> $oldRow The redirected pigeonexpress_shipment row
     * @param array<string, mixed> $newRow The row of the shipment that replaced it
     * @return int How many tracking numbers were replaced
     */
    public function replaceTrackFor(OrderInterface $order, array $oldRow, array $newRow): int
    {
        $old = trim((string) ($oldRow['reference_number'] ?? ''));
        $new = trim((string) ($newRow['reference_number'] ?? ''));
        $newRowId = (int) ($newRow['entity_id'] ?? 0);
        $orderId = (int) $order->getEntityId();
        if ($old === '' || $new === '' || $old === $new || $orderId <= 0) {
            return 0;
        }

        $replaced = 0;
        $shipments = [];
        try {
            // Already done (the event dispatched twice): only the link is kept.
            $done = $this->findTracks($orderId, $new);
            foreach ($this->findTracks($orderId, $old) as $track) {
                $shipmentId = (int) $track->getParentId();
                if ($done !== []) {
                    $this->trackRepository->delete($track);
                } else {
                    $track->setTrackNumber($new);
                    $this->trackRepository->save($track);
                    $done[] = $track;
                }
                $shipments[] = $this->shipmentNumber($shipmentId);
                $replaced++;
            }
            if ($done !== []) {
                $this->link->link($newRowId, (int) $done[0]->getParentId());
            }
        } catch (\Throwable $e) {
            $this->logger->error('[PigeonExpress] Tracking number not replaced: ' . $e->getMessage(), [
                'order_id' => $orderId,
                'reference_number' => $old,
                'new_reference_number' => $new,
            ]);
            $this->comment->add($order, (string) __(
                'Pigeon Express waybill %1 was redirected to %2, but the tracking number on the Magento shipment '
                . 'could not be replaced: %3. Change it by hand on the shipment page.',
                $old,
                $new,
                $e->getMessage()
            ));

            return $replaced;
        }

        if ($replaced > 0) {
            $this->comment->add($order, (string) __(
                'Pigeon Express waybill %1 was redirected to %2, so the tracking number on Magento shipment #%3 '
                . 'was replaced with the new one.',
                $old,
                $new,
                implode(', #', array_unique($shipments))
            ));
        }

        return $replaced;
    }

    /**
     * Why Magento would refuse to ship the order, or null when it can.
     */
    public function skipReason(OrderInterface $order): ?string
    {
        if ($order->getIsVirtual()) {
            return self::REASON_VIRTUAL;
        }

        if ($order instanceof Order && $order->canShip()) {
            return null;
        }

        if ($order instanceof Order && $order->hasShipments()) {
            return self::REASON_SHIPPED;
        }

        return self::REASON_CANNOT_SHIP;
    }

    private function reasonText(string $reason): string
    {
        switch ($reason) {
            case self::REASON_VIRTUAL:
                return (string) __('the order has nothing to ship.');
            case self::REASON_SHIPPED:
                return (string) __('all its items are already shipped.');
        }

        return (string) __('Magento does not allow shipping the order in its current state.');
    }

    /**
     * The newest Magento shipment an earlier waybill of the order was put in,
     * if it still belongs to the order.
     */
    private function previousShipment(OrderInterface $order, int $rowId): ?int
    {
        foreach ($this->link->linkedForOrder((int) $order->getEntityId(), $rowId) as $shipmentId) {
            try {
                $shipment = $this->shipmentRepository->get($shipmentId);
                if ((int) $shipment->getOrderId() === (int) $order->getEntityId()) {
                    return $shipmentId;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return null;
    }

    private function addTrack(int $shipmentId, int $orderId, string $number): void
    {
        $track = $this->trackFactory->create();
        $track->setParentId($shipmentId);
        $track->setOrderId($orderId);
        $track->setTrackNumber($number);
        $track->setCarrierCode(ConfigInterface::CARRIER_CODE);
        $track->setTitle(self::TRACK_TITLE);
        $this->trackRepository->save($track);
    }

    private function newTrackCreation(string $number): \Magento\Sales\Api\Data\ShipmentTrackCreationInterface
    {
        $track = $this->trackCreationFactory->create();
        $track->setTrackNumber($number);
        $track->setCarrierCode(ConfigInterface::CARRIER_CODE);
        $track->setTitle(self::TRACK_TITLE);

        return $track;
    }

    /**
     * Pigeon Express tracks of the order carrying this number.
     *
     * @return \Magento\Sales\Model\Order\Shipment\Track[]
     */
    private function findTracks(int $orderId, string $number): array
    {
        $collection = $this->trackCollectionFactory->create();
        $collection->addFieldToFilter('order_id', $orderId);
        $collection->addFieldToFilter('carrier_code', ConfigInterface::CARRIER_CODE);
        $collection->addFieldToFilter('track_number', $number);

        return array_values($collection->getItems());
    }

    /**
     * findTracks() for an error path, where a second failure must not escape.
     *
     * @return \Magento\Sales\Model\Order\Shipment\Track[]
     */
    private function safeFindTracks(int $orderId, string $number): array
    {
        try {
            return $this->findTracks($orderId, $number);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * The shipment's number as Magento shows it, its id when it cannot be read.
     */
    private function shipmentNumber(int $shipmentId): string
    {
        try {
            $increment = (string) $this->shipmentRepository->get($shipmentId)->getIncrementId();

            return $increment !== '' ? $increment : (string) $shipmentId;
        } catch (\Throwable $e) {
            return (string) $shipmentId;
        }
    }
}
