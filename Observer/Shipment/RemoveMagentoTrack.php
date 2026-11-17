<?php
/**
 * pigeonexpress_shipment_cancelled: take the cancelled waybill's tracking
 * number off its Magento shipment. Magento cannot delete a shipment, so the
 * shipment itself stays; see Model\Magento\NativeShipment.
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

class RemoveMagentoTrack implements ObserverInterface
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
        $row = $observer->getEvent()->getData('shipment_row');

        if (!$order instanceof OrderInterface || !is_array($row)) {
            return;
        }

        try {
            $this->nativeShipment->removeTrackFor($order, $row);
        } catch (\Throwable $e) {
            $this->logger->error('[PigeonExpress] Magento track removal observer: ' . $e->getMessage());
        }
    }
}
