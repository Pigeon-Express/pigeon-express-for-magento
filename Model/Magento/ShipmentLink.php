<?php
/**
 * Which Magento shipment holds a Pigeon Express waybill: the
 * magento_shipment_id column of pigeonexpress_shipment.
 *
 * Written straight to the row rather than through the Shipment model, so
 * nothing else on the row (a status read a moment ago by another request) is
 * written back with it.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Magento;

use Magento\Framework\App\ResourceConnection;

class ShipmentLink
{
    public const TABLE = 'pigeonexpress_shipment';
    public const COLUMN = 'magento_shipment_id';

    /** @var ResourceConnection */
    private $resource;

    /** @var bool|null */
    private $available;

    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    /**
     * Whether the column exists. False only on an install whose schema has not
     * been upgraded yet, where the link is then simply not kept.
     */
    public function isAvailable(): bool
    {
        if ($this->available === null) {
            $connection = $this->resource->getConnection();
            $this->available = $connection->tableColumnExists(
                $this->resource->getTableName(self::TABLE),
                self::COLUMN
            );
        }

        return $this->available;
    }

    public function link(int $rowId, int $magentoShipmentId): void
    {
        if ($rowId <= 0 || $magentoShipmentId <= 0 || !$this->isAvailable()) {
            return;
        }

        $this->resource->getConnection()->update(
            $this->resource->getTableName(self::TABLE),
            [self::COLUMN => $magentoShipmentId],
            ['entity_id = ?' => $rowId]
        );
    }

    /**
     * The Magento shipment of this row, read from the database (the event's
     * copy of the row may predate the link).
     */
    public function linkedTo(int $rowId): ?int
    {
        if ($rowId <= 0 || !$this->isAvailable()) {
            return null;
        }

        $connection = $this->resource->getConnection();
        $value = $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE), [self::COLUMN])
                ->where('entity_id = ?', $rowId)
        );

        return $value ? (int) $value : null;
    }

    /**
     * Magento shipments that earlier waybills of the order were put in, newest
     * first.
     *
     * @return int[]
     */
    public function linkedForOrder(int $orderId, int $exceptRowId = 0): array
    {
        if ($orderId <= 0 || !$this->isAvailable()) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::TABLE), [self::COLUMN])
            ->where('order_id = ?', $orderId)
            ->where(self::COLUMN . ' IS NOT NULL')
            ->where('entity_id <> ?', $exceptRowId)
            ->order('entity_id DESC');

        return array_values(array_unique(array_map('intval', $connection->fetchCol($select))));
    }
}
