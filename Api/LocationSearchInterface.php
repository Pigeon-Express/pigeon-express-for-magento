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
}
