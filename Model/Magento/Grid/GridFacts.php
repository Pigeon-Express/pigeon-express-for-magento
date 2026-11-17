<?php
/**
 * What the orders grid reads beside its own table: open courier requests
 * (one SQL condition for the "waiting for courier" filter, one read for the
 * rows of a page, for "Courier requested" under the waybill) and the
 * shipping method of the orders on a page.
 *
 * Open means not in a final status (completed, cancelled, failed) and made
 * in the environment the shop is in now. Only the waybill a request holds
 * counts: a request for a waybill since cancelled and replaced does not
 * speak for the new one, as in WooCommerce (CourierRequestRepository).
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Magento\Grid;

use Magento\Framework\App\ResourceConnection;
use PigeonExpress\Shipping\Model\Courier\CourierRequestRepository;
use PigeonExpress\Shipping\Model\Environment;

class GridFacts
{
    /** @var ResourceConnection */
    private $resource;

    /** @var Environment */
    private $environment;

    public function __construct(ResourceConnection $resource, Environment $environment)
    {
        $this->resource = $resource;
        $this->environment = $environment;
    }

    /**
     * SQL that is true when an open request holds the waybill in this column.
     *
     * @param string $referenceColumn For example "pe.reference_number"
     */
    public function openCondition(string $referenceColumn): string
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['pecrs' => $this->resource->getTableName(CourierRequestRepository::TABLE_SHIPMENTS)], [])
            ->join(
                ['pecr' => $this->resource->getTableName(CourierRequestRepository::TABLE)],
                'pecr.entity_id = pecrs.request_id',
                [new \Zend_Db_Expr('1')]
            )
            ->where('pecrs.reference_number = ' . $referenceColumn)
            ->where('pecr.environment = ?', $this->environment->current())
            ->where(
                'pecr.status_code IS NULL OR pecr.status_code NOT IN (?)',
                CourierRequestRepository::FINAL_CODES
            );

        return 'EXISTS (' . $select . ')';
    }

    /**
     * The newest open request for each of these waybills.
     *
     * @param string[] $references
     * @return array<string, array<string, mixed>> reference => request row
     */
    public function openFor(array $references): array
    {
        $references = array_values(array_unique(array_filter(array_map('strval', $references))));
        if ($references === []) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from(['pecrs' => $this->resource->getTableName(CourierRequestRepository::TABLE_SHIPMENTS)], [
                    'reference_number',
                ])
                ->join(
                    ['pecr' => $this->resource->getTableName(CourierRequestRepository::TABLE)],
                    'pecr.entity_id = pecrs.request_id',
                    ['request_number', 'status_code', 'pickup_date', 'time_type', 'time_from', 'time_to']
                )
                ->where('pecrs.reference_number IN (?)', $references)
                ->where('pecr.environment = ?', $this->environment->current())
                ->where(
                    'pecr.status_code IS NULL OR pecr.status_code NOT IN (?)',
                    CourierRequestRepository::FINAL_CODES
                )
                ->order('pecr.entity_id ASC')
        );

        $out = [];
        foreach ($rows as $row) {
            // Ascending, so the newest request is the one kept.
            $out[(string) $row['reference_number']] = $row;
        }

        return $out;
    }

    /**
     * Shipping method of each of these orders.
     *
     * @param int[] $orderIds
     * @return array<int, string>
     */
    public function shippingMethods(array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
        if ($orderIds === []) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $pairs = $connection->fetchPairs(
            $connection->select()
                ->from($this->resource->getTableName('sales_order'), ['entity_id', 'shipping_method'])
                ->where('entity_id IN (?)', $orderIds)
        );

        $out = [];
        foreach ($pairs as $id => $method) {
            $out[(int) $id] = (string) $method;
        }

        return $out;
    }
}
