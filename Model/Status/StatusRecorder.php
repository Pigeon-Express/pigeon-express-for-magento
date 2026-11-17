<?php
/**
 * The one way a status read from Pigeon Express reaches a shipment row.
 *
 * Copied from the WooCommerce plugin (ShipmentStatus::record()). Idempotent,
 * so the background check, the "Refresh" button and the orders grid mass
 * action can all report the same status without the order being changed or
 * commented twice:
 *
 * - A status reported again as it is stored only updates the time of the
 *   check, with a plain column update: no model save, no events.
 * - Anything else is written on a fresh copy of the row, read after taking a
 *   per-order lock, so two requests cannot both act on one status.
 * - Reaching a final status acts once: the order comment and, when the
 *   settings ask for it, the order status (OrderStatusApplier).
 *
 * A shipment Pigeon Express reports cancelled is cancelled here too, so the
 * order can get a new one.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Status;

use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Sales\Api\OrderRepositoryInterface;
use PigeonExpress\Shipping\Model\ResourceModel\Shipment as ShipmentResource;
use PigeonExpress\Shipping\Model\Shipment;
use PigeonExpress\Shipping\Model\Shipment\OrderLock;
use PigeonExpress\Shipping\Model\ShipmentCanceller;
use PigeonExpress\Shipping\Model\ShipmentFactory;
use Psr\Log\LoggerInterface;

class StatusRecorder
{
    public const EVENT_STATUS_CHANGED = 'pigeonexpress_shipment_status_changed';
    public const EVENT_CANCELLED = 'pigeonexpress_shipment_cancelled';

    /** Lock scope, apart from the create/edit one so a status read never blocks an edit for long. */
    private const LOCK_SCOPE = 'status';

    /** @var ShipmentResource */
    private $resource;

    /** @var ShipmentFactory */
    private $shipmentFactory;

    /** @var OrderLock */
    private $lock;

    /** @var EventManager */
    private $events;

    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var OrderStatusApplier */
    private $applier;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        ShipmentResource $resource,
        ShipmentFactory $shipmentFactory,
        OrderLock $lock,
        EventManager $events,
        OrderRepositoryInterface $orderRepository,
        OrderStatusApplier $applier,
        LoggerInterface $logger
    ) {
        $this->resource = $resource;
        $this->shipmentFactory = $shipmentFactory;
        $this->lock = $lock;
        $this->events = $events;
        $this->orderRepository = $orderRepository;
        $this->applier = $applier;
        $this->logger = $logger;
    }

    /**
     * Store a status read from the carrier for this shipment row.
     *
     * @param string $at The carrier's time, as it wrote it (ISO 8601), or ''
     * @return bool Whether the status code changed
     */
    public function record(Shipment $row, string $code, string $carrierName = '', string $at = ''): bool
    {
        $code = StatusCodes::clean($code);
        $reference = (string) $row->getReferenceNumber();
        if ($code === '' || $reference === '' || !$row->getId()) {
            return false;
        }

        $name = trim(strip_tags($carrierName));
        $at = $this->toUtc($at);

        if (!$this->differs($row, $code, $name, $at)) {
            $this->touch($row);

            return false;
        }

        $orderId = $row->getOrderId();
        if (!$this->lock->acquire($orderId, self::LOCK_SCOPE)) {
            return false;
        }

        try {
            $fresh = $this->shipmentFactory->create();
            $this->resource->load($fresh, (int) $row->getId());

            // Cancelled or replaced since the status was read.
            if (!$fresh->getId() || (string) $fresh->getReferenceNumber() !== $reference
                || $fresh->getStatus() === ShipmentCanceller::STATUS_CANCELLED
            ) {
                return false;
            }

            if (!$this->differs($fresh, $code, $name, $at)) {
                $this->touch($fresh);

                return false;
            }

            $old = $fresh->getStatusCode();
            $newCode = $old !== $code;

            $fresh->setData('status_code', $code);
            if ($newCode || $name !== '') {
                $fresh->setData('status_name', $name !== '' ? $name : null);
            }
            if ($newCode || $at !== '') {
                $fresh->setData('status_at', $at !== '' ? $at : null);
            }
            $fresh->setData('status_checked_at', gmdate('Y-m-d H:i:s'));

            $cancelledThere = $newCode && $code === StatusCodes::CANCELLED;
            if ($cancelledThere) {
                $fresh->setStatus(ShipmentCanceller::STATUS_CANCELLED);
            }

            $this->resource->save($fresh);
            $row->addData($fresh->getData());
        } finally {
            $this->lock->release($orderId, self::LOCK_SCOPE);
        }

        if ($newCode) {
            $this->dispatch($fresh, $old !== '' ? $old : null, $code, $cancelledThere);

            if (StatusCodes::isFinal($code)) {
                $this->applier->apply($orderId, $reference, $code, $name, $at);
            }
        }

        return $newCode;
    }

    /**
     * Whether recording this status would change anything besides the time
     * of the check. The same status reported again without its name or time
     * keeps the ones already stored.
     */
    public function differs(Shipment $row, string $code, string $name, string $at): bool
    {
        if ($row->getStatusCode() !== $code) {
            return true;
        }
        if ($name !== '' && $row->getStatusName() !== $name) {
            return true;
        }

        return $at !== '' && (string) $row->getStatusAt() !== $at;
    }

    /**
     * A carrier time ("2026-11-11T11:32:02+02:00") as UTC "Y-m-d H:i:s", ''
     * when there is none.
     */
    public function toUtc(string $at): string
    {
        $at = trim($at);
        if ($at === '') {
            return '';
        }
        $timestamp = strtotime($at);

        return $timestamp !== false ? gmdate('Y-m-d H:i:s', $timestamp) : '';
    }

    private function touch(Shipment $row): void
    {
        $now = gmdate('Y-m-d H:i:s');
        try {
            $this->resource->getConnection()->update(
                $this->resource->getMainTable(),
                ['status_checked_at' => $now],
                ['entity_id = ?' => (int) $row->getId()]
            );
            $row->setData('status_checked_at', $now);
        } catch (\Throwable $e) {
            $this->logger->warning('[PigeonExpress] Status check time not saved: ' . $e->getMessage());
        }
    }

    private function dispatch(Shipment $row, ?string $old, string $new, bool $cancelledThere): void
    {
        try {
            $order = $this->orderRepository->get($row->getOrderId());
        } catch (\Throwable $e) {
            return;
        }

        $data = [
            'order'        => $order,
            'shipment_row' => $row->getData(),
        ];

        try {
            $this->events->dispatch(self::EVENT_STATUS_CHANGED, $data + [
                'old_code'     => $old,
                'new_code'     => $new,
                'is_delivered' => StatusCodes::isDelivered($new),
                'is_returned'  => StatusCodes::isReturned($new),
            ]);
            if ($cancelledThere) {
                $this->events->dispatch(self::EVENT_CANCELLED, $data);
            }
        } catch (\Throwable $e) {
            $this->logger->error('[PigeonExpress] Status event observer failed: ' . $e->getMessage());
        }
    }
}
