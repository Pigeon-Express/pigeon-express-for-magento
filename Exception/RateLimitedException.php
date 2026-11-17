<?php
/**
 * Pigeon Express refused a request for going over the rate limit. The next
 * request would be refused too, so a batch stops here.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Exception;

class RateLimitedException extends ApiException
{
}
