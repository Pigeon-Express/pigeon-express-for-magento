<?php
/**
 * The courier requests made from this shop, and the shipments in each. The
 * API cannot list them, so this is the only list there is. Reads are for the
 * environment the shop is in now.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Courier;

use Magento\Framework\App\ResourceConnection;
use PigeonExpress\Shipping\Model\Environment;

class CourierRequestRepository
{
    public const TABLE = 'pigeonexpress_courier_request';
    public const TABLE_SHIPMENTS = 'pigeonexpress_courier_request_shipment';

    /** Statuses after which the request is over. */
    public const FINAL_CODES = ['courier_request_completed', 'courier_request_cancelled', 'courier_request_failed'];

    /** @var ResourceConnection */
    private $resource;

    /** @var Environment */
    private $environment;

    public function __construct(ResourceConnection $resource, Environment $environment)
    {
        $this->resource = $resource;
        $this->environment = $environment;
    }

    public static function isFinal(string $code): bool
    {
        return in_array($code, self::FINAL_CODES, true);
    }

    /**
     * A request by its number, with its shipments as reference => order id.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $number): ?array
    {
        if ($number === '') {
            return null;
        }
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE))
                ->where('request_number = ?', $number)
                ->where('environment = ?', $this->environment->current())
                ->limit(1)
        );
        if (!$row) {
            return null;
        }
        $row['shipments'] = $this->shipmentsOf((int) $row['entity_id']);

        return $row;
    }

    public function findById(int $id): ?array
    {
        $connection = $this->resource->getConnection();
        $number = $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE), ['request_number'])
                ->where('entity_id = ?', $id)
        );

        return is_string($number) ? $this->find($number) : null;
    }

    /**
     * @return array<string, int> reference => order id
     */
    public function shipmentsOf(int $requestId): array
    {
        $connection = $this->resource->getConnection();
        $pairs = $connection->fetchPairs(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE_SHIPMENTS), ['reference_number', 'order_id'])
                ->where('request_id = ?', $requestId)
                ->order('entity_id ASC')
        );

        return array_map('intval', $pairs);
    }

    /**
     * The newest request holding this shipment, or null.
     *
     * @return array<string, mixed>|null
     */
    public function forReference(string $reference): ?array
    {
        if ($reference === '') {
            return null;
        }
        $connection = $this->resource->getConnection();
        $number = $connection->fetchOne(
            $connection->select()
                ->from(['s' => $this->resource->getTableName(self::TABLE_SHIPMENTS)], [])
                ->join(['r' => $this->resource->getTableName(self::TABLE)], 'r.entity_id = s.request_id', ['request_number'])
                ->where('s.reference_number = ?', $reference)
                ->where('r.environment = ?', $this->environment->current())
                ->order('r.entity_id DESC')
                ->limit(1)
        );

        return is_string($number) && $number !== '' ? $this->find($number) : null;
    }

    /**
     * Insert a request with its shipments, or update the one with that number.
     *
     * @param array<string, mixed> $request
     * @return bool Whether it was new
     */
    public function save(array $request): bool
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $known = $this->find((string) $request['number']);
        $columns = array_intersect_key($request, array_flip([
            'status_code', 'status_name', 'pickup_date', 'time_type', 'time_from', 'time_to',
            'address', 'request_type', 'admin_user_id', 'checked_at',
        ]));

        if ($known !== null) {
            $connection->update($table, $columns, ['entity_id = ?' => (int) $known['entity_id']]);

            return false;
        }

        $connection->beginTransaction();
        try {
            $connection->insert($table, $columns + [
                'request_number' => (string) $request['number'],
                'environment'    => $this->environment->current(),
            ]);
            $id = (int) $connection->lastInsertId($table);
            foreach ((array) ($request['shipments'] ?? []) as $reference => $orderId) {
                $connection->insert($this->resource->getTableName(self::TABLE_SHIPMENTS), [
                    'request_id'       => $id,
                    'order_id'         => (int) $orderId,
                    'reference_number' => (string) $reference,
                ]);
            }
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateStatus(string $number, array $data): void
    {
        $this->resource->getConnection()->update(
            $this->resource->getTableName(self::TABLE),
            array_intersect_key($data, array_flip(['status_code', 'status_name', 'checked_at'])),
            ['request_number = ?' => $number, 'environment = ?' => $this->environment->current()]
        );
    }
}
