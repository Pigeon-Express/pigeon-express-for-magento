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
use PigeonExpress\Shipping\Model\Directory\RegionResolver;
use PigeonExpress\Shipping\Model\Environment;
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

    /**
     * @var RegionResolver
     */
    private $regionResolver;

    /**
     * @var Environment
     */
    private $environment;

    /**
     * @var \PigeonExpress\Shipping\Model\Location\WorkingHours
     */
    private $workingHours;

    /**
     * The working hours of a pickup point for the customer, when the
     * delivery type shows them; an empty string otherwise.
     *
     * @param \Magento\Framework\DataObject $item
     */
    private function hoursFor($item, string $type, int $storeId): string
    {
        if (!$this->config->isShowWorkingHours($type, $storeId)) {
            return '';
        }

        return $this->workingHours->summary($item->getData('working_hours'));
    }

    public function __construct(
        OfficeCollectionFactory $officeCollectionFactory,
        ApsCollectionFactory $apsCollectionFactory,
        ConfigInterface $config,
        StoreManagerInterface $storeManager,
        ResourceConnection $resource,
        RegionResolver $regionResolver,
        Environment $environment,
        ?\PigeonExpress\Shipping\Model\Location\WorkingHours $workingHours = null
    ) {
        $this->workingHours = $workingHours ?? new \PigeonExpress\Shipping\Model\Location\WorkingHours();
        $this->officeCollectionFactory = $officeCollectionFactory;
        $this->apsCollectionFactory = $apsCollectionFactory;
        $this->config = $config;
        $this->storeManager = $storeManager;
        $this->resource = $resource;
        $this->regionResolver = $regionResolver;
        $this->environment = $environment;
    }

    /**
     * The Magento region a pickup point stands in, or null if it cannot be
     * placed. Read from the city the location was joined to.
     *
     * @param \Magento\Framework\DataObject $item
     */
    private function regionFor($item): ?int
    {
        return $this->regionResolver->resolve(
            (string) ($item->getData('district_en_resolved') ?? ''),
            (string) ($item->getData('district_resolved') ?? '')
        );
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
            'pe_city.api_id = main_table.city_id AND pe_city.environment = main_table.environment',
            [
                'city_resolved' => 'COALESCE(main_table.city, pe_city.name)',
                'postcode_resolved' => 'COALESCE(main_table.postcode, pe_city.postal_code)',
                // The district the counter stands in, which is the only source
                // of a region for the Magento address. Bulgaria is one of the
                // countries an order cannot be placed without one for.
                'district_resolved' => 'pe_city.district',
                'district_en_resolved' => 'pe_city.district_en',
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
        // Not main_table.city: the sync only ever writes city_id, so that column
        // is NULL on every row and a filter on it can never match. The readable
        // city comes from the joined city table instead.
        $collection->addFieldToFilter(
            ['main_table.name', 'main_table.address', 'pe_city.name'],
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
                'region_id' => $this->regionFor($item),
                'type' => ConfigInterface::DELIVERY_TYPE_OFFICE,
                'hours' => $this->hoursFor($item, ConfigInterface::DELIVERY_TYPE_OFFICE, $storeId),
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
        // Not main_table.city: the sync only ever writes city_id, so that column
        // is NULL on every row and a filter on it can never match. The readable
        // city comes from the joined city table instead.
        $collection->addFieldToFilter(
            ['main_table.name', 'main_table.address', 'pe_city.name'],
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
                'region_id' => $this->regionFor($item),
                'type' => ConfigInterface::DELIVERY_TYPE_APS,
                'hours' => $this->hoursFor($item, ConfigInterface::DELIVERY_TYPE_APS, $storeId),
            ];
        }
        return $items;
    }

    /**
     * @inheritdoc
     */
    public function listByCity(string $type, int $cityId, int $storeId = 0): array
    {
        if ($cityId <= 0) {
            return [];
        }

        if ($type === ConfigInterface::DELIVERY_TYPE_OFFICE) {
            $collection = $this->officeCollectionFactory->create();
        } elseif ($type === ConfigInterface::DELIVERY_TYPE_APS) {
            $collection = $this->apsCollectionFactory->create();
        } else {
            return [];
        }

        $collection->addFieldToFilter('is_active', 1);
        $this->joinCityData($collection);
        $collection->getSelect()->where('main_table.city_id = ?', $cityId);
        $collection->setOrder('main_table.name', 'ASC');

        // No page size on purpose. Every pickup point in the city has to be
        // reachable, and the configured search limit would hide most of Sofia.
        $items = [];
        foreach ($collection as $item) {
            $items[] = [
                'id' => (int) $item->getId(),
                'name' => (string) $item->getName(),
                'address' => (string) $item->getAddress(),
                'city' => (string) ($item->getData('city_resolved') ?? ''),
                'postcode' => (string) ($item->getData('postcode_resolved') ?? ''),
                'region_id' => $this->regionFor($item),
                'type' => $type,
                'hours' => $this->hoursFor($item, $type, $storeId),
            ];
        }

        return $items;
    }

    /**
     * @inheritdoc
     */
    public function searchCitiesWithLocations(string $type, string $query, int $limit = 20): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        if ($type === ConfigInterface::DELIVERY_TYPE_OFFICE) {
            $locationTable = $this->resource->getTableName('pigeonexpress_office');
        } elseif ($type === ConfigInterface::DELIVERY_TYPE_APS) {
            $locationTable = $this->resource->getTableName('pigeonexpress_aps');
        } else {
            return [];
        }

        $connection = $this->resource->getConnection();
        $like = str_replace(['\\', '_', '%'], ['\\\\', '\_', '\%'], $query) . '%';

        $select = $connection->select()
            ->from(
                ['c' => $this->resource->getTableName('pigeonexpress_city')],
                ['api_id', 'name', 'postal_code', 'district', 'district_en']
            )
            ->join(
                ['l' => $locationTable],
                'l.city_id = c.api_id AND l.environment = c.environment AND l.is_active = 1',
                ['count' => 'COUNT(l.entity_id)']
            )
            ->where('c.environment = ?', $this->environment->current())
            ->where('c.name LIKE ? OR c.name_en LIKE ? OR c.postal_code LIKE ?', $like)
            ->group(['c.api_id', 'c.name', 'c.postal_code', 'c.district', 'c.district_en'])
            // Same ordering the admin city picker uses: district capitals are the
            // codes ending in 000, and they are what most orders go to.
            ->order(new \Zend_Db_Expr("(c.postal_code LIKE '%000') DESC"))
            ->order(new \Zend_Db_Expr('CHAR_LENGTH(c.name) ASC'))
            ->order('c.name ASC')
            ->limit($limit > 0 ? min($limit, 50) : 20);

        $rows = [];
        foreach ($connection->fetchAll($select) as $row) {
            $postcode = (string) ($row['postal_code'] ?? '');
            $rows[] = [
                'id' => (int) $row['api_id'],
                'name' => (string) $row['name'],
                'postcode' => $postcode,
                'label' => $postcode !== '' ? $row['name'] . ' (' . $postcode . ')' : (string) $row['name'],
                'count' => (int) $row['count'],
                'region_id' => $this->regionResolver->resolve(
                    isset($row['district_en']) ? (string) $row['district_en'] : null,
                    isset($row['district']) ? (string) $row['district'] : null
                ),
            ];
        }

        return $rows;
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
     * @inheritdoc
     */
    public function listWithCoordinates(string $type, int $storeId = 0): array
    {
        $collection = $this->collectionFor($type);
        if ($collection === null) {
            return [];
        }

        $collection->addFieldToFilter('is_active', 1);
        $collection->addFieldToFilter('main_table.latitude', ['notnull' => true]);
        $collection->addFieldToFilter('main_table.longitude', ['notnull' => true]);
        $this->joinCityData($collection);
        $collection->getSelect()->order(['city_resolved ASC', 'main_table.name ASC']);

        // Unpaged, like listByCity(): a map that drops pins is wrong.
        $items = [];
        foreach ($collection as $item) {
            $point = self::coordinatesOf($item->getData('latitude'), $item->getData('longitude'));
            if ($point === null) {
                continue;
            }
            $items[] = $this->pickerRow($item, $type, $storeId) + $point;
        }

        return $items;
    }

    /**
     * @inheritdoc
     */
    public function rowsByApiIds(string $type, array $apiIds, int $storeId = 0): array
    {
        $ids = [];
        foreach ($apiIds as $apiId) {
            if (is_scalar($apiId) && ctype_digit(trim((string) $apiId)) && (int) $apiId > 0) {
                $ids[(int) $apiId] = (int) $apiId;
            }
        }
        if ($ids === []) {
            return [];
        }

        $collection = $this->collectionFor($type);
        if ($collection === null) {
            return [];
        }

        $collection->addFieldToFilter('is_active', 1);
        $collection->addFieldToFilter('main_table.api_id', ['in' => array_values($ids)]);
        $this->joinCityData($collection);

        $found = [];
        foreach ($collection as $item) {
            $point = self::coordinatesOf($item->getData('latitude'), $item->getData('longitude'));
            $found[(int) $item->getData('api_id')] = $this->pickerRow($item, $type, $storeId)
                + ['api_id' => (int) $item->getData('api_id')]
                + ($point ?? ['latitude' => null, 'longitude' => null]);
        }

        // In the order asked for: nearest first stays nearest first.
        $rows = [];
        foreach ($ids as $apiId) {
            if (isset($found[$apiId])) {
                $rows[] = $found[$apiId];
            }
        }

        return $rows;
    }

    /**
     * @inheritdoc
     */
    public function getApsCode(int $entityId): ?string
    {
        if ($entityId <= 0) {
            return null;
        }

        $collection = $this->apsCollectionFactory->create();
        $collection->addFieldToFilter('main_table.entity_id', $entityId);
        $collection->setPageSize(1);

        $item = $collection->getFirstItem();
        if (!$item instanceof Aps || !$item->getId()) {
            return null;
        }

        return $item->getCode();
    }

    /**
     * A usable map point, or null when the location has none. 0,0 is open sea
     * off Africa: an unfilled pair, not a place.
     *
     * @param mixed $latitude
     * @param mixed $longitude
     * @return array{latitude: float, longitude: float}|null
     */
    public static function coordinatesOf($latitude, $longitude): ?array
    {
        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return null;
        }

        $lat = (float) $latitude;
        $lng = (float) $longitude;

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat === 0.0 && $lng === 0.0)) {
            return null;
        }

        return ['latitude' => $lat, 'longitude' => $lng];
    }

    /**
     * The collection of a location type, or null for an unknown type.
     *
     * @return \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection|null
     */
    private function collectionFor(string $type)
    {
        if ($type === ConfigInterface::DELIVERY_TYPE_OFFICE) {
            return $this->officeCollectionFactory->create();
        }
        if ($type === ConfigInterface::DELIVERY_TYPE_APS) {
            return $this->apsCollectionFactory->create();
        }

        return null;
    }

    /**
     * One location in the row shape the checkout picker uses.
     *
     * @param \Magento\Framework\DataObject $item A row joined with joinCityData()
     * @return array{id: int, name: string, address: string, city: string, postcode: string, region_id: int|null, type: string, hours: string}
     */
    private function pickerRow($item, string $type, int $storeId): array
    {
        return [
            'id' => (int) $item->getId(),
            'name' => (string) $item->getData('name'),
            'address' => (string) $item->getData('address'),
            'city' => (string) ($item->getData('city_resolved') ?? ''),
            'postcode' => (string) ($item->getData('postcode_resolved') ?? ''),
            'region_id' => $this->regionFor($item),
            'type' => $type,
            'hours' => $this->hoursFor($item, $type, $storeId),
        ];
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
