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
