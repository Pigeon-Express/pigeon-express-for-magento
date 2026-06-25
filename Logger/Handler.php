<?php
/**
 * Log handler for Pigeon Express module – writes to var/log/pigeonexpress.log.
 * API errors, sync, and carrier logs go here.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Logger;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger;

class Handler extends Base
{
    /**
     * @var int
     */
    protected $loggerType = Logger::INFO;

    /**
     * @var string
     */
    protected $fileName = '/var/log/pigeonexpress.log';
}
