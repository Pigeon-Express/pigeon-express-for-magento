<?php
/**
 * pigeonexpress_shipment_created: ship the order in Magento with the waybill
 * number as its tracking number. See Model\Magento\NativeShipment.
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

class CreateMagentoShipment implements ObserverInterface
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

        // The waybill exists by now; nothing here may undo or hide that.
        try {
            $this->nativeShipment->createFor($order, $row);
        } catch (\Throwable $e) {
            $this->logger->error('[PigeonExpress] Magento shipment observer: ' . $e->getMessage());
        }
    }
}
