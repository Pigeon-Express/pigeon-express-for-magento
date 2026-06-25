<?php
/**
 * Persist and load Pigeon Express location data for quote shipping address.
 * Table: pigeonexpress_quote_address (address_id → quote_address.address_id).
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model;

use Magento\Framework\App\ResourceConnection;

class QuoteAddressLocationPersistor
{
    private const TABLE = 'pigeonexpress_quote_address';

    /**
     * @var ResourceConnection
     */
    private $resource;

    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    /**
     * Save location data for a quote address (insert or replace).
     *
     * @param int $addressId quote_address.address_id
     */
    public function save(
        int $addressId,
        ?string $deliveryType,
        ?string $locationId,
        ?string $locationName,
        ?string $locationAddress,
        ?string $instructions
    ): void {
        $conn = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);

        $data = [
            'address_id' => $addressId,
            'pigeonexpress_delivery_type' => $deliveryType ?? '',
            'pigeonexpress_location_id' => $locationId ?? '',
            'pigeonexpress_location_name' => $locationName ?? '',
            'pigeonexpress_location_address' => $locationAddress ?? '',
            'pigeonexpress_instructions' => $instructions ?? '',
        ];

        $conn->insertOnDuplicate($table, $data, array_keys($data));
    }

    /**
     * Load location data by quote address id.
     *
     * @param int $addressId
     * @return array{delivery_type: string, location_id: string, location_name: string, location_address: string, instructions: string}|null
     */
    public function getByAddressId(int $addressId): ?array
    {
        $conn = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);

        $row = $conn->fetchRow(
            $conn->select()
                ->from($table, [
                    'delivery_type' => 'pigeonexpress_delivery_type',
                    'location_id' => 'pigeonexpress_location_id',
                    'location_name' => 'pigeonexpress_location_name',
                    'location_address' => 'pigeonexpress_location_address',
                    'instructions' => 'pigeonexpress_instructions',
                ])
                ->where('address_id = ?', $addressId)
        );

        if ($row === false) {
            return null;
        }

        return [
            'delivery_type' => (string) $row['delivery_type'],
            'location_id' => (string) $row['location_id'],
            'location_name' => (string) $row['location_name'],
            'location_address' => (string) $row['location_address'],
            'instructions' => (string) $row['instructions'],
        ];
    }
}
