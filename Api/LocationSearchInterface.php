<?php
/**
 * Location search for Office/APS autocomplete (local DB only).
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Api;

interface LocationSearchInterface
{
    /**
     * Search offices by query (name/address). Active only. No external API.
     *
     * @param string $query Search term
     * @param int $storeId Store for scope (unused in Stage 1; for i18n later)
     * @param int $limit Max results (default from config)
     * @return array<int, array{id: int, name: string, address: string, type: string}>
     */
    public function searchOffices(string $query, int $storeId = 0, int $limit = 0): array;

    /**
     * Search APS by query (name/address). Active only. No external API.
     *
     * @param string $query Search term
     * @param int $storeId Store for scope (unused in Stage 1; for i18n later)
     * @param int $limit Max results (default from config)
     * @return array<int, array{id: int, name: string, address: string, type: string}>
     */
    public function searchAps(string $query, int $storeId = 0, int $limit = 0): array;

    /**
     * Search offices or APS by type. Used by checkout autocomplete endpoint.
     *
     * @param string $type 'office' or 'aps'
     * @param string $query Search term
     * @param int $storeId Store ID
     * @param int $limit Max results (0 = use config)
     * @return array<int, array{id: int, name: string, address: string, type: string}>
     */
    public function search(string $type, string $query, int $storeId = 0, int $limit = 0): array;

    /**
     * Every active office or locker in one city.
     *
     * Deliberately unpaged. A customer choosing a pickup point has to be able to
     * see all of them: Sofia alone holds 43 offices and 84 lockers, and the
     * search limit would silently drop half of them.
     *
     * @param string $type 'office' or 'aps'
     * @param int $cityId Pigeon Express city id, as stored in city_id
     * @return array<int, array{id: int, name: string, address: string, city: string, postcode: string, type: string}>
     */
    public function listByCity(string $type, int $cityId, int $storeId = 0): array;

    /**
     * Cities that actually have somewhere to collect from.
     *
     * The city table holds every settlement in the country, and only a fraction
     * of them have an office or a locker. Offering the rest would send customers
     * to an empty list.
     *
     * @param string $type 'office' or 'aps'
     * @param string $query Name, Latin name or postal code prefix
     * @return array<int, array{id: int, name: string, postcode: string, label: string, count: int}>
     */
    public function searchCitiesWithLocations(string $type, string $query, int $limit = 20): array;

    /**
     * Every active office or locker that has map coordinates, by city then
     * name, in the picker row shape plus the point. Unpaged. Rows without
     * coordinates (all lockers in the test environment) or with 0,0 are left
     * out, so an empty list means there is nothing to put on a map.
     *
     * @param string $type 'office' or 'aps'
     * @return array<int, array{id: int, name: string, address: string, city: string, postcode: string, region_id: int|null, type: string, hours: string, latitude: float, longitude: float}>
     */
    public function listWithCoordinates(string $type, int $storeId = 0): array;

    /**
     * The active locations of a type with these Pigeon Express ids (api_id),
     * in the current environment, in the order the ids were given (a nearest
     * search keeps its order). Ids not in the table, inactive or of the other
     * environment are left out. Picker row shape plus `api_id` and the point
     * (latitude / longitude null when the row has none).
     *
     * @param string $type 'office' or 'aps'
     * @param array<int, int|string> $apiIds
     * @return array<int, array{id: int, name: string, address: string, city: string, postcode: string, region_id: int|null, type: string, hours: string, api_id: int, latitude: float|null, longitude: float|null}>
     */
    public function rowsByApiIds(string $type, array $apiIds, int $storeId = 0): array;

    /**
     * The Pigeon Express code of a locker by its entity_id (the id the
     * checkout and the order store), or null when the current environment has
     * no such locker or the sync has not filled the code. Inactive lockers
     * keep their code. Free boxes are looked up by it
     * (ShipmentClientInterface::lockerAvailability()).
     */
    public function getApsCode(int $entityId): ?string;
}
