<?php
/**
 * The form of a new courier request, for the orders given in order_ids (from
 * the order button or the orders grid mass action), or for none.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Controller\Adminhtml\Courier;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;

class NewAction extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'PigeonExpress_Shipping::courier_request_create';

    public function execute(): ResultInterface
    {
        /** @var \Magento\Backend\Model\View\Result\Page $page */
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $page->setActiveMenu('PigeonExpress_Shipping::courier_requests');
        $page->getConfig()->getTitle()->prepend(__('Request a Pigeon Express courier'));

        return $page;
    }
}
