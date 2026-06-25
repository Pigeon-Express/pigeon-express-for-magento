<?php
/**
 * Rate calculation client interface.
 *
 * @copyright Copyright (c). All rights reserved.
 */
declare(strict_types=1);

namespace PigeonExpress\Shipping\Api;

use PigeonExpress\Shipping\Exception\ApiException;

interface RateClientInterface
{
    /**
     * Calculate shipping via /shipments/calculate.
     *
     * @param array $payload
     * @param int $storeId
     * @return array{shipping_price: float, total_price: float, currency?: string, estimated_delivery_days?: int, service_fees?: array}
     * @throws ApiException
     */
    public function calculate(array $payload, int $storeId): array;
}

