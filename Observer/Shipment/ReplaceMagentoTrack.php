<?php
/**
 * pigeonexpress_shipment_redirected: the order moved to a new waybill after a
 * redirect, so the old tracking number on its Magento shipment is replaced
 * with the new one. See Model\Shipment\RedirectFollower and
 * Model\Magento\NativeShipment::replaceTrackFor().
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Observer\Shipment;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;
use PigeonExpress\Shipping\Model\Magento\NativeShipment;
use Psr\Log\LoggerInterface;

class ReplaceMagentoTrack implements ObserverInterface
{
    /** @var NativeShipment */
    private $nativeShipment;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(NativeShipment $nativeShipment, LoggerInterface $logger)
    {
        $this->nativeShipment = $nativeShipment;
        $this->logger = $logger;
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');
        $new = $observer->getEvent()->getData('shipment_row');
        $old = $observer->getEvent()->getData('old_row');

        if (!$order instanceof OrderInterface || !is_array($new) || !is_array($old)) {
            return;
        }

        try {
            $this->nativeShipment->replaceTrackFor($order, $old, $new);
        } catch (\Throwable $e) {
            $this->logger->error('[PigeonExpress] Magento track replace observer: ' . $e->getMessage());
        }
    }
}
