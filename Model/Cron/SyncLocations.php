<?php
/**
 * Pigeon Express daily cron – sync Offices and APS from API.
 *
 * API key and secret are not set in cron; they are read from Magento config
 * (Stores → Configuration → Carriers → Pigeon Express) via ConfigInterface
 * when LocationSync and LocationsClient run.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Cron;

use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Api\LocationSyncInterface;
use PigeonExpress\Shipping\Exception\ApiException;

class SyncLocations
{
    /** @var LocationSyncInterface */
    private $locationSync;

    /** @var ConfigInterface */
    private $config;

    /** @var LoggerInterface */
    private $logger;

    /** @var StoreManagerInterface */
    private $storeManager;

    public function __construct(
        LocationSyncInterface $locationSync,
        ConfigInterface $config,
        LoggerInterface $logger,
        StoreManagerInterface $storeManager
    ) {
        $this->locationSync = $locationSync;
        $this->config = $config;
        $this->logger = $logger;
        $this->storeManager = $storeManager;
    }

    /**
     * Execute daily sync. Credentials come from config (getApiKey/getApiSecret).
     */
    public function execute(): void
    {
        $this->logger->info('[PigeonExpress Cron] pigeonexpress_sync_locations execute() started');

        if (!$this->isActiveAnywhere()) {
            $this->logger->info('[PigeonExpress Cron] Skipped: carrier is disabled in every store');
            return;
        }
        if ($this->config->getApiKey() === '') {
            $this->logger->warning('[PigeonExpress Cron] Skipped: API key not set in Stores → Configuration → Carriers → Pigeon Express');
            return;
        }

        try {
            $this->logger->info('[PigeonExpress Cron] Start location sync');
            $stats = $this->locationSync->syncAll();
            $this->logger->info(
                '[PigeonExpress Cron] Sync done.'
                . ' Offices: created=' . $stats['offices']['created']
                . ', updated=' . $stats['offices']['updated'] . ', deactivated=' . $stats['offices']['deactivated']
                . ' | APS: created=' . $stats['aps']['created']
                . ', updated=' . $stats['aps']['updated'] . ', deactivated=' . $stats['aps']['deactivated']
                . ' | Cities: created=' . $stats['cities']['created'] . ', updated=' . $stats['cities']['updated']
            );
        } catch (ApiException $e) {
            $this->logger->error('[PigeonExpress Cron] Sync failed: ' . $e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('[PigeonExpress Cron] Sync error: ' . $e->getMessage());
        }
    }

    /**
     * The carrier can be switched on for a single website or store view, and
     * the offices are shared by all of them, so any one is enough.
     */
    private function isActiveAnywhere(): bool
    {
        if ($this->config->isActive(0)) {
            return true;
        }

        foreach ($this->storeManager->getStores() as $store) {
            if ($this->config->isActive((int) $store->getId())) {
                return true;
            }
        }

        return false;
    }
}
