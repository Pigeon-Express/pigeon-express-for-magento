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
}
