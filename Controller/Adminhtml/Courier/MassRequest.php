<?php
/**
 * Sales > Orders mass action "Request a Pigeon Express courier": opens the
 * request form for the selected orders.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Controller\Adminhtml\Courier;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Ui\Component\MassAction\Filter;

class MassRequest extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'PigeonExpress_Shipping::courier_request_create';

    /** @var Filter */
    private $filter;

    /** @var CollectionFactory */
    private $collectionFactory;

    public function __construct(Context $context, Filter $filter, CollectionFactory $collectionFactory)
    {
        $this->filter = $filter;
        $this->collectionFactory = $collectionFactory;
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $redirect = $this->resultRedirectFactory->create();
        try {
            $ids = array_map('intval', $this->filter->getCollection($this->collectionFactory->create())->getAllIds());
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Select at least one order.'));

            return $redirect->setPath('sales/order/index');
        }

        return $redirect->setPath('pigeonexpress/courier/new', ['order_ids' => implode(',', array_slice($ids, 0, 300))]);
    }
}
