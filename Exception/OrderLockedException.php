<?php
/**
 * Another request is creating a shipment for the same order right now.
 *
 * Nothing was sent. Unattended callers (cron) try again a minute later
 * instead of reporting a failure: by then the other request has either
 * created the shipment or let go of the order.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Exception;

use Magento\Framework\Exception\LocalizedException;

class OrderLockedException extends LocalizedException
{
}
