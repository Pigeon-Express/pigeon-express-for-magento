<?php
/**
 * Cancel the Pigeon Express shipment attached to an order.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;
use PigeonExpress\Shipping\Model\ShipmentCanceller;
use Psr\Log\LoggerInterface;

class Cancel extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'PigeonExpress_Shipping::cancel_shipment';

    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var ShipmentCanceller */
    private $shipmentCanceller;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        Context $context,
        OrderRepositoryInterface $orderRepository,
        ShipmentCanceller $shipmentCanceller,
        LoggerInterface $logger
    ) {
        $this->orderRepository = $orderRepository;
        $this->shipmentCanceller = $shipmentCanceller;
        $this->logger = $logger;
        parent::__construct($context);
    }

    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed(self::ADMIN_RESOURCE);
    }

    public function execute(): Redirect
    {
        /** @var Redirect $redirect */
        $redirect = $this->resultRedirectFactory->create();
        $orderId = (int) $this->getRequest()->getParam('order_id');

        if ($orderId <= 0) {
            $this->messageManager->addErrorMessage(__('No order was specified.'));
            return $redirect->setPath('sales/order/index');
        }

        try {
            $order = $this->orderRepository->get($orderId);
            $shipment = $this->shipmentCanceller->cancelForOrder($orderId, (int) $order->getStoreId());

            $this->messageManager->addSuccessMessage(__(
                'Pigeon Express shipment %1 has been cancelled.',
                (string) $shipment->getReferenceNumber()
            ));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('[PigeonExpress] Cancel controller error: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(
                __('Could not cancel the Pigeon Express shipment. Check the log for details.')
            );
        }

        return $redirect->setPath('sales/order/view', ['order_id' => $orderId]);
    }
}
