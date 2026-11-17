<?php
/**
 * Pigeon Express no longer takes changes to this shipment: the courier has
 * picked it up or it was handed in at an office.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Exception;

class NotEditableException extends ApiException
{
}
