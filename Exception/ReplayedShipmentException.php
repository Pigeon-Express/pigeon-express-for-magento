<?php
/**
 * Pigeon Express answered a create with its stored answer to an identical
 * earlier create, and that shipment has been cancelled since.
 *
 * Nothing new exists at the carrier, so nothing may be stored. The same create
 * works once the carrier's 30 second window has passed.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Exception;

class ReplayedShipmentException extends ApiException
{
}
