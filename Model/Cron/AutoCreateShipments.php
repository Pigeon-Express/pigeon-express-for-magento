<?php
/**
 * Create the shipments of orders queued when they reached Processing.
 *
 * As WooCommerce does it (Labels\AutoCreate): with what the order says,
 * through the same ShipmentSender::send() as the Create button, so its
 * per order lock and "already created" check apply. Every outcome the
 * merchant needs to act on becomes an order comment. A request that may
 * have reached the carrier is never repeated: a second attempt could ship
 * the order twice.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Cron;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Status\HistoryFactory;
use PigeonExpress\Exceptions\ConnectionException as SdkConnectionException;
use PigeonExpress\Exceptions\RateLimitException as SdkRateLimitException;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Api\ShipmentSenderInterface;
use PigeonExpress\Shipping\Exception\OrderLockedException;
use PigeonExpress\Shipping\Exception\OutcomeUnknownException;
use PigeonExpress\Shipping\Model\AutoCreate\Queue;
use PigeonExpress\Shipping\Model\ResourceModel\Shipment\CollectionFactory as ShipmentCollectionFactory;
use PigeonExpress\Shipping\Model\ShipmentCanceller;
use Psr\Log\LoggerInterface;

class AutoCreateShipments
{
    /** Waits before the second and third attempt, as in WooCommerce. */
    private const RETRY_DELAYS = [300, 1800];

    /** Seconds before an order another request held is tried again. */
    private const LOCKED_RETRY_DELAY = 60;

    /** Orders taken per cron run. */
    private const BATCH = 20;

    /** @var Queue */
    private $queue;

    /** @var OrderRepositoryInterface */
    private $orders;

    /** @var ShipmentSenderInterface */
    private $sender;

    /** @var ConfigInterface */
    private $config;

    /** @var HistoryFactory */
    private $historyFactory;

    /** @var OrderStatusHistoryRepositoryInterface */
    private $historyRepository;

    /** @var LoggerInterface */
    private $logger;

    /** @var ShipmentCollectionFactory */
    private $shipments;

    public function __construct(
        Queue $queue,
        OrderRepositoryInterface $orders,
        ShipmentSenderInterface $sender,
        ConfigInterface $config,
        HistoryFactory $historyFactory,
        OrderStatusHistoryRepositoryInterface $historyRepository,
        LoggerInterface $logger,
        ShipmentCollectionFactory $shipments
    ) {
        $this->shipments = $shipments;
        $this->queue = $queue;
        $this->orders = $orders;
        $this->sender = $sender;
        $this->config = $config;
        $this->historyFactory = $historyFactory;
        $this->historyRepository = $historyRepository;
        $this->logger = $logger;
    }

    public function execute(): void
    {
        foreach ($this->queue->due(self::BATCH) as $orderId) {
            if (!$this->queue->claim($orderId)) {
                continue;
            }
            try {
                $this->run($orderId);
            } catch (\Throwable $e) {
                $this->logger->error('[PigeonExpress] Auto create: order ' . $orderId . ' failed: ' . $e->getMessage());
                $this->queue->finish($orderId, Queue::STATUS_UNKNOWN, $e->getMessage());
            }
        }
    }

    /**
     * One queued order, already claimed.
     */
    public function run(int $orderId): void
    {
        try {
            /** @var Order $order */
            $order = $this->orders->get($orderId);
        } catch (\Throwable $e) {
            $this->queue->finish($orderId, Queue::STATUS_SKIPPED, 'Order not found.');

            return;
        }

        $storeId = (int) $order->getStoreId();

        // Turned off while the order waited: nothing happens, as in WooCommerce.
        if (!$this->config->isAutoCreateEnabled($storeId)) {
            $this->queue->finish($orderId, Queue::STATUS_SKIPPED, 'Automatic creation is switched off.');

            return;
        }

        // Cancelled, put on hold or closed in the meantime. Said on the order:
        // it will not be queued again if it comes back to Processing.
        // A cash on delivery order is still Pending (state new) here, see QueueAutoCreate.
        $shippable = $order->getState() === Order::STATE_PROCESSING
            || ($order->getState() === Order::STATE_NEW && $this->sender->isCashOnDelivery($order));
        if (!$shippable) {
            $this->queue->finish($orderId, Queue::STATUS_SKIPPED, 'State is ' . $order->getState() . '.');
            $this->comment($order, (string) __(
                'Pigeon Express: the automatic shipment was skipped because the order status changed to "%1" '
                . 'before it ran. If the order still has to ship, create the shipment from the Pigeon Express panel.',
                (string) $order->getStatusLabel()
            ));

            return;
        }

        // Created by hand while the order waited.
        if ($this->hasShipment($orderId)) {
            $this->queue->finish($orderId, Queue::STATUS_SKIPPED, 'A shipment already exists.');

            return;
        }

        try {
            $shipment = $this->sender->send($order);
        } catch (OrderLockedException $e) {
            // Somebody pressed Send on this order at the same moment. Not a
            // failure: the next run finds their shipment, or the order free.
            $this->queue->retryLater($orderId, self::LOCKED_RETRY_DELAY, $e->getMessage());

            return;
        } catch (OutcomeUnknownException $e) {
            $this->queue->finish($orderId, Queue::STATUS_UNKNOWN, $e->getMessage());
            $this->comment($order, (string) __(
                'Pigeon Express: the request to create the shipment automatically did not complete (%1), so it is '
                . 'not known whether Pigeon Express created it. Check your Pigeon Express account before creating '
                . 'the shipment from this order, to avoid sending it twice.',
                $e->getMessage()
            ));

            return;
        } catch (\Throwable $e) {
            // Created by hand at the same moment: the send was refused for
            // that, and there is nothing to tell the merchant.
            if ($this->hasShipment($orderId)) {
                $this->queue->finish($orderId, Queue::STATUS_SKIPPED, 'A shipment already exists.');

                return;
            }

            if ($this->neverLeft($e) && $this->queue->attempts($orderId) <= count(self::RETRY_DELAYS)) {
                $delay = self::RETRY_DELAYS[$this->queue->attempts($orderId) - 1];
                $this->queue->retryLater($orderId, $delay, $e->getMessage());

                return;
            }

            $this->queue->finish($orderId, Queue::STATUS_FAILED, $e->getMessage());
            $this->comment($order, (string) __(
                'Pigeon Express: the shipment could not be created automatically. %1 '
                . 'Create it from the Pigeon Express panel on this order.',
                rtrim($e->getMessage(), '.') . '.'
            ));

            return;
        }

        $this->queue->finish($orderId, Queue::STATUS_DONE, (string) $shipment->getReferenceNumber());
        $this->comment($order, (string) __(
            'Pigeon Express: the shipment was created automatically (%1).',
            (string) $shipment->getReferenceNumber()
        ));
    }

    /**
     * Whether the order has a shipment that is not cancelled.
     */
    private function hasShipment(int $orderId): bool
    {
        $collection = $this->shipments->create();
        $collection->addFieldToFilter('order_id', ['eq' => $orderId]);
        $collection->addFieldToFilter('status', ['neq' => ShipmentCanceller::STATUS_CANCELLED]);
        $collection->setPageSize(1);

        return (bool) $collection->getFirstItem()->getId();
    }

    /**
     * Whether the failure proves the request never reached the carrier, so a
     * later attempt cannot create a second shipment.
     */
    private function neverLeft(\Throwable $e): bool
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof SdkRateLimitException || $cause instanceof SdkConnectionException) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add a comment to the order's history, without saving the order itself:
     * a save would run every order observer again for nothing.
     */
    private function comment(Order $order, string $text): void
    {
        try {
            $history = $this->historyFactory->create();
            $history->setParentId((int) $order->getId());
            $history->setComment($text);
            $history->setStatus((string) $order->getStatus());
            $history->setEntityName('order');
            $history->setIsCustomerNotified(0);
            $history->setIsVisibleOnFront(0);
            $this->historyRepository->save($history);
        } catch (\Throwable $e) {
            $this->logger->error('[PigeonExpress] Auto create: could not add a comment to order '
                . $order->getId() . ': ' . $e->getMessage());
        }
    }
}
