<?php
/**
 * The parcel cannot be carried as described, and no amount of retrying changes
 * that.
 *
 * Separate from ApiException so rating can tell the difference between "the
 * carrier is unreachable, fall back to a flat rate" and "this cannot be shipped
 * at all". Quoting a flat rate for a parcel the carrier will refuse sells the
 * customer a delivery that cannot happen.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Exception;

class UnshippableException extends ApiException
{
}
