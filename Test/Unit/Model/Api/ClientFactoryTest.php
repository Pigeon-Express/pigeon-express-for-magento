<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Api;

use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Exception\ApiException;
use PigeonExpress\Shipping\Model\Api\ClientFactory;

/**
 * Every API class builds its SDK client here, so the environment and its keys
 * are decided in one place.
 */
class ClientFactoryTest extends TestCase
{
    public function testTestModeTalksToTheTestEnvironmentWithItsKeys(): void
    {
        $client = $this->factory(true, 'key-t', 'secret-t')->create();

        self::assertSame(ClientFactory::BASE_URL_TEST, $client->getConfig()->getBaseUrl());
        self::assertSame('key-t', $client->getConfig()->getApiKey());
        self::assertSame('secret-t', $client->getConfig()->getApiSecret());
        self::assertSame(ClientFactory::DEFAULT_TIMEOUT, $client->getConfig()->getTimeout());
    }

    public function testLiveModeTalksToTheLiveEnvironment(): void
    {
        $client = $this->factory(false, 'key-l', 'secret-l')->create(120);

        self::assertSame(ClientFactory::BASE_URL_LIVE, $client->getConfig()->getBaseUrl());
        self::assertSame(120, $client->getConfig()->getTimeout());
    }

    public function testAnEmptySecretFallsBackToTheKey(): void
    {
        $client = $this->factory(true, 'only-key', '')->create();

        self::assertSame('only-key', $client->getConfig()->getApiSecret());
    }

    public function testNoKeyIsRefusedBeforeAnyRequest(): void
    {
        $factory = $this->factory(true, '', '');

        self::assertFalse($factory->hasCredentials());
        $this->expectException(ApiException::class);
        $factory->create();
    }

    public function testEachCallGivesItsOwnClient(): void
    {
        $factory = $this->factory(true, 'k', 's');

        self::assertNotSame($factory->create(), $factory->create());
    }

    private function factory(bool $testMode, string $key, string $secret): ClientFactory
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('isTestMode')->willReturn($testMode);
        $config->method('getApiKey')->willReturn($key);
        $config->method('getApiSecret')->willReturn($secret);

        return new ClientFactory($config);
    }
}
