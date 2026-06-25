<?php
/**
 * Pigeon Express Location Sync interface.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Api;

/**
 * Sync Offices and APS from API to DB (upsert + deactivate removed).
 */
interface LocationSyncInterface
{
    /**
     * Sync offices from API.
     *
     * @return array{created: int, updated: int, deactivated: int}
     * @throws \PigeonExpress\Shipping\Exception\ApiException
     */
    public function syncOffices(): array;

    /**
     * Sync APS (lockers) from API.
     *
     * @return array{created: int, updated: int, deactivated: int}
     * @throws \PigeonExpress\Shipping\Exception\ApiException
     */
    public function syncAps(): array;

    /**
     * Sync cities from API.
     *
     * @return array{created: int, updated: int}
     * @throws \PigeonExpress\Shipping\Exception\ApiException
     */
    public function syncCities(): array;

    /**
     * Sync offices, APS, and cities.
     *
     * @return array{offices: array, aps: array, cities: array}
     * @throws \PigeonExpress\Shipping\Exception\ApiException
     */
    public function syncAll(): array;
}
