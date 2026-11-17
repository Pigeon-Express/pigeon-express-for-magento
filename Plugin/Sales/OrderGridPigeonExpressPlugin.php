<?php
/**
 * Adds the newest Pigeon Express waybill of each order to the Sales > Orders
 * grid collection, for the "Pigeon Express" column and its filter.
 *
 * Joined when the grid is read, not copied into sales_order_grid. That table
 * is refreshed only when the order itself is saved (or by the asynchronous
 * grid indexer), and a shipment status changes without the order being saved:
 * copied columns would show yesterday's status until something else touched
 * the order. A join is always current and needs no schema change on a core
 * table, nothing to reindex and nothing to clean up on uninstall.
 *
 * Kept light, because Magento runs the same joins again for the row count:
 * the newest waybill comes from one grouped derived table (not a subquery
 * per row), and the order's shipping method and the courier requests, which
 * only the "Pigeon Express" filter needs, are read inside the filter's own
 * expression, so they cost nothing unless that filter is used. The column
 * reads them for the rows of the page instead (Column\PigeonExpress).
 *
 * Hooked on the UI collection factory rather than by replacing the grid
 * collection class, so it works whichever class another extension registers
 * for sales_order_grid_data_source.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Plugin\Sales;

use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\View\Element\UiComponent\DataProvider\CollectionFactory;
use PigeonExpress\Shipping\Model\Magento\Grid\GridFacts;
use PigeonExpress\Shipping\Model\Magento\Grid\StateFilter;
use PigeonExpress\Shipping\Model\Magento\ShipmentLink;
use Psr\Log\LoggerInterface;

class OrderGridPigeonExpressPlugin
{
    public const REQUEST_NAME = 'sales_order_grid_data_source';

    /** Aliases of the joined tables. */
    private const LATEST_ALIAS = 'pigeonexpress_shipment_newest';
    private const SHIPMENT_ALIAS = 'pigeonexpress_shipment_latest';

    /** @var StateFilter */
    private $stateFilter;

    /** @var GridFacts */
    private $gridFacts;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(StateFilter $stateFilter, GridFacts $gridFacts, LoggerInterface $logger)
    {
        $this->stateFilter = $stateFilter;
        $this->gridFacts = $gridFacts;
        $this->logger = $logger;
    }

    /**
     * @param CollectionFactory $subject
     * @param mixed $collection
     * @param string $requestName
     * @return mixed
     */
    public function afterGetReport(CollectionFactory $subject, $collection, $requestName)
    {
        if ($requestName !== self::REQUEST_NAME || !$collection instanceof AbstractDb) {
            return $collection;
        }

        try {
            $this->join($collection);
        } catch (\Throwable $e) {
            // The orders grid must open even if this cannot be added.
            $this->logger->error('[PigeonExpress] Orders grid join failed: ' . $e->getMessage());
        }

        return $collection;
    }

    private function join(AbstractDb $collection): void
    {
        $connection = $collection->getConnection();
        $shipmentTable = $collection->getTable(ShipmentLink::TABLE);
        $hasStatus = $connection->tableColumnExists($shipmentTable, 'status_code');
        $pe = self::SHIPMENT_ALIAS;
        $newest = self::LATEST_ALIAS;

        // The newest row per order, worked out once for the table rather
        // than once per order row.
        $latest = $connection->select()
            ->from($shipmentTable, ['order_id', 'entity_id' => new \Zend_Db_Expr('MAX(entity_id)')])
            ->group('order_id');

        $columns = [
            'pigeonexpress_reference' => $pe . '.reference_number',
            'pigeonexpress_lifecycle' => $pe . '.status',
        ];
        if ($hasStatus) {
            $columns['pigeonexpress_status_code'] = $pe . '.status_code';
            $columns['pigeonexpress_status_name'] = $pe . '.status_name';
            $columns['pigeonexpress_status_at'] = $pe . '.status_at';
        }

        $select = $collection->getSelect();
        $select->joinLeft([$newest => $latest], $newest . '.order_id = main_table.entity_id', []);
        $select->joinLeft([$pe => $shipmentTable], $pe . '.entity_id = ' . $newest . '.entity_id', $columns);

        // Only the filter reads the order's method (a primary key lookup) and
        // the courier requests; neither is joined for the page or the count.
        $method = '(SELECT pigeonexpress_order.shipping_method FROM '
            . $connection->quoteIdentifier($collection->getTable('sales_order'))
            . ' AS pigeonexpress_order WHERE pigeonexpress_order.entity_id = main_table.entity_id)';
        $state = new \Zend_Db_Expr($this->stateFilter->expression(
            $pe,
            $method,
            $hasStatus,
            $this->gridFacts->openCondition($pe . '.reference_number')
        ));

        // Filtering on the column filters on the expression, not on an alias
        // WHERE cannot see.
        $collection->addFilterToMap(StateFilter::FIELD, $state);
    }
}
