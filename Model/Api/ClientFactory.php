<?php
/**
 * The one place a Pigeon Express SDK client is built.
 *
 * Every API class in this module asks here, so the credentials, the
 * environment and its address are decided once: the key pair of the
 * environment chosen with Test Mode, against that environment's API.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Api;

use PigeonExpress\PigeonExpress;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Exception\ApiException;

class ClientFactory
{
    /** API of the test environment. Never shown to merchants. */
    public const BASE_URL_TEST = 'https://api-demo.pigeonexpress.com';

    /** API of the live environment. Never shown to merchants. */
    public const BASE_URL_LIVE = 'https://api.pigeonexpress.com';

    /** Seconds, unless the caller asks for another. */
    public const DEFAULT_TIMEOUT = 30;

    /** @var ConfigInterface */
    private $config;

    public function __construct(ConfigInterface $config)
    {
        $this->config = $config;
    }

    /**
     * A client for the active environment.
     *
     * A new instance each call: the SDK resources remember the last write's
     * replay state, and two callers must not read each other's.
     *
     * @param int|null $timeout Seconds; null for DEFAULT_TIMEOUT
     * @throws ApiException When no API key is configured for the active environment
     */
    public function create(?int $timeout = null): PigeonExpress
    {
        $apiKey = $this->config->getApiKey();
        if ($apiKey === '') {
            throw new ApiException(__('Pigeon Express API key is not configured.'));
        }

        $apiSecret = $this->config->getApiSecret();
        if ($apiSecret === '') {
            $apiSecret = $apiKey;
        }

        try {
            return new PigeonExpress($apiKey, $apiSecret, [
                'base_url' => $this->getBaseUrl(),
                'timeout'  => $timeout !== null && $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT,
            ]);
        } catch (\InvalidArgumentException $e) {
            throw new ApiException(__('Pigeon Express API key is not configured.'), $e);
        }
    }

    /**
     * Whether a key is configured for the active environment.
     */
    public function hasCredentials(): bool
    {
        return $this->config->getApiKey() !== '';
    }

    /**
     * Address of the active environment's API. For logs and code only.
     */
    public function getBaseUrl(): string
    {
        return $this->config->isTestMode() ? self::BASE_URL_TEST : self::BASE_URL_LIVE;
    }
}
