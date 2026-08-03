<?php
/**
 * Cancel a Pigeon Express shipment and record the outcome locally.
 *
 * The API only allows cancelling while a shipment is still registered, so this
 * checks locally first to give a clear message instead of a raw API rejection.
 * The local row is kept rather than deleted: a cancelled waybill is part of the
 * order's history, and deleting it would let the same order be sent twice with
 * no trace of the first attempt.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model;

use Magento\Framework\Exception\LocalizedException;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Api\ShipmentClientInterface;
use PigeonExpress\Shipping\Exception\ApiException;
use PigeonExpress\Shipping\Model\ResourceModel\Shipment as ShipmentResource;
use PigeonExpress\Shipping\Model\ResourceModel\Shipment\CollectionFactory as ShipmentCollectionFactory;
use Psr\Log\LoggerInterface;

class ShipmentCanceller
{
    /** Status the API reports for a shipment that has not been collected yet. */
    public const STATUS_REGISTERED = 'shipment_registered';

    /** Status written locally once Pigeon Express confirms the cancellation. */
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Statuses we accept as still cancellable.
     *
     * `sent` is the module's own default for a freshly created shipment, kept
     * for rows written before the API status was recorded.
     */
    private const CANCELLABLE_STATUSES = [self::STATUS_REGISTERED, 'sent'];

    /** @var ShipmentClientInterface */
    private $shipmentClient;

    /** @var ShipmentCollectionFactory */
    private $shipmentCollectionFactory;

    /** @var ShipmentResource */
    private $shipmentResource;

    /** @var ConfigInterface */
    private $config;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        ShipmentClientInterface $shipmentClient,
        ShipmentCollectionFactory $shipmentCollectionFactory,
        ShipmentResource $shipmentResource,
        ConfigInterface $config,
        LoggerInterface $logger
    ) {
        $this->shipmentClient = $shipmentClient;
        $this->shipmentCollectionFactory = $shipmentCollectionFactory;
        $this->shipmentResource = $shipmentResource;
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Cancel the shipment attached to an order.
     *
     * @throws LocalizedException When there is nothing to cancel, or it is too late
     * @throws ApiException When Pigeon Express refuses
     */
    public function cancelForOrder(int $orderId, int $storeId): Shipment
    {
        $shipment = $this->findShipment($orderId);

        if ($shipment === null) {
            throw new LocalizedException(__('This order has no Pigeon Express shipment to cancel.'));
        }

        if ($shipment->getStatus() === self::STATUS_CANCELLED) {
            throw new LocalizedException(__('This shipment has already been cancelled.'));
        }

        if (!$this->isCancellable($shipment)) {
            throw new LocalizedException(__(
                'Pigeon Express can only cancel a shipment before the courier collects it. '
                . 'This one is now "%1", so it has to be handled with Pigeon Express directly.',
                (string) $shipment->getStatus()
            ));
        }

        $reference = (string) $shipment->getReferenceNumber();
        if ($reference === '') {
            throw new LocalizedException(__('This shipment has no reference number, so it cannot be cancelled.'));
        }

        $this->shipmentClient->cancel($reference, $storeId);

        $shipment->setStatus(self::STATUS_CANCELLED);
        $this->shipmentResource->save($shipment);

        if ($this->config->isLoggingEnabled($storeId)) {
            $this->logger->info('[PigeonExpress] Shipment cancelled', [
                'order_id' => $orderId,
                'reference_number' => $reference,
            ]);
        }

        return $shipment;
    }

    /**
     * Whether a shipment is still early enough in its life to be cancelled.
     */
    public function isCancellable(Shipment $shipment): bool
    {
        return in_array((string) $shipment->getStatus(), self::CANCELLABLE_STATUSES, true);
    }

    /**
     * Most recent shipment for an order, or null when none was ever created.
     */
    public function findShipment(int $orderId): ?Shipment
    {
        $collection = $this->shipmentCollectionFactory->create();
        $collection->addFieldToFilter('order_id', $orderId);
        $collection->setOrder('entity_id', 'DESC');
        $collection->setPageSize(1);

        /** @var Shipment $shipment */
        $shipment = $collection->getFirstItem();

        return $shipment->getId() ? $shipment : null;
    }
}
