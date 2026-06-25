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
     * @return array[] Each item: id, name, city_id, address, type, latitude, longitude
     * @throws \PigeonExpress\Shipping\Exception\ApiException
     */
    public function fetchOffices(): array;

    /**
     * Fetch all APS (locker) locations from API.
     *
     * @return array[] Each item: id, name, city_id, address, type, latitude, longitude
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
}
