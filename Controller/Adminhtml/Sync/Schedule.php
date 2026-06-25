<?php
declare(strict_types=1);

namespace PigeonExpress\Shipping\Controller\Adminhtml\Sync;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Cron\Model\ResourceModel\Schedule\CollectionFactory as ScheduleCollectionFactory;
use Magento\Cron\Model\Schedule as CronSchedule;
use Magento\Cron\Model\ScheduleFactory;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Cron\Model\ResourceModel\Schedule as ScheduleResource;
use Magento\Framework\Stdlib\DateTime\DateTime;

class Schedule extends Action implements HttpGetActionInterface
{
    private const JOB_CODE = 'pigeonexpress_sync_locations';

    /** @var ScheduleFactory */
    private $scheduleFactory;

    /** @var ScheduleCollectionFactory */
    private $scheduleCollectionFactory;

    /** @var DateTime */
    private $dateTime;

    /** @var ScheduleResource */
    private $scheduleResource;

    public function __construct(
        Context $context,
        ScheduleFactory $scheduleFactory,
        ScheduleCollectionFactory $scheduleCollectionFactory,
        DateTime $dateTime,
        ScheduleResource $scheduleResource
    ) {
        $this->scheduleFactory = $scheduleFactory;
        $this->scheduleCollectionFactory = $scheduleCollectionFactory;
        $this->dateTime = $dateTime;
        $this->scheduleResource = $scheduleResource;
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
            $collection = $this->scheduleCollectionFactory->create();
            $collection->addFieldToFilter('job_code', self::JOB_CODE);
            $collection->addFieldToFilter('status', CronSchedule::STATUS_PENDING);

            if ($collection->getSize() > 0) {
                $this->messageManager->addNoticeMessage(
                    __('Location sync is already scheduled and pending.')
                );
                return $resultRedirect->setUrl($back);
            }

            $now = $this->dateTime->gmtDate();

            $schedule = $this->scheduleFactory->create();
            $schedule->setJobCode(self::JOB_CODE);
            $schedule->setStatus(CronSchedule::STATUS_PENDING);
            $schedule->setCreatedAt($now);
            $schedule->setScheduledAt($now);
            $this->scheduleResource->save($schedule);

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
