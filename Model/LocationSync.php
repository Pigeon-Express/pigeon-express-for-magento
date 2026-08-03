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
use PigeonExpress\Shipping\Api\AdditionalServicesClientInterface;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Api\LocationSyncInterface;
use PigeonExpress\Shipping\Api\LocationsClientInterface;
use PigeonExpress\Shipping\Exception\ApiException;

class LocationSync implements LocationSyncInterface
{
    /**
     * A sync returning less than this share of what is already stored is
     * treated as truncated and rejected.
     */
    private const TRUNCATION_GUARD_RATIO = 0.5;

    /** Below this many stored rows there is nothing worth protecting. */
    private const TRUNCATION_GUARD_MIN_STORED = 20;

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

    /** @var AdditionalServicesClientInterface */
    private $additionalServicesClient;

    /** @var AdditionalServiceRepository */
    private $additionalServiceRepository;

    public function __construct(
        LocationsClientInterface $locationsClient,
        OfficeFactory $officeFactory,
        ApsFactory $apsFactory,
        \PigeonExpress\Shipping\Model\ResourceModel\Office\CollectionFactory $officeCollectionFactory,
        \PigeonExpress\Shipping\Model\ResourceModel\Aps\CollectionFactory $apsCollectionFactory,
        \PigeonExpress\Shipping\Model\ResourceModel\City\CollectionFactory $cityCollectionFactory,
        TransactionFactory $transactionFactory,
        ConfigInterface $config,
        LoggerInterface $logger,
        AdditionalServicesClientInterface $additionalServicesClient,
        AdditionalServiceRepository $additionalServiceRepository
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
        $this->additionalServicesClient = $additionalServicesClient;
        $this->additionalServiceRepository = $additionalServiceRepository;
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
                'district'    => isset($row['district']) ? (string) $row['district'] : null,
                'district_en' => isset($row['district_en']) ? (string) $row['district_en'] : null,
            ];
        }

        $resource   = $this->cityCollectionFactory->create()->getResource();
        $connection = $resource->getConnection();
        $table      = $resource->getMainTable();

        if (!empty($rows)) {
            $connection->insertOnDuplicate(
                $table,
                array_values($rows),
                ['name', 'name_en', 'postal_code', 'district', 'district_en']
            );
        }

        $count = count($rows);

        if ($this->config->isLoggingEnabled()) {
            $this->logger->info('[PigeonExpress] Sync cities: upserted=' . $count);
        }

        return ['created' => $count, 'updated' => 0];
    }

    /**
     * Refresh the additional services catalogue from GET /additional-services.
     *
     * Services that disappear from the API are flagged inactive rather than
     * deleted, so any fee we resolved for them is not lost.
     *
     * @return array{created: int, updated: int, deactivated: int}
     * @throws ApiException
     */
    public function syncAdditionalServices(): array
    {
        $rows = $this->additionalServicesClient->fetchAll(0);
        $stats = $this->additionalServiceRepository->replaceCatalogue($rows);

        if ($this->config->isLoggingEnabled()) {
            $this->logger->info('[PigeonExpress] Sync additional services', $stats);
        }

        return $stats;
    }

    /**
     * @inheritdoc
     */
    public function syncAll(): array
    {
        // Each dataset is independent. One failing must not stop the others,
        // otherwise a single bad response costs a whole night's sync.
        $empty = ['created' => 0, 'updated' => 0, 'deactivated' => 0];

        $tasks = [
            'offices'             => function (): array {
                return $this->syncOffices();
            },
            'aps'                 => function (): array {
                return $this->syncAps();
            },
            'cities'              => function (): array {
                return $this->syncCities();
            },
            'additional_services' => function (): array {
                return $this->syncAdditionalServices();
            },
        ];

        $result = [];
        foreach ($tasks as $name => $task) {
            try {
                $result[$name] = $task();
            } catch (\Throwable $e) {
                $this->logger->error('[PigeonExpress] Sync failed for ' . $name . ': ' . $e->getMessage());
                $result[$name] = $empty + ['error' => $e->getMessage()];
            }
        }

        return $result;
    }

    /**
     * Refuse a sync that would replace a healthy dataset with a fragment.
     *
     * A truncated fetch, from a partial pagination walk or an API hiccup, would
     * otherwise deactivate most of the merchant's offices and quietly remove
     * pickup locations from checkout. Growth and modest shrinkage are allowed;
     * only a collapse is treated as a fault.
     *
     * @throws ApiException
     */
    private function guardAgainstTruncation(string $type, int $fetched, int $stored): void
    {
        if ($stored < self::TRUNCATION_GUARD_MIN_STORED) {
            return;
        }

        if ($fetched >= (int) ceil($stored * self::TRUNCATION_GUARD_RATIO)) {
            return;
        }

        $this->logger->error(
            '[PigeonExpress] Refusing to sync ' . $type . ': the API returned ' . $fetched
            . ' rows but ' . $stored . ' are stored. Existing data has been left untouched.'
        );

        throw new ApiException(__(
            'Pigeon Express %1 sync aborted: the API returned %2 records but %3 are stored. '
            . 'Existing data was kept. Check the API and retry.',
            $type,
            $fetched,
            $stored
        ));
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

        $this->guardAgainstTruncation($type, count($apiLocations), count($existing));

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
