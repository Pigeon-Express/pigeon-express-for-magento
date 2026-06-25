<?php
declare(strict_types=1);

namespace PigeonExpress\Shipping\Observer\Sales;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use PigeonExpress\Shipping\Api\ConfigInterface;
use Psr\Log\LoggerInterface;

class OrderPlaceAfter implements ObserverInterface
{
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function execute(Observer $observer)
    {
        /** @var \Magento\Sales\Model\Order $order */
        $order = $observer->getEvent()->getOrder();
        if (!$order) {
            return;
        }

        $method = $order->getShippingMethod();
        $prefix = ConfigInterface::CARRIER_CODE . '_';
        if ($method === null || strpos($method, $prefix) !== 0) {
            return;
        }

        $address = $order->getShippingAddress();
        if (!$address) {
            return;
        }

        $ext = $address->getExtensionAttributes();
        if ($ext && $ext->getPigeonexpressInstructions()) {
            $instructions = trim((string) $ext->getPigeonexpressInstructions());
            if ($instructions !== '') {
                // Add order comment
                $order->addStatusHistoryComment(
                    __('Pigeon Express Delivery Instructions: %1', $instructions)
                );
                
                $this->logger->info('[PE OrderPlaceAfter] Added delivery instructions to order', [
                    'order_id' => $order->getEntityId(),
                    'increment_id' => $order->getIncrementId()
                ]);
            }
        }
    }
}
