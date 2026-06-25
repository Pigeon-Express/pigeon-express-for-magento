<?php
/**
 * Pigeon Express Location Sync – upsert from API, deactivate removed.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model;

use Magento\Framework\DB\TransactionFactory;
use Psr\Log\LoggerInterface;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Api\LocationSyncInterface;
use PigeonExpress\Shipping\Api\LocationsClientInterface;
use PigeonExpress\Shipping\Exception\ApiException;

class LocationSync implements LocationSyncInterface
{
    /** @var LocationsClientInterface */
    private $locationsClient;

    /** @var OfficeFactory */
    private $officeFactory;

    /** @var ApsFactory */
    private $apsFactory;

    /** @var \PigeonExpress\Shipping\Model\ResourceModel\Office\CollectionFactory */
    private $officeCollectionFactory;

    /** @var \PigeonExpress\Shipping\Model\ResourceModel\Aps\CollectionFactory */
    private $apsCollectionFactory;

    /** @var \PigeonExpress\Shipping\Model\ResourceModel\City\CollectionFactory */
    private $cityCollectionFactory;

    /** @var TransactionFactory */
    private $transactionFactory;

    /** @var ConfigInterface */
    private $config;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        LocationsClientInterface $locationsClient,
        OfficeFactory $officeFactory,
        ApsFactory $apsFactory,
        \PigeonExpress\Shipping\Model\ResourceModel\Office\CollectionFactory $officeCollectionFactory,
        \PigeonExpress\Shipping\Model\ResourceModel\Aps\CollectionFactory $apsCollectionFactory,
        \PigeonExpress\Shipping\Model\ResourceModel\City\CollectionFactory $cityCollectionFactory,
        TransactionFactory $transactionFactory,
        ConfigInterface $config,
        LoggerInterface $logger
    ) {
        $this->locationsClient = $locationsClient;
        $this->officeFactory = $officeFactory;
        $this->apsFactory = $apsFactory;
        $this->officeCollectionFactory = $officeCollectionFactory;
        $this->apsCollectionFactory = $apsCollectionFactory;
        $this->cityCollectionFactory = $cityCollectionFactory;
        $this->transactionFactory = $transactionFactory;
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * @inheritdoc
     */
    public function syncOffices(): array
    {
        $apiList = $this->locationsClient->fetchOffices();
        return $this->syncLocations($apiList, 'office');
    }

    /**
     * @inheritdoc
     */
    public function syncAps(): array
    {
        $apiList = $this->locationsClient->fetchAps();
        return $this->syncLocations($apiList, 'aps');
    }

    /**
     * @inheritdoc
     */
    public function syncCities(): array
    {
        $apiList = $this->locationsClient->fetchCities();

        // Dedup by api_id (API may return duplicates).
        $rows = [];
        foreach ($apiList as $row) {
            $apiId = (int) $row['id'];
            $rows[$apiId] = [
                'api_id'      => $apiId,
                'name'        => (string) ($row['name'] ?? ''),
                'name_en'     => isset($row['name_en']) ? (string) $row['name_en'] : null,
                'postal_code' => isset($row['postal_code']) ? (string) $row['postal_code'] : null,
            ];
        }

        $resource   = $this->cityCollectionFactory->create()->getResource();
        $connection = $resource->getConnection();
        $table      = $resource->getMainTable();

        if (!empty($rows)) {
            $connection->insertOnDuplicate($table, array_values($rows), ['name', 'name_en', 'postal_code']);
        }

        $count = count($rows);

        if ($this->config->isLoggingEnabled()) {
            $this->logger->info('[PigeonExpress] Sync cities: upserted=' . $count);
        }

        return ['created' => $count, 'updated' => 0];
    }

    /**
     * @inheritdoc
     */
    public function syncAll(): array
    {
        return [
            'offices' => $this->syncOffices(),
            'aps'     => $this->syncAps(),
            'cities'  => $this->syncCities(),
        ];
    }

    /**
     * @param array[] $apiLocations
     * @param string $type 'office'|'aps'
     * @return array{created: int, updated: int, deactivated: int}
     * @throws ApiException
     */
    private function syncLocations(array $apiLocations, string $type): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'deactivated' => 0];

        $collection = $type === 'office'
            ? $this->officeCollectionFactory->create()
            : $this->apsCollectionFactory->create();

        $existing = [];
        foreach ($collection as $item) {
            $apiId = $item->getApiId();
            if ($apiId !== null) {
                $existing[$apiId] = $item;
            }
        }

        $apiIds = array_column($apiLocations, 'id');
        $transaction = $this->transactionFactory->create();

        foreach ($apiLocations as $row) {
            $apiId = (int) $row['id'];
            if (isset($existing[$apiId])) {
                $model = $existing[$apiId];
                $this->applyApiData($model, $row);
                $model->setIsActive(true);
                $transaction->addObject($model);
                $stats['updated']++;
            } else {
                $model = $type === 'office' ? $this->officeFactory->create() : $this->apsFactory->create();
                $this->applyApiData($model, $row);
                $model->setIsActive(true);
                $transaction->addObject($model);
                $stats['created']++;
            }
        }

        $toDeactivate = array_diff(array_keys($existing), $apiIds);
        foreach ($toDeactivate as $apiId) {
            $model = $existing[$apiId];
            $model->setIsActive(false);
            $transaction->addObject($model);
            $stats['deactivated']++;
        }

        $transaction->save();

        if ($this->config->isLoggingEnabled()) {
            $this->logger->info(
                '[PigeonExpress] Sync ' . $type . ': created=' . $stats['created']
                . ', updated=' . $stats['updated'] . ', deactivated=' . $stats['deactivated']
            );
        }

        return $stats;
    }

    /**
     * @param Office|Aps $model
     * @param array $row
     */
    private function applyApiData($model, array $row): void
    {
        $model->setApiId((int) $row['id']);
        $model->setName((string) ($row['name'] ?? ''));
        $model->setAddress((string) ($row['address'] ?? ''));
        $model->setCityId(isset($row['city_id']) ? (int) $row['city_id'] : null);
        $model->setCity(null);
        $model->setCountry('BG');
        $model->setPostcode(null);
        $model->setLatitude(isset($row['latitude']) ? (float) $row['latitude'] : null);
        $model->setLongitude(isset($row['longitude']) ? (float) $row['longitude'] : null);
    }
}
