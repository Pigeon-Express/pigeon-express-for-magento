<?php
/**
 * Background status check: every 12 hours, as WooCommerce does by default,
 * read the status of every shipment still open (not cancelled, not in a final
 * status, younger than 60 days), a hundred per request.
 *
 * Does nothing while "Track shipment status" is off or the module has no API
 * credentials. Final statuses are never asked about again.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Cron;

use PigeonExpress\Shipping\Model\Api\ClientFactory;
use PigeonExpress\Shipping\Model\Status\StatusConfig;
use PigeonExpress\Shipping\Model\Status\StatusRefresher;
use Psr\Log\LoggerInterface;

class SyncStatuses
{
    /** @var StatusRefresher */
    private $refresher;

    /** @var StatusConfig */
    private $config;

    /** @var ClientFactory */
    private $clientFactory;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        StatusRefresher $refresher,
        StatusConfig $config,
        ClientFactory $clientFactory,
        LoggerInterface $logger
    ) {
        $this->refresher = $refresher;
        $this->config = $config;
        $this->clientFactory = $clientFactory;
        $this->logger = $logger;
    }

    /**
     * @return array{updated:int, unchanged:int, failed:int, not_found:int, rate_limited:bool}|null Null when off
     */
    public function execute(): ?array
    {
        if (!$this->config->isStatusSyncEnabled() || !$this->clientFactory->hasCredentials()) {
            return null;
        }

        try {
            $open = $this->refresher->openShipments();
            $result = $this->refresher->refreshShipments($open);
        } catch (\Throwable $e) {
            $this->logger->error('[PigeonExpress] Status check failed: ' . $e->getMessage());

            return null;
        }

        $this->logger->info(sprintf(
            '[PigeonExpress] Status check: %d open, %d changed, %d unchanged, %d not found, %d failed',
            count($open),
            $result['updated'],
            $result['unchanged'],
            $result['not_found'],
            $result['failed']
        ));

        return $result;
    }
}
