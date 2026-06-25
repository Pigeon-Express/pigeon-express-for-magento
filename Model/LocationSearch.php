<?php
/**
 * Location search implementation: queries pigeonexpress_office and pigeonexpress_aps (local DB only).
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Api\LocationSearchInterface;
use PigeonExpress\Shipping\Model\ResourceModel\Office\CollectionFactory as OfficeCollectionFactory;
use PigeonExpress\Shipping\Model\ResourceModel\Aps\CollectionFactory as ApsCollectionFactory;

class LocationSearch implements LocationSearchInterface
{
    /**
     * @var OfficeCollectionFactory
     */
    private $officeCollectionFactory;

    /**
     * @var ApsCollectionFactory
     */
    private $apsCollectionFactory;

    /**
     * @var ConfigInterface
     */
    private $config;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var ResourceConnection
     */
    private $resource;

    public function __construct(
        OfficeCollectionFactory $officeCollectionFactory,
        ApsCollectionFactory $apsCollectionFactory,
        ConfigInterface $config,
        StoreManagerInterface $storeManager,
        ResourceConnection $resource
    ) {
        $this->officeCollectionFactory = $officeCollectionFactory;
        $this->apsCollectionFactory = $apsCollectionFactory;
        $this->config = $config;
        $this->storeManager = $storeManager;
        $this->resource = $resource;
    }

    /**
     * Join pigeonexpress_city to resolve city name and postcode via city_id when not set on the location row.
     *
     * @param \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection $collection
     */
    private function joinCityData($collection): void
    {
        $cityTable = $this->resource->getTableName('pigeonexpress_city');
        $collection->getSelect()->joinLeft(
            ['pe_city' => $cityTable],
            'pe_city.api_id = main_table.city_id',
            [
                'city_resolved' => 'COALESCE(main_table.city, pe_city.name)',
                'postcode_resolved' => 'COALESCE(main_table.postcode, pe_city.postal_code)',
            ]
        );
    }

    /**
     * Escape value for use in LIKE expression (\% \_ \\).
     *
     * @param string $value
     * @return string
     */
    private function escapeLikeValue(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace(['_', '%'], ['\_', '\%'], $value);
        return $value;
    }

    /**
     * @inheritdoc
     */
    public function searchOffices(string $query, int $storeId = 0, int $limit = 0): array
    {
        $limit = $limit > 0 ? $limit : $this->getSearchLimit($storeId);
        $q = trim($query);
        if ($q === '') {
            return [];
        }
        $collection = $this->officeCollectionFactory->create();
        $collection->addFieldToFilter('is_active', 1);
        $like = '%' . $this->escapeLikeValue($q) . '%';
        $this->joinCityData($collection);
        $collection->addFieldToFilter(
            ['main_table.name', 'main_table.address', 'main_table.city'],
            [
                ['like' => $like],
                ['like' => $like],
                ['like' => $like],
            ]
        );
        $collection->setPageSize($limit);
        $items = [];
        foreach ($collection as $item) {
            $items[] = [
                'id' => (int) $item->getId(),
                'name' => (string) $item->getName(),
                'address' => (string) $item->getAddress(),
                'city' => (string) ($item->getData('city_resolved') ?? ''),
                'postcode' => (string) ($item->getData('postcode_resolved') ?? ''),
                'type' => ConfigInterface::DELIVERY_TYPE_OFFICE,
            ];
        }
        return $items;
    }

    /**
     * @inheritdoc
     */
    public function searchAps(string $query, int $storeId = 0, int $limit = 0): array
    {
        $limit = $limit > 0 ? $limit : $this->getSearchLimit($storeId);
        $q = trim($query);
        if ($q === '') {
            return [];
        }
        $collection = $this->apsCollectionFactory->create();
        $collection->addFieldToFilter('is_active', 1);
        $like = '%' . $this->escapeLikeValue($q) . '%';
        $this->joinCityData($collection);
        $collection->addFieldToFilter(
            ['main_table.name', 'main_table.address', 'main_table.city'],
            [
                ['like' => $like],
                ['like' => $like],
                ['like' => $like],
            ]
        );
        $collection->setPageSize($limit);
        $items = [];
        foreach ($collection as $item) {
            $items[] = [
                'id' => (int) $item->getId(),
                'name' => (string) $item->getName(),
                'address' => (string) $item->getAddress(),
                'city' => (string) ($item->getData('city_resolved') ?? ''),
                'postcode' => (string) ($item->getData('postcode_resolved') ?? ''),
                'type' => ConfigInterface::DELIVERY_TYPE_APS,
            ];
        }
        return $items;
    }

    /**
     * @inheritdoc
     */
    public function search(string $type, string $query, int $storeId = 0, int $limit = 0): array
    {
        if ($type === ConfigInterface::DELIVERY_TYPE_OFFICE) {
            return $this->searchOffices($query, $storeId, $limit);
        }
        if ($type === ConfigInterface::DELIVERY_TYPE_APS) {
            return $this->searchAps($query, $storeId, $limit);
        }
        return [];
    }

    /**
     * Get city name for a given office or APS entity by entity_id.
     * Returns city name (Bulgarian) or null if not found.
     *
     * @param string $deliveryType office|aps
     * @param string $entityId     entity_id from pigeonexpress_office or pigeonexpress_aps
     * @return string|null
     */
    public function getCityByEntityId(string $deliveryType, string $entityId): ?string
    {
        $entityId = trim($entityId);
        if ($entityId === '' || $entityId === '0') {
            return null;
        }

        if ($deliveryType === ConfigInterface::DELIVERY_TYPE_OFFICE) {
            $collection = $this->officeCollectionFactory->create();
        } elseif ($deliveryType === ConfigInterface::DELIVERY_TYPE_APS) {
            $collection = $this->apsCollectionFactory->create();
        } else {
            return null;
        }

        $this->joinCityData($collection);
        $collection->getSelect()->where('main_table.entity_id = ?', (int) $entityId);
        $collection->setPageSize(1);

        $item = $collection->getFirstItem();
        if (!$item || !$item->getId()) {
            return null;
        }

        $city = (string) ($item->getData('city_resolved') ?? '');
        return $city !== '' ? $city : null;
    }

    /**
     * Get configured autocomplete result limit.
     *
     * @param int $storeId
     * @return int
     */
    private function getSearchLimit(int $storeId): int
    {
        $limit = (int) $this->config->getLocationSearchLimit($storeId);
        return $limit > 0 ? min($limit, 50) : 20;
    }
}
