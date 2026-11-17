<?php
/**
 * React to a change of API key, secret or test mode.
 *
 * A different account or environment has its own offices, and checkout shows
 * none until they are synced, so a sync is queued straight away instead of
 * waiting for the night. Cached prices, fees, limits and streets came from the
 * old one, so they are dropped.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Observer\Config;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface;
use PigeonExpress\Shipping\Model\Api\AddressResolver;
use PigeonExpress\Shipping\Model\Api\RateClient;
use PigeonExpress\Shipping\Model\Service\CarrierLimits;
use PigeonExpress\Shipping\Model\Service\CodFeeResolver;
use PigeonExpress\Shipping\Model\SyncScheduler;
use Psr\Log\LoggerInterface;

class ApiSettingsChanged implements ObserverInterface
{
    private const PATHS = [
        'carriers/pigeonexpress/api_key',
        'carriers/pigeonexpress/api_secret',
        'carriers/pigeonexpress/test_mode',
    ];

    /** @var SyncScheduler */
    private $syncScheduler;

    /** @var CacheInterface */
    private $cache;

    /** @var ManagerInterface */
    private $messageManager;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        SyncScheduler $syncScheduler,
        CacheInterface $cache,
        ManagerInterface $messageManager,
        LoggerInterface $logger
    ) {
        $this->syncScheduler = $syncScheduler;
        $this->cache = $cache;
        $this->messageManager = $messageManager;
        $this->logger = $logger;
    }

    public function execute(Observer $observer): void
    {
        $changed = (array) $observer->getEvent()->getData('changed_paths');
        if (!array_intersect(self::PATHS, $changed)) {
            return;
        }

        $this->cache->clean([
            RateClient::CACHE_TAG,
            CodFeeResolver::CACHE_TAG,
            CarrierLimits::CACHE_TAG,
            AddressResolver::CACHE_TAG,
        ]);

        try {
            $this->syncScheduler->schedule();
            $this->messageManager->addNoticeMessage(
                __('The Pigeon Express connection changed. Offices, lockers and cities will sync on the next cron run (about a minute).')
            );
        } catch (\Throwable $e) {
            $this->logger->error('[PigeonExpress] Could not schedule a sync after a settings change: ' . $e->getMessage());
        }
    }
}
