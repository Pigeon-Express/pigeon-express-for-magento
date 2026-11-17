<?php
/**
 * Read the status of this order's Pigeon Express shipment now, and what
 * Pigeon Express charged for it. The order panel's "Refresh" button.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Sales\Api\OrderRepositoryInterface;
use PigeonExpress\Shipping\Model\ShipmentCanceller;
use PigeonExpress\Shipping\Model\Status\ShipmentDetailsStore;
use PigeonExpress\Shipping\Model\Status\StatusRefresher;
use Psr\Log\LoggerInterface;

class Refresh extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'PigeonExpress_Shipping::refresh_status';

    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var StatusRefresher */
    private $refresher;

    /** @var ShipmentDetailsStore */
    private $details;

    /** @var ShipmentCanceller */
    private $canceller;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        Context $context,
        OrderRepositoryInterface $orderRepository,
        StatusRefresher $refresher,
        ShipmentDetailsStore $details,
        ShipmentCanceller $canceller,
        LoggerInterface $logger
    ) {
        $this->orderRepository = $orderRepository;
        $this->refresher = $refresher;
        $this->details = $details;
        $this->canceller = $canceller;
        $this->logger = $logger;
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create();
        $orderId = (int) $this->getRequest()->getParam('order_id');

        try {
            $order = $this->orderRepository->get($orderId);
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Order not found.'));

            return $redirect->setPath('sales/order/index');
        }

        $result = $this->refresher->refreshOrders([$orderId]);

        // The charges go with the status. A status that changed has had its
        // details read already; read them for an unchanged one too.
        $row = $this->canceller->findShipment($orderId);
        if ($row !== null && $row->getStatus() !== ShipmentCanceller::STATUS_CANCELLED && $result['updated'] === 0) {
            try {
                $this->details->fetch($row, (int) $order->getStoreId());
            } catch (\Throwable $e) {
                $this->logger->info('[PigeonExpress] Details not refreshed for order #' . $orderId . ': '
                    . $e->getMessage());
            }
        }

        if ($result['failed'] > 0) {
            $this->messageManager->addErrorMessage(
                __('The status could not be read from Pigeon Express. Try again in a moment.')
            );
        } elseif ($result['updated'] > 0) {
            $this->messageManager->addSuccessMessage(__('The shipment status was updated.'));
        } else {
            $this->messageManager->addSuccessMessage(__('The shipment status has not changed.'));
        }

        return $redirect->setPath('sales/order/view', ['order_id' => $orderId]);
    }
}
