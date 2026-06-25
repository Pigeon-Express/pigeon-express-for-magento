<?php
declare(strict_types=1);

namespace PigeonExpress\Shipping\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Sales\Api\OrderRepositoryInterface;
use PigeonExpress\Shipping\Api\ShipmentSenderInterface;
use Psr\Log\LoggerInterface;

class Send extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'PigeonExpress_Shipping::send_shipment';

    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var ShipmentSenderInterface */
    private $shipmentSender;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        Context $context,
        OrderRepositoryInterface $orderRepository,
        ShipmentSenderInterface $shipmentSender,
        LoggerInterface $logger
    ) {
        $this->orderRepository = $orderRepository;
        $this->shipmentSender = $shipmentSender;
        $this->logger = $logger;
        parent::__construct($context);
    }

    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed(self::ADMIN_RESOURCE);
    }

    public function execute(): Redirect
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $orderId = (int) $this->getRequest()->getParam('order_id');

        if ($orderId <= 0) {
            $this->messageManager->addErrorMessage(__('Invalid order ID.'));
            return $resultRedirect->setPath('sales/order/index');
        }

        $orderViewPath = 'sales/order/view';
        $orderViewParams = ['order_id' => $orderId];

        try {
            $order = $this->orderRepository->get($orderId);
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            $this->messageManager->addErrorMessage(__('Order #%1 not found.', $orderId));
            return $resultRedirect->setPath('sales/order/index');
        }

        $overrides = [];
        $phoneOverride = trim((string) $this->getRequest()->getParam('receiver_phone'));
        if ($phoneOverride !== '') {
            $overrides['receiver_phone'] = $phoneOverride;
        }

        try {
            $shipment = $this->shipmentSender->send($order, $overrides);

            $referenceNumber = $shipment->getReferenceNumber();
            $trackingNumber = $shipment->getTrackingNumber();

            $msg = __('Shipment sent to Pigeon Express. Reference: %1', $referenceNumber);
            if ($trackingNumber) {
                $msg = __('Shipment sent to Pigeon Express. Reference: %1, Tracking: %2', $referenceNumber, $trackingNumber);
            }
            $this->messageManager->addSuccessMessage($msg);

        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            $this->logger->warning('[PigeonExpress] Send shipment failed for order #' . $orderId . ': ' . $e->getMessage());
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Failed to send shipment to Pigeon Express. Please check the logs.'));
            $this->logger->error('[PigeonExpress] Send shipment unexpected error for order #' . $orderId . ': ' . $e->getMessage());
        }

        return $resultRedirect->setPath($orderViewPath, $orderViewParams);
    }
}
