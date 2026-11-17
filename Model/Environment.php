<?php
/**
 * Which Pigeon Express environment the store is talking to: live or test.
 *
 * Offices, lockers and cities are stored per environment, because the test API
 * has its own, smaller set whose ids overlap with live ones. Every read of those
 * tables filters by this value, and the sync writes it.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model;

use PigeonExpress\Shipping\Api\ConfigInterface;

class Environment
{
    public const LIVE = 'live';
    public const TEST = 'test';

    /** @var ConfigInterface */
    private $config;

    public function __construct(ConfigInterface $config)
    {
        $this->config = $config;
    }

    public function current(): string
    {
        return $this->config->isTestMode() ? self::TEST : self::LIVE;
    }
}
