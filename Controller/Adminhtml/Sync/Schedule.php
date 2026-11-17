<?php
declare(strict_types=1);

namespace PigeonExpress\Shipping\Controller\Adminhtml\Sync;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use PigeonExpress\Shipping\Model\SyncScheduler;

class Schedule extends Action implements HttpGetActionInterface
{
    /** @var SyncScheduler */
    private $syncScheduler;

    public function __construct(
        Context $context,
        SyncScheduler $syncScheduler
    ) {
        $this->syncScheduler = $syncScheduler;
        parent::__construct($context);
    }

    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed('PigeonExpress_Shipping::sync');
    }

    public function execute(): Redirect
    {
        /** @var Redirect $resultRedirect */
        $resultRedirect = $this->resultRedirectFactory->create();
        $fallback = $this->getUrl('adminhtml/system_config/edit', ['section' => 'carriers']);
        $back = $this->_redirect->getRefererUrl() ?: $fallback;

        try {
            if (!$this->syncScheduler->schedule()) {
                $this->messageManager->addNoticeMessage(
                    __('Location sync is already scheduled and pending.')
                );
                return $resultRedirect->setUrl($back);
            }

            $this->messageManager->addSuccessMessage(
                __('Location sync has been scheduled. It will run on the next cron tick (~1 min).')
            );
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(
                __('Failed to schedule sync: %1', $e->getMessage())
            );
        }

        return $resultRedirect->setUrl($back);
    }
}
