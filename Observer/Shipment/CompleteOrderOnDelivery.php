<?php
/**
 * pigeonexpress_shipment_status_changed: the first time a waybill is
 * delivered, complete the order when the settings ask for it. See
 * Model\Magento\CompleteOnDelivery.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Observer\Shipment;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;
use PigeonExpress\Shipping\Model\Magento\CompleteOnDelivery;
use Psr\Log\LoggerInterface;

class CompleteOrderOnDelivery implements ObserverInterface
{
    /** @var CompleteOnDelivery */
    private $completeOnDelivery;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(CompleteOnDelivery $completeOnDelivery, LoggerInterface $logger)
    {
        $this->completeOnDelivery = $completeOnDelivery;
        $this->logger = $logger;
    }

    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        $order = $event->getData('order');
        $row = $event->getData('shipment_row');

        if (!$event->getData('is_delivered') || !$order instanceof OrderInterface) {
            return;
        }

        // A status repeated for the same code is not a new delivery.
        if ((string) $event->getData('old_code') === (string) $event->getData('new_code')) {
            return;
        }

        try {
            $this->completeOnDelivery->onDelivered($order, is_array($row) ? $row : []);
        } catch (\Throwable $e) {
            $this->logger->error('[PigeonExpress] Complete on delivery observer: ' . $e->getMessage());
        }
    }
}
