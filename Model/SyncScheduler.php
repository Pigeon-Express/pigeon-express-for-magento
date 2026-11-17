<?php
/**
 * Queue the location sync for the next cron tick.
 *
 * Used by the "Schedule Sync Now" button and when the API key or test mode
 * changes, since a new environment starts with no offices of its own.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model;

use Magento\Cron\Model\ResourceModel\Schedule as ScheduleResource;
use Magento\Cron\Model\ResourceModel\Schedule\CollectionFactory as ScheduleCollectionFactory;
use Magento\Cron\Model\Schedule as CronSchedule;
use Magento\Cron\Model\ScheduleFactory;
use Magento\Framework\Stdlib\DateTime\DateTime;

class SyncScheduler
{
    public const JOB_CODE = 'pigeonexpress_sync_locations';

    /** @var ScheduleFactory */
    private $scheduleFactory;

    /** @var ScheduleCollectionFactory */
    private $scheduleCollectionFactory;

    /** @var DateTime */
    private $dateTime;

    /** @var ScheduleResource */
    private $scheduleResource;

    public function __construct(
        ScheduleFactory $scheduleFactory,
        ScheduleCollectionFactory $scheduleCollectionFactory,
        DateTime $dateTime,
        ScheduleResource $scheduleResource
    ) {
        $this->scheduleFactory = $scheduleFactory;
        $this->scheduleCollectionFactory = $scheduleCollectionFactory;
        $this->dateTime = $dateTime;
        $this->scheduleResource = $scheduleResource;
    }

    /**
     * @return bool false when a sync is already pending, so nothing was added
     * @throws \Exception
     */
    public function schedule(): bool
    {
        $now = $this->dateTime->gmtDate();

        // Cron writes the nightly run ahead of time, so only a pending row that
        // is already due means the sync is about to happen.
        $collection = $this->scheduleCollectionFactory->create();
        $collection->addFieldToFilter('job_code', self::JOB_CODE);
        $collection->addFieldToFilter('status', CronSchedule::STATUS_PENDING);
        $collection->addFieldToFilter('scheduled_at', ['lteq' => $now]);

        if ($collection->getSize() > 0) {
            return false;
        }

        $schedule = $this->scheduleFactory->create();
        $schedule->setJobCode(self::JOB_CODE);
        $schedule->setStatus(CronSchedule::STATUS_PENDING);
        $schedule->setCreatedAt($now);
        $schedule->setScheduledAt($now);
        $this->scheduleResource->save($schedule);

        return true;
    }
}
