<?php
/**
 * Contract for reading the additional services catalogue from the API.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Api;

interface AdditionalServicesClientInterface
{
    /**
     * Fetch the additional services catalogue from GET /additional-services.
     *
     * The endpoint returns service definitions only. It does not return
     * prices, so nothing in the returned rows may be treated as a fee.
     *
     * @return array<int, array{code:string, name:string, description:?string, input_type:?string, option_group:?string}>
     * @throws \PigeonExpress\Shipping\Exception\ApiException
     */
    public function fetchAll(int $storeId): array;
}
