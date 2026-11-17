<?php
/**
 * Queue an order for an automatic shipment when it reaches Processing.
 *
 * Only queues: the shipment is created by the cron job, never during the
 * request that saved the order, so a slow carrier cannot hold up checkout
 * or the admin, and a failure cannot undo the order save.
 *
 * @copyright Copyright (c). All rights reserved.
 */
declare(strict_types=1);

namespace PigeonExpress\Shipping\Observer\Sales;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Api\ShipmentSenderInterface;
use PigeonExpress\Shipping\Model\AutoCreate\Queue;
use Psr\Log\LoggerInterface;

class QueueAutoCreate implements ObserverInterface
{
    /** @var ConfigInterface */
    private $config;

    /** @var Queue */
    private $queue;

    /** @var LoggerInterface */
    private $logger;

    private $sender;

    public function __construct(
        ConfigInterface $config,
        Queue $queue,
        LoggerInterface $logger,
        ShipmentSenderInterface $sender
    ) {
        $this->sender = $sender;
        $this->config = $config;
        $this->queue = $queue;
        $this->logger = $logger;
    }

    public function execute(Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();
        if (!$order instanceof Order || !$order->getId()) {
            return;
        }

        try {
            if (!$this->becameProcessing($order) && !$this->placedAsCashOnDelivery($order)) {
                return;
            }
            if (strpos((string) $order->getShippingMethod(), ConfigInterface::CARRIER_CODE . '_') !== 0) {
                return;
            }
            $storeId = (int) $order->getStoreId();
            if (!$this->config->isAutoCreateEnabled($storeId)) {
                return;
            }

            if ($this->queue->add((int) $order->getId()) && $this->config->isLoggingEnabled($storeId)) {
                $this->logger->info('[PigeonExpress] Auto create: order #' . $order->getIncrementId() . ' queued');
            }
        } catch (\Throwable $e) {
            // Never let the queue break an order save.
            $this->logger->error('[PigeonExpress] Auto create: could not queue order ' . $order->getId() . ': '
                . $e->getMessage());
        }
    }

    /**
     * Whether this save moved the order into Processing.
     */
    private function becameProcessing(Order $order): bool
    {
        if ($order->getState() !== Order::STATE_PROCESSING) {
            return false;
        }

        $before = $order->getOrigData('state');

        // A new order placed straight into Processing has no earlier state.
        return $before !== Order::STATE_PROCESSING;
    }

    /**
     * A cash on delivery order stays Pending in Magento until it is invoiced,
     * which is usually after delivery. In WooCommerce it is Processing from the
     * start, so it is queued when it is placed, as there.
     */
    private function placedAsCashOnDelivery(Order $order): bool
    {
        if ($order->getState() !== Order::STATE_NEW || (string) $order->getOrigData('state') !== '') {
            return false;
        }

        return $this->sender->isCashOnDelivery($order);
    }
}
