<?php
/**
 * Read and write access to the locally cached additional services catalogue.
 *
 * The catalogue itself is refreshed from the API by the daily location sync.
 * Fee figures are written here only after the API has actually charged them,
 * so this table never holds a number a developer typed in.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model;

use Magento\Framework\Stdlib\DateTime\DateTime;
use PigeonExpress\Shipping\Model\ResourceModel\AdditionalService as AdditionalServiceResource;
use PigeonExpress\Shipping\Model\ResourceModel\AdditionalService\CollectionFactory;
use Psr\Log\LoggerInterface;

class AdditionalServiceRepository
{
    /** @var AdditionalServiceFactory */
    private $serviceFactory;

    /** @var AdditionalServiceResource */
    private $resource;

    /** @var CollectionFactory */
    private $collectionFactory;

    /** @var DateTime */
    private $dateTime;

    /** @var LoggerInterface */
    private $logger;

    /** @var array<string, AdditionalService>|null */
    private $cache;

    public function __construct(
        AdditionalServiceFactory $serviceFactory,
        AdditionalServiceResource $resource,
        CollectionFactory $collectionFactory,
        DateTime $dateTime,
        LoggerInterface $logger
    ) {
        $this->serviceFactory = $serviceFactory;
        $this->resource = $resource;
        $this->collectionFactory = $collectionFactory;
        $this->dateTime = $dateTime;
        $this->logger = $logger;
    }

    /**
     * All known services keyed by code.
     *
     * @return array<string, AdditionalService>
     */
    public function getAllByCode(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $result = [];
        try {
            $collection = $this->collectionFactory->create();
            foreach ($collection as $service) {
                $code = $service->getCode();
                if ($code !== null && $code !== '') {
                    $result[$code] = $service;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[PigeonExpress] Failed to load additional services catalogue: ' . $e->getMessage());
        }

        $this->cache = $result;

        return $result;
    }

    /**
     * Services present in the last successful catalogue sync.
     *
     * @return array<string, AdditionalService>
     */
    public function getActiveByCode(): array
    {
        return array_filter(
            $this->getAllByCode(),
            static function (AdditionalService $service): bool {
                return $service->getIsActive();
            }
        );
    }

    public function getByCode(string $code): ?AdditionalService
    {
        $all = $this->getAllByCode();

        return $all[$code] ?? null;
    }

    public function isKnown(string $code): bool
    {
        return $this->getByCode($code) !== null;
    }

    /**
     * Replace the stored catalogue with what the API just returned.
     *
     * Rows that vanish from the API are flagged inactive rather than deleted so
     * any fee history we resolved for them survives.
     *
     * @param array<int, array{code:string, name:?string, description:?string, input_type:?string, option_group:?string}> $rows
     * @return array{created:int, updated:int, deactivated:int}
     */
    public function replaceCatalogue(array $rows): array
    {
        $created = 0;
        $updated = 0;
        $seen = [];

        foreach ($rows as $row) {
            $code = isset($row['code']) ? (string) $row['code'] : '';
            if ($code === '') {
                continue;
            }
            $seen[$code] = true;

            $service = $this->loadByCode($code);
            $isNew = $service->getId() === null;

            $service->setCode($code);
            $service->setName($row['name'] ?? null);
            $service->setDescription($row['description'] ?? null);
            $service->setInputType($row['input_type'] ?? null);
            $service->setOptionGroup($row['option_group'] ?? null);
            $service->setIsActive(true);

            try {
                $this->resource->save($service);
                $isNew ? $created++ : $updated++;
            } catch (\Throwable $e) {
                $this->logger->warning(
                    '[PigeonExpress] Failed to store additional service "' . $code . '": ' . $e->getMessage()
                );
            }
        }

        $deactivated = 0;
        foreach ($this->getAllByCode() as $code => $service) {
            if (isset($seen[$code]) || !$service->getIsActive()) {
                continue;
            }
            $service->setIsActive(false);
            try {
                $this->resource->save($service);
                $deactivated++;
            } catch (\Throwable $e) {
                $this->logger->warning(
                    '[PigeonExpress] Failed to deactivate additional service "' . $code . '": ' . $e->getMessage()
                );
            }
        }

        $this->cache = null;

        return ['created' => $created, 'updated' => $updated, 'deactivated' => $deactivated];
    }

    /**
     * Record what the API actually charged for a service, so the figure can be
     * reused as an offline fallback.
     */
    public function recordFee(string $code, float $amount, float $basis): void
    {
        if ($code === '' || $basis <= 0.0) {
            return;
        }

        try {
            $service = $this->loadByCode($code);
            $service->setCode($code);
            $service->setLastFeeAmount(round($amount, 4));
            $service->setLastFeeBasis(round($basis, 4));
            $service->setFeeSyncedAt($this->dateTime->gmtDate());
            $this->resource->save($service);
            $this->cache = null;
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[PigeonExpress] Failed to record fee for additional service "' . $code . '": ' . $e->getMessage()
            );
        }
    }

    private function loadByCode(string $code): AdditionalService
    {
        $service = $this->serviceFactory->create();
        $this->resource->load($service, $code, 'code');

        return $service;
    }
}
