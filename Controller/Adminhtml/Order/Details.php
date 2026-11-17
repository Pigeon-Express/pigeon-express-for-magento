<?php
/**
 * Read what Pigeon Express charged for this order's shipment and return the
 * "Pigeon Express charges" box. For a shipment created before the module kept
 * the charges, asked for from the order panel.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\View\LayoutFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use PigeonExpress\Shipping\Block\Adminhtml\Order\Charges;
use PigeonExpress\Shipping\Model\ShipmentCanceller;
use PigeonExpress\Shipping\Model\Status\ShipmentDetailsStore;

class Details extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'PigeonExpress_Shipping::shipment_details';

    /** @var JsonFactory */
    private $resultJsonFactory;

    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var ShipmentCanceller */
    private $canceller;

    /** @var ShipmentDetailsStore */
    private $details;

    /** @var LayoutFactory */
    private $layoutFactory;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        OrderRepositoryInterface $orderRepository,
        ShipmentCanceller $canceller,
        ShipmentDetailsStore $details,
        LayoutFactory $layoutFactory
    ) {
        $this->resultJsonFactory = $resultJsonFactory;
        $this->orderRepository = $orderRepository;
        $this->canceller = $canceller;
        $this->details = $details;
        $this->layoutFactory = $layoutFactory;
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $result = $this->resultJsonFactory->create();

        try {
            $order = $this->orderRepository->get((int) $this->getRequest()->getParam('order_id'));
        } catch (\Throwable $e) {
            return $result->setData(['error' => (string) __('Order not found.')]);
        }

        $row = $this->canceller->findShipment((int) $order->getId());
        if ($row === null || (string) $row->getReferenceNumber() === '') {
            return $result->setData(['error' => (string) __('This order has no Pigeon Express shipment.')]);
        }

        try {
            $this->details->fetch($row, (int) $order->getStoreId());
        } catch (\Throwable $e) {
            return $result->setData([
                'error' => (string) __('The charges could not be read from Pigeon Express. Try again in a moment.'),
            ]);
        }

        /** @var Charges $block */
        $block = $this->layoutFactory->create()->createBlock(Charges::class);
        $block->setShipment($row)->setOrder($order);

        return $result->setData(['html' => $block->toHtml()]);
    }
}
