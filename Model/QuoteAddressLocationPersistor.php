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
        ?string $instructions,
        ?string $cityId = null,
        ?string $streetId = null
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
        $data += $this->idColumns($cityId, $streetId);

        $conn->insertOnDuplicate($table, $data, array_keys($data));
    }

    /**
     * Load location data by quote address id.
     *
     * @param int $addressId
     * @return array{delivery_type: string, location_id: string, location_name: string, location_address: string, instructions: string, city_id: string, street_id: string}|null
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
                    'city_id' => 'pigeonexpress_city_id',
                    'street_id' => 'pigeonexpress_street_id',
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
            'city_id' => $row['city_id'] !== null ? (string) $row['city_id'] : '',
            'street_id' => $row['street_id'] !== null ? (string) $row['street_id'] : '',
        ];
    }

    /**
     * The id columns to write: an id as a number, '' as NULL (cleared), and
     * nothing at all for null, so a save that does not know them (choosing an
     * office) leaves what the address step stored.
     *
     * @return array<string, int|null>
     */
    private function idColumns(?string $cityId, ?string $streetId): array
    {
        $out = [];
        foreach (['pigeonexpress_city_id' => $cityId, 'pigeonexpress_street_id' => $streetId] as $column => $value) {
            if ($value === null) {
                continue;
            }
            $value = trim($value);
            $out[$column] = ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
        }

        return $out;
    }
}
