<?php
/**
 * Shared base of the return shipment actions in the order panel.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Controller\Adminhtml\Reverse;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PigeonExpress\Shipping\Model\ReturnShipment\ReturnShipmentService;
use Psr\Log\LoggerInterface;

abstract class AbstractReverse extends Action
{
    public const ADMIN_RESOURCE = 'PigeonExpress_Shipping::return_shipment';

    /** @var OrderRepositoryInterface */
    protected $orderRepository;

    /** @var ReturnShipmentService */
    protected $service;

    /** @var LoggerInterface */
    protected $logger;

    public function __construct(
        Context $context,
        OrderRepositoryInterface $orderRepository,
        ReturnShipmentService $service,
        LoggerInterface $logger
    ) {
        $this->orderRepository = $orderRepository;
        $this->service = $service;
        $this->logger = $logger;
        parent::__construct($context);
    }

    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed(static::ADMIN_RESOURCE);
    }

    protected function loadOrder(): ?OrderInterface
    {
        $orderId = (int) $this->getRequest()->getParam('order_id');
        if ($orderId <= 0) {
            return null;
        }
        try {
            return $this->orderRepository->get($orderId);
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function back(?OrderInterface $order): \Magento\Framework\Controller\Result\Redirect
    {
        $redirect = $this->resultRedirectFactory->create();

        return $order
            ? $redirect->setPath('sales/order/view', ['order_id' => (int) $order->getEntityId(), '_fragment' => 'pe-return-shipment'])
            : $redirect->setPath('sales/order/index');
    }

    /**
     * A failure in the merchant's words; anything unexpected goes to the log.
     */
    protected function fail(\Throwable $e, string $what): void
    {
        if ($e instanceof \Magento\Framework\Exception\LocalizedException) {
            $this->messageManager->addErrorMessage($e->getMessage());

            return;
        }
        $this->logger->error('[PigeonExpress] Return shipment ' . $what . ' failed: ' . $e->getMessage());
        $this->messageManager->addErrorMessage(__('Could not reach Pigeon Express. Try again.'));
    }
}
