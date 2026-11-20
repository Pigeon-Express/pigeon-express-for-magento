<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Api;

use PHPUnit\Framework\TestCase;
use PigeonExpress\Exceptions\RateLimitException;
use PigeonExpress\Exceptions\ServerException;
use PigeonExpress\PigeonExpress;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Exception\ApiException;
use PigeonExpress\Shipping\Exception\RateLimitedException;
use PigeonExpress\Shipping\Model\Api\ClientFactory;
use PigeonExpress\Shipping\Model\Api\LocationsClient;
use Psr\Log\AbstractLogger;

/**
 * The nearest search the checkout builds on, and the locker code the sync
 * now keeps.
 */
class LocationsClientTest extends TestCase
{
    /** @var FakeHttpClient */
    private $http;

    /** @var array<int, int|null> */
    private $timeouts = [];

    /** @var array<int, string> Every message logged */
    private $logged = [];

    public function testNearestAsksForOneKindWithAShortTimeout(): void
    {
        $client = $this->client([FakeHttpClient::json(200, ['success' => true, 'data' => [
            ['id' => 144, 'code' => 'SOF-028', 'type' => 'office', 'name' => 'НДК', 'distance_km' => 0.8, 'latitude' => '42.7048702', 'longitude' => '23.3206809'],
            ['id' => 0, 'name' => 'no id'],
            ['id' => 145, 'code' => ' 168 ', 'type' => 'office', 'name' => 'Средец'],
        ]])]);

        $rows = $client->nearest(42.69771, 23.32191, 'office', 20);

        $this->assertSame('offices/nearest', $this->http->requests[0]['url']);
        $this->assertSame(
            ['latitude' => '42.697710', 'longitude' => '23.321910', 'type' => 'office', 'limit' => 20],
            $this->http->requests[0]['body']
        );
        $this->assertSame([LocationsClient::NEAREST_TIMEOUT], $this->timeouts);
        $this->assertSame([
            [
                'id' => 144,
                'code' => 'SOF-028',
                'type' => 'office',
                'name' => 'НДК',
                'distance_km' => 0.8,
                'latitude' => 42.7048702,
                'longitude' => 23.3206809,
            ],
            [
                'id' => 145,
                'code' => '168',
                'type' => 'office',
                'name' => 'Средец',
                'distance_km' => null,
                'latitude' => null,
                'longitude' => null,
            ],
        ], $rows, 'a row without an id is dropped');
    }

    public function testNearestLockersAreAskedForAsLockers(): void
    {
        $client = $this->client([FakeHttpClient::json(200, ['success' => true, 'data' => []])]);

        $this->assertSame([], $client->nearest(42.7, 23.3, ConfigInterface::DELIVERY_TYPE_APS, 99));
        $this->assertSame('locker', $this->http->requests[0]['body']['type']);
        $this->assertSame(20, $this->http->requests[0]['body']['limit']);
    }

    public function testABadPointOrTypeAsksNothing(): void
    {
        $client = $this->client([FakeHttpClient::json(200, ['success' => true, 'data' => []])]);

        foreach ([[91.0, 23.0, 'office'], [42.0, 181.0, 'office'], [NAN, 23.0, 'aps'], [42.0, 23.0, 'address']] as [$lat, $lng, $type]) {
            try {
                $client->nearest($lat, $lng, $type);
                $this->fail('expected an ApiException');
            } catch (ApiException $e) {
                $this->assertSame(0, $e->getHttpStatus());
            }
        }
        $this->assertSame([], $this->http->requests);
    }

    public function testARateLimitIsRecognisableAndThePointIsNeverLogged(): void
    {
        $client = $this->client([new RateLimitException('Твърде много опити.', 60)]);

        try {
            $client->nearest(42.69771, 23.32191, 'office');
            $this->fail('expected a RateLimitedException');
        } catch (RateLimitedException $e) {
            $this->assertSame(429, $e->getHttpStatus());
        }

        $this->assertNotEmpty($this->logged);
        foreach ($this->logged as $line) {
            $this->assertStringNotContainsString('42.69', $line);
            $this->assertStringNotContainsString('23.32', $line);
        }
    }

    public function testAnyOtherFailureIsAnApiException(): void
    {
        $client = $this->client([new ServerException('down', 503)]);

        try {
            $client->nearest(42.7, 23.3, 'office');
            $this->fail('expected an ApiException');
        } catch (ApiException $e) {
            $this->assertNotInstanceOf(RateLimitedException::class, $e);
            $this->assertSame(503, $e->getHttpStatus());
        }
    }

    public function testTheSyncRowsCarryTheLockerCode(): void
    {
        $client = $this->client([FakeHttpClient::json(200, [
            'success' => true,
            'data' => [
                ['id' => 485, 'code' => '1132', 'type' => 'locker', 'name' => 'Билла АПС', 'address' => 'София'],
                ['id' => 486, 'code' => '', 'type' => 'locker', 'name' => 'Без код', 'address' => 'София'],
            ],
            'meta' => ['last_page' => 1],
        ])]);

        $rows = $client->fetchAps();

        $this->assertSame('1132', $rows[0]['code']);
        $this->assertNull($rows[1]['code']);
        $this->assertSame('locker', $this->http->requests[0]['body']['type']);
    }

    /**
     * @param array<int, \PigeonExpress\HttpClient\Response|\Throwable> $script
     */
    private function client(array $script): LocationsClient
    {
        $this->http = new FakeHttpClient($script);
        $this->timeouts = [];
        $this->logged = [];

        $sdk = new PigeonExpress('k', 's');
        $sdk->setHttpClient($this->http);

        $factory = $this->createMock(ClientFactory::class);
        $factory->method('create')->willReturnCallback(function (?int $timeout = null) use ($sdk): PigeonExpress {
            $this->timeouts[] = $timeout;

            return $sdk;
        });

        $config = $this->createMock(ConfigInterface::class);
        $config->method('isLoggingEnabled')->willReturn(true);

        $logged = &$this->logged;
        $logger = new class ($logged) extends AbstractLogger {
            /** @var array<int, string> */
            private $lines;

            /** @param array<int, string> $lines */
            public function __construct(array &$lines)
            {
                $this->lines = &$lines;
            }

            /**
             * @param mixed $level
             * @param string|\Stringable $message
             * @param array<string, mixed> $context
             */
            public function log($level, $message, array $context = []): void
            {
                $this->lines[] = $message . ' ' . json_encode($context);
            }
        };

        return new LocationsClient($config, $logger, $factory);
    }
}
