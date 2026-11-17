<?php
/**
 * Rows of pigeonexpress_return_shipment. One live row (status created) per
 * order; a cancelled row stays for the record.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\ReturnShipment;

use Magento\Framework\App\ResourceConnection;

class ReturnShipmentRepository
{
    public const TABLE = 'pigeonexpress_return_shipment';
    public const STATUS_CREATED = 'created';
    public const STATUS_CANCELLED = 'cancelled';

    /** @var ResourceConnection */
    private $resource;

    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    /**
     * The live return shipment of an order, or null.
     *
     * @return array<string, mixed>|null
     */
    public function findActive(int $orderId): ?array
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->table())
                ->where('order_id = ?', $orderId)
                ->where('status = ?', self::STATUS_CREATED)
                ->order('entity_id DESC')
                ->limit(1)
        );

        return $row ? $this->decode($row) : null;
    }

    public function isCancelled(int $orderId, string $reference): bool
    {
        $connection = $this->resource->getConnection();

        return (bool) $connection->fetchOne(
            $connection->select()
                ->from($this->table(), ['entity_id'])
                ->where('order_id = ?', $orderId)
                ->where('reference_number = ?', $reference)
                ->where('status = ?', self::STATUS_CANCELLED)
                ->limit(1)
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    public function insert(array $row): int
    {
        $row += ['status' => self::STATUS_CREATED];
        foreach (['payload', 'sent_json', 'details_json'] as $json) {
            if (isset($row[$json]) && is_array($row[$json])) {
                $row[$json] = (string) json_encode($row[$json], JSON_UNESCAPED_UNICODE);
            }
        }
        $this->resource->getConnection()->insert($this->table(), $row);

        return (int) $this->resource->getConnection()->lastInsertId($this->table());
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): void
    {
        if (isset($data['details_json']) && is_array($data['details_json'])) {
            $data['details_json'] = (string) json_encode($data['details_json'], JSON_UNESCAPED_UNICODE);
        }
        $this->resource->getConnection()->update($this->table(), $data, ['entity_id = ?' => $id]);
    }

    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decode(array $row): array
    {
        foreach (['payload', 'sent_json', 'details_json'] as $json) {
            $value = isset($row[$json]) ? json_decode((string) $row[$json], true) : null;
            $row[$json] = is_array($value) ? $value : null;
        }

        return $row;
    }
}
