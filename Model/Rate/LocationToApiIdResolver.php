<?php
/**
 * Resolves local entity_id (Office/APS) to Pigeon Express API office/locker id for rate payload.
 *
 * Checkout stores entity_id from LocationSearch; API expects api_id from pigeonexpress_office / pigeonexpress_aps.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Rate;

use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\ResourceModel\Office\CollectionFactory as OfficeCollectionFactory;
use PigeonExpress\Shipping\Model\ResourceModel\Aps\CollectionFactory as ApsCollectionFactory;

class LocationToApiIdResolver
{
    /** @var OfficeCollectionFactory */
    private $officeCollectionFactory;

    /** @var ApsCollectionFactory */
    private $apsCollectionFactory;

    public function __construct(
        OfficeCollectionFactory $officeCollectionFactory,
        ApsCollectionFactory $apsCollectionFactory
    ) {
        $this->officeCollectionFactory = $officeCollectionFactory;
        $this->apsCollectionFactory = $apsCollectionFactory;
    }

    /**
     * Resolve delivery location entity_id to PE API id (for delivery_office_id in rate request).
     *
     * @param string $deliveryType 'office' or 'aps'
     * @param string $entityId     entity_id from quote (pigeonexpress_office.entity_id or pigeonexpress_aps.entity_id)
     * @return int|null API id, or null if not found
     */
    public function resolveToApiId(string $deliveryType, string $entityId): ?int
    {
        $entityId = trim($entityId);
        if ($entityId === '') {
            return null;
        }
        $id = (int) $entityId;
        if ($id <= 0) {
            return null;
        }

        if ($deliveryType === ConfigInterface::DELIVERY_TYPE_APS) {
            $collection = $this->apsCollectionFactory->create();
            $collection->addFieldToFilter('entity_id', ['eq' => $id]);
            $collection->setPageSize(1);
            $item = $collection->getFirstItem();
            if (!$item || !$item->getId()) {
                return null;
            }
            return $item->getApiId();
        }

        if ($deliveryType === ConfigInterface::DELIVERY_TYPE_OFFICE) {
            $collection = $this->officeCollectionFactory->create();
            $collection->addFieldToFilter('entity_id', ['eq' => $id]);
            $collection->setPageSize(1);
            $item = $collection->getFirstItem();
            if (!$item || !$item->getId()) {
                return null;
            }
            return $item->getApiId();
        }

        return null;
    }

    /**
     * Load an active office or locker by its local entity_id.
     *
     * Used to validate what checkout sends us and to take the authoritative
     * name, address, city and postcode from our own synced data rather than
     * from request input.
     *
     * @param string $deliveryType 'office' or 'aps'
     * @return array{entity_id:int, api_id:int, name:string, address:string, city:string, postcode:string}|null
     */
    public function resolveLocation(string $deliveryType, string $entityId): ?array
    {
        // Strict parse. This value arrives from checkout, and a lenient cast would
        // quietly turn junk like "1 OR 1=1" into the id 1. The query binds
        // parameters so there is no injection either way, but silently resolving
        // malformed input to a real office is not behaviour worth keeping.
        $entityId = trim($entityId);
        if ($entityId === '' || !ctype_digit($entityId)) {
            return null;
        }

        $id = (int) $entityId;
        if ($id <= 0) {
            return null;
        }

        if ($deliveryType === ConfigInterface::DELIVERY_TYPE_APS) {
            $collection = $this->apsCollectionFactory->create();
        } elseif ($deliveryType === ConfigInterface::DELIVERY_TYPE_OFFICE) {
            $collection = $this->officeCollectionFactory->create();
        } else {
            return null;
        }

        $resource = $collection->getResource();
        $connection = $resource->getConnection();
        $cityTable = $connection->getTableName('pigeonexpress_city');

        // The location sync leaves city and postcode empty on offices and
        // lockers, but always fills city_id, so the readable city comes from the
        // city table. Join rather than trusting whatever checkout posted.
        $select = $connection->select()
            ->from(['l' => $resource->getMainTable()], ['entity_id', 'api_id', 'name', 'address'])
            ->joinLeft(
                ['c' => $cityTable],
                'c.api_id = l.city_id',
                ['city_name' => 'name', 'city_postal_code' => 'postal_code']
            )
            ->where('l.entity_id = ?', $id)
            ->where('l.is_active = ?', 1)
            ->limit(1);

        $row = $connection->fetchRow($select);
        if (!$row || empty($row['entity_id'])) {
            return null;
        }

        return [
            'entity_id' => (int) $row['entity_id'],
            'api_id'    => (int) $row['api_id'],
            'name'      => (string) ($row['name'] ?? ''),
            'address'   => (string) ($row['address'] ?? ''),
            'city'      => (string) ($row['city_name'] ?? ''),
            'postcode'  => (string) ($row['city_postal_code'] ?? ''),
        ];
    }

    /**
     * Get api_id of the first active office from DB (used as placeholder for rate calculation).
     */
    public function getFirstActiveOfficeApiId(): ?int
    {
        $collection = $this->officeCollectionFactory->create();
        $collection->addFieldToFilter('is_active', 1);
        $collection->setPageSize(1);
        $item = $collection->getFirstItem();
        return ($item && $item->getId()) ? $item->getApiId() : null;
    }

    /**
     * Get api_id of the first active APS from DB (used as placeholder for rate calculation).
     */
    public function getFirstActiveApsApiId(): ?int
    {
        $collection = $this->apsCollectionFactory->create();
        $collection->addFieldToFilter('is_active', 1);
        $collection->setPageSize(1);
        $item = $collection->getFirstItem();
        return ($item && $item->getId()) ? $item->getApiId() : null;
    }
}
