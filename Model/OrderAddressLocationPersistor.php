<?php
/**
 * Persist and load Pigeon Express location data for order shipping address.
 * Table: pigeonexpress_order_address (address_id → sales_order_address.entity_id).
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model;

use Magento\Framework\App\ResourceConnection;

class OrderAddressLocationPersistor
{
    private const TABLE = 'pigeonexpress_order_address';

    /**
     * @var ResourceConnection
     */
    private $resource;

    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    /**
     * Save location data for an order address (insert or replace).
     *
     * @param int $addressId sales_order_address.entity_id
     * @param string|null $deliveryType
     * @param string|null $locationId
     * @param string|null $locationName
     * @param string|null $locationAddress
     * @param string|null $instructions
     * @param float|null $deliveryPrice
     * @param bool $freeShipping Delivery was given away rather than merely priced at zero
     */
    public function save(
        int $addressId,
        ?string $deliveryType,
        ?string $locationId,
        ?string $locationName,
        ?string $locationAddress,
        ?string $instructions,
        ?float $deliveryPrice = null,
        bool $freeShipping = false,
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
            'pigeonexpress_delivery_price' => $deliveryPrice !== null ? (string) $deliveryPrice : null,
            'pigeonexpress_free_shipping' => $freeShipping ? 1 : 0,
        ];
        $data += $this->idColumns($cityId, $streetId);

        $conn->insertOnDuplicate($table, $data, array_keys($data));
    }

    /**
     * Load location data by order address id.
     *
     * @param int $addressId
     * @return array{delivery_type: string, location_id: string, location_name: string, location_address: string, instructions: string, city_id: string, street_id: string, delivery_price: float|null, free_shipping: bool}|null
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
                    'delivery_price' => 'pigeonexpress_delivery_price',
                    'free_shipping' => 'pigeonexpress_free_shipping',
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
            'delivery_price' => isset($row['delivery_price']) && $row['delivery_price'] !== null
                ? (float) $row['delivery_price'] : null,
            'free_shipping' => !empty($row['free_shipping']),
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
