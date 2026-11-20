<?php
/**
 * Open the quick changes of a shipment the courier already has: ask Pigeon
 * Express what it allows now (one GET /actions) and answer with the forms,
 * drawn from its current values. Writes nothing.
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
use PigeonExpress\Shipping\Block\Adminhtml\Order\QuickActions as QuickActionsBlock;
use PigeonExpress\Shipping\Model\QuickActions;
use PigeonExpress\Shipping\Model\QuickActions\QuickActionException;
use PigeonExpress\Shipping\Model\ShipmentCanceller;
use PigeonExpress\Shipping\Model\ShipmentEditor;
use Psr\Log\LoggerInterface;

class QuickOptions extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'PigeonExpress_Shipping::quick_change';

    /** @var JsonFactory */
    private $resultJsonFactory;

    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var ShipmentCanceller */
    private $finder;

    /** @var ShipmentEditor */
    private $editor;

    /** @var QuickActions */
    private $quickActions;

    /** @var LayoutFactory */
    private $layoutFactory;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        OrderRepositoryInterface $orderRepository,
        ShipmentCanceller $finder,
        ShipmentEditor $editor,
        QuickActions $quickActions,
        LayoutFactory $layoutFactory,
        LoggerInterface $logger
    ) {
        $this->resultJsonFactory = $resultJsonFactory;
        $this->orderRepository = $orderRepository;
        $this->finder = $finder;
        $this->editor = $editor;
        $this->quickActions = $quickActions;
        $this->layoutFactory = $layoutFactory;
        $this->logger = $logger;
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

        $row = $this->finder->findShipment((int) $order->getEntityId());
        if (!QuickActions::isOffered($row, $this->editor->editBlockReason($row))) {
            return $result->setData([
                'error' => (string) __('Quick changes are not offered for this shipment now. Reload the order.'),
            ]);
        }

        try {
            $view = $this->quickActions->options($order, $row);
        } catch (QuickActionException $e) {
            return $result->setData(['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->logger->error('[PigeonExpress] Quick changes not opened: ' . $e->getMessage());

            return $result->setData(['error' => (string) __('Could not reach Pigeon Express.')]);
        }

        /** @var QuickActionsBlock $block */
        $block = $this->layoutFactory->create()->createBlock(QuickActionsBlock::class);
        $block->setTemplate('PigeonExpress_Shipping::order/quick-actions-forms.phtml')
            ->setOrder($order)
            ->setView($view);

        return $result->setData(['html' => $block->toHtml()]);
    }
}
