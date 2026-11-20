<?php
/**
 * Read the status of this order's Pigeon Express shipment now, and what
 * Pigeon Express charged for it. The order panel's "Refresh" button.
 *
 * A shipment Pigeon Express redirected (a new waybill) moves the order to the
 * new shipment here (Model\Shipment\RedirectFollower): from its status
 * (StatusRefresher) or from its details. A customer email such a move owes
 * from an earlier attempt that failed is sent again here.
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
use PigeonExpress\Shipping\Model\Magento\RedirectNotifier;
use PigeonExpress\Shipping\Model\Shipment\RedirectFollower;
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

    /** @var RedirectFollower */
    private $follower;

    /** @var RedirectNotifier */
    private $notifier;

    public function __construct(
        Context $context,
        OrderRepositoryInterface $orderRepository,
        StatusRefresher $refresher,
        ShipmentDetailsStore $details,
        ShipmentCanceller $canceller,
        LoggerInterface $logger,
        RedirectFollower $follower,
        RedirectNotifier $notifier
    ) {
        $this->orderRepository = $orderRepository;
        $this->refresher = $refresher;
        $this->details = $details;
        $this->canceller = $canceller;
        $this->logger = $logger;
        $this->follower = $follower;
        $this->notifier = $notifier;
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

        $storeId = (int) $order->getStoreId();
        $result = $this->refresher->refreshOrders([$orderId]);
        $moved = (string) ($result['moved'][$orderId] ?? '');

        // The charges go with the status. A status that changed has had its
        // details read already; read them for an unchanged one too. Details
        // naming a redirect move the order as well.
        $row = $this->canceller->findShipment($orderId);
        if ($row !== null && $row->getStatus() !== ShipmentCanceller::STATUS_CANCELLED && $result['updated'] === 0) {
            try {
                $stored = $this->details->fetch($row, $storeId);
                if (RedirectFollower::redirectOf($stored) !== '') {
                    $moved = $this->follower->follow($orderId, (string) $row->getReferenceNumber(), [], $storeId);
                }
            } catch (\Throwable $e) {
                $this->logger->info('[PigeonExpress] Details not refreshed for order #' . $orderId . ': '
                    . $e->getMessage());
            }
        }

        if ($moved !== '') {
            $this->messageManager->addSuccessMessage(__(
                'Pigeon Express redirected this shipment; the order now follows the new shipment %1.',
                $moved
            ));
        } elseif ($result['failed'] > 0) {
            $this->messageManager->addErrorMessage(
                __('The status could not be read from Pigeon Express. Try again in a moment.')
            );
        } elseif ($result['updated'] > 0) {
            $this->messageManager->addSuccessMessage(__('The shipment status was updated.'));
        } else {
            $this->messageManager->addSuccessMessage(__('The shipment status has not changed.'));
        }

        // A redirect whose customer email failed earlier: try it again.
        $current = $this->canceller->findShipment($orderId);
        if ($current !== null && $moved === '') {
            try {
                if ($this->notifier->retryPending($order, $current)) {
                    $this->messageManager->addSuccessMessage(__('The customer was emailed the new tracking number.'));
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[PigeonExpress] Redirect email retry failed: ' . $e->getMessage());
            }
        }

        return $redirect->setPath('sales/order/view', ['order_id' => $orderId]);
    }
}
