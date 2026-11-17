<?php
/**
 * Pigeon Express refused a return shipment because the account does not
 * have return shipments. The form is not shown again after it.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\ReturnShipment;

use Magento\Framework\Exception\LocalizedException;

class NotEnabledException extends LocalizedException
{
}
