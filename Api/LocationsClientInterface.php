<?php
/**
 * Pigeon Express Locations API client interface.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Api;

/**
 * Fetches Offices and APS locations (via SDK).
 */
interface LocationsClientInterface
{
    /**
     * Fetch all offices from API.
     *
     * @return array[] Each item: id, code, name, city_id, address, type, latitude, longitude, working_hours
     * @throws \PigeonExpress\Shipping\Exception\ApiException
     */
    public function fetchOffices(): array;

    /**
     * Fetch all APS (locker) locations from API.
     *
     * @return array[] Each item: id, code, name, city_id, address, type, latitude, longitude, working_hours
     * @throws \PigeonExpress\Shipping\Exception\ApiException
     */
    public function fetchAps(): array;

    /**
     * Fetch all cities from API (all pages).
     *
     * @return array[] Each item: id, name, name_en, postal_code
     * @throws \PigeonExpress\Shipping\Exception\ApiException
     */
    public function fetchCities(): array;

    /**
     * The offices or parcel lockers closest to a point, nearest first
     * (GET /offices/nearest), straight from Pigeon Express: no cache, no
     * throttle and no check against the local tables (the caller does those).
     * Locations without coordinates are never in the answer; in the test
     * environment that means no locker. Coordinates are never logged.
     *
     * Rows: `id` is the Pigeon Express id (the api_id of pigeonexpress_office
     * / pigeonexpress_aps, see LocationSearch::rowsByApiIds()), `distance_km`
     * the straight-line distance or null when Pigeon Express left it out.
     *
     * 5 second timeout.
     *
     * @param string $type 'office' or 'aps' (also 'locker' or 'all')
     * @param int $limit 1 to 20, kept within those bounds
     * @return array<int, array{id: int, code: string, type: string, name: string, distance_km: float|null, latitude: float|null, longitude: float|null}>
     * @throws \PigeonExpress\Shipping\Exception\RateLimitedException On a 429 (getHttpStatus() 429)
     * @throws \PigeonExpress\Shipping\Exception\ApiException For a bad point or type (status 0) or any other failure
     */
    public function nearest(float $latitude, float $longitude, string $type, int $limit = 20): array;
}
