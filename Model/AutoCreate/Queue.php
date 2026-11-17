<?php
/**
 * Orders waiting for an automatic shipment.
 *
 * A table rather than the message queue: queue consumers need exec(), which
 * this kind of host often disables, while the cron group runs in process.
 * One row per order, ever: the order id is the primary key and rows are
 * never deleted while the order exists, so an order that leaves Processing
 * and comes back is not queued a second time, exactly as WooCommerce marks
 * an order once. A row is claimed with a conditional update, so two cron
 * runs cannot both take it.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\AutoCreate;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

class Queue
{
    public const TABLE = 'pigeonexpress_auto_create';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE = 'done';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';
    public const STATUS_UNKNOWN = 'unknown';

    /** A run that has not finished after this long died; its row is freed. */
    private const STALE_RUNNING_SECONDS = 900;

    /** @var ResourceConnection */
    private $resource;

    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    /**
     * Queue an order. False when it was queued before, whatever became of it.
     */
    public function add(int $orderId): bool
    {
        if ($orderId <= 0) {
            return false;
        }

        return $this->connection()->insertArray(
            $this->table(),
            ['order_id', 'status'],
            [[$orderId, self::STATUS_PENDING]],
            AdapterInterface::INSERT_IGNORE
        ) > 0;
    }

    /**
     * Orders due now, oldest first.
     *
     * @return int[]
     */
    public function due(int $limit = 20): array
    {
        $connection = $this->connection();

        // A run that crashed half way would hold its row for ever.
        $connection->update(
            $this->table(),
            ['status' => self::STATUS_UNKNOWN, 'message' => 'The run did not finish.'],
            [
                'status = ?' => self::STATUS_RUNNING,
                'updated_at < NOW() - INTERVAL ' . self::STALE_RUNNING_SECONDS . ' SECOND',
            ]
        );

        $select = $connection->select()
            ->from($this->table(), ['order_id'])
            ->where('status = ?', self::STATUS_PENDING)
            // The database clock on both sides, whatever the PHP time zone.
            ->where('run_after <= NOW()')
            ->order('queued_at ASC')
            ->limit($limit);

        return array_map('intval', $connection->fetchCol($select));
    }

    /**
     * Take an order for this run. False when another run took it first.
     */
    public function claim(int $orderId): bool
    {
        return $this->connection()->update(
            $this->table(),
            ['status' => self::STATUS_RUNNING, 'attempts' => new \Zend_Db_Expr('attempts + 1')],
            ['order_id = ?' => $orderId, 'status = ?' => self::STATUS_PENDING]
        ) === 1;
    }

    /**
     * Record how the run ended.
     */
    public function finish(int $orderId, string $status, string $message = ''): void
    {
        $this->connection()->update(
            $this->table(),
            ['status' => $status, 'message' => $message !== '' ? $message : null],
            ['order_id = ?' => $orderId]
        );
    }

    /**
     * Put an order back for a later attempt.
     */
    public function retryLater(int $orderId, int $delaySeconds, string $message): void
    {
        $this->connection()->update(
            $this->table(),
            [
                'status'    => self::STATUS_PENDING,
                'run_after' => new \Zend_Db_Expr('NOW() + INTERVAL ' . max(0, $delaySeconds) . ' SECOND'),
                'message'   => $message,
            ],
            ['order_id = ?' => $orderId]
        );
    }

    /**
     * Attempts made so far.
     */
    public function attempts(int $orderId): int
    {
        $connection = $this->connection();

        return (int) $connection->fetchOne(
            $connection->select()->from($this->table(), ['attempts'])->where('order_id = ?', $orderId)
        );
    }

    private function connection(): AdapterInterface
    {
        return $this->resource->getConnection();
    }

    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }
}
