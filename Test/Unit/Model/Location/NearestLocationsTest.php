<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Location;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Api\LocationSearchInterface;
use PigeonExpress\Shipping\Api\LocationsClientInterface;
use PigeonExpress\Shipping\Exception\ApiException;
use PigeonExpress\Shipping\Exception\RateLimitedException;
use PigeonExpress\Shipping\Model\Environment;
use PigeonExpress\Shipping\Model\Location\NearestLocations;
use PigeonExpress\Shipping\Model\Location\NearestRefusedException;

/**
 * The nearest list behind the checkout picker: the point is rounded before
 * anything sees it, the carrier's answer is cached per rounded point, every
 * carrier lookup is counted per cart, per address and for the shop, a 429
 * leaves the carrier alone for five minutes, and only locations the store
 * knows are offered, at most five, with the distance written for the shopper.
 */
class NearestLocationsTest extends TestCase
{
    /** Start of an hour, so the minute and hour windows line up. */
    private const T0 = 1800000000 - (1800000000 % 3600);

    /** @var int */
    private $now = self::T0;

    /** @var array<string, string> */
    private $cacheData = [];

    /** @var array<int, array{0: float, 1: float, 2: string, 3: int}> */
    private $carrierCalls = [];

    /** @var callable|null Throws instead of answering, when set */
    private $carrierFails = null;

    /** @var array<int, array<string, mixed>> What the carrier answers */
    private $carrierRows = [];

    /** @var array<int, array<string, mixed>> Local rows by api_id */
    private $localRows = [];

    /** @var string */
    private $locale = 'en_US';

    /** @var string */
    private $environment = 'test';

    protected function setUp(): void
    {
        $this->carrierRows = [
            ['id' => 11, 'distance_km' => 0.344],
            ['id' => 12, 'distance_km' => 1.25],
        ];
        $this->localRows = [
            11 => $this->localRow(1, 11, 'Лиляна'),
            12 => $this->localRow(2, 12, 'Военна болница'),
        ];
    }

    public function testThePointIsRoundedBeforeTheCarrierSeesItAndTheAnswerIsCachedForIt(): void
    {
        $service = $this->service();

        $rows = $service->find('office', 42.14253, 24.74951, 'quote:1', '203.0.113.5');
        $again = $service->find('office', 42.1428, 24.7504, 'quote:1', '203.0.113.5');

        $this->assertCount(1, $this->carrierCalls, 'The same rounded point is answered from the cache.');
        [$lat, $lng, $type, $limit] = $this->carrierCalls[0];
        $this->assertSame('42.143', sprintf('%.3F', $lat));
        $this->assertSame('24.750', sprintf('%.3F', $lng));
        $this->assertSame(42.143, $lat);
        $this->assertSame('office', $type);
        $this->assertSame(NearestLocations::ASKED, $limit);
        $this->assertSame($rows, $again);

        // Neither the point nor anything finer than the rounded one is cached.
        foreach ($this->cacheData as $value) {
            $this->assertStringNotContainsString('42.14', $value);
            $this->assertStringNotContainsString('24.7', $value);
        }
    }

    public function testThePointMustBeOnTheGlobe(): void
    {
        $this->assertNull(NearestLocations::roundPoint(90.001, 10.0));
        $this->assertNull(NearestLocations::roundPoint(10.0, -180.001));
        $this->assertNull(NearestLocations::roundPoint(NAN, 10.0));
        $this->assertNull(NearestLocations::roundPoint(10.0, INF));
        $this->assertSame([90.0, -180.0], NearestLocations::roundPoint(90.0004, -180.0004));

        // No "-0" in a cache key or a request.
        $zero = NearestLocations::roundPoint(-0.0001, -0.0004);
        $this->assertNotNull($zero);
        $this->assertSame('0.000|0.000', sprintf('%.3F|%.3F', $zero[0], $zero[1]));
    }

    public function testAnUnknownTypeOrPointIsRefusedWithoutAskingTheCarrier(): void
    {
        $service = $this->service();

        foreach ([['locker', 42.0, 24.0], ['office', 95.0, 24.0], ['aps', 42.0, NAN]] as [$type, $lat, $lng]) {
            try {
                $service->find($type, $lat, $lng, 'quote:1', '203.0.113.5');
                $this->fail('Expected a refusal for ' . $type);
            } catch (NearestRefusedException $e) {
                $this->assertSame(NearestRefusedException::INVALID, $e->getReason());
            }
        }

        $this->assertSame([], $this->carrierCalls);
    }

    public function testOnlyLocationsTheStoreKnowsAreOfferedAtMostFiveInTheCarriersOrder(): void
    {
        $this->carrierRows = [];
        $this->localRows = [];
        for ($i = 1; $i <= 8; $i++) {
            $this->carrierRows[] = ['id' => 100 + $i, 'distance_km' => $i / 10];
            // 102 has not been synced yet.
            if ($i !== 2) {
                $this->localRows[100 + $i] = $this->localRow($i, 100 + $i, 'Office ' . $i);
            }
        }
        $this->carrierRows[] = ['id' => '', 'distance_km' => 0.01];

        $rows = $this->service()->find('office', 42.0, 24.0, 'quote:1', '203.0.113.5');

        $this->assertSame([1, 3, 4, 5, 6], array_column($rows, 'id'));
        $this->assertSame(['100 m', '300 m', '400 m', '500 m', '600 m'], array_column($rows, 'distance'));
        $this->assertSame(
            ['id', 'name', 'address', 'city', 'postcode', 'region_id', 'type', 'hours', 'distance'],
            array_keys($rows[0]),
            'The page gets the picker row and the distance: no ids of the carrier, no points.'
        );
    }

    public function testTheLocalRowsAreCheckedOnEveryRequestEvenFromTheCache(): void
    {
        $service = $this->service();

        $this->assertCount(2, $service->find('office', 42.0, 24.0, 'quote:1', '203.0.113.5'));

        // A sync takes one away while the carrier's answer is still cached.
        unset($this->localRows[12]);

        $this->assertSame([1], array_column($service->find('office', 42.0, 24.0, 'quote:1', '203.0.113.5'), 'id'));
        $this->assertCount(1, $this->carrierCalls);
    }

    public function testTheCacheIsKeptPerEnvironment(): void
    {
        $service = $this->service();

        $service->find('office', 42.0, 24.0, 'quote:1', '203.0.113.5');
        $this->environment = 'live';
        $service->find('office', 42.0, 24.0, 'quote:1', '203.0.113.5');

        $this->assertCount(2, $this->carrierCalls);
    }

    /**
     * @dataProvider distances
     */
    public function testDistancesReadAsTheShopperExpects(?float $km, string $locale, string $expected): void
    {
        $this->locale = $locale;

        $this->assertSame($expected, $this->service()->formatDistance($km));
    }

    /**
     * @return array<string, array{0: float|null, 1: string, 2: string}>
     */
    public function distances(): array
    {
        return [
            'unknown' => [null, 'en_US', ''],
            'negative' => [-1.0, 'en_US', ''],
            'at the door' => [0.0, 'en_US', '10 m'],
            'metres to the nearest ten' => [0.344, 'en_US', '340 m'],
            'rounded up to a kilometre' => [0.996, 'en_US', '1.0 km'],
            'one decimal' => [1.25, 'en_US', '1.3 km'],
            'still one decimal' => [99.94, 'en_US', '99.9 km'],
            'whole kilometres from 100' => [99.96, 'en_US', '100 km'],
            'far' => [150.4, 'en_US', '150 km'],
            'Bulgarian decimal comma' => [1.2, 'bg_BG', '1,2 km'],
            'Bulgarian metres' => [0.35, 'bg_BG', '350 m'],
        ];
    }

    public function testOneCartGetsTenCarrierLookupsAMinuteAndCacheHitsAreFree(): void
    {
        $service = $this->service();

        for ($i = 0; $i < NearestLocations::LOOKUPS_PER_MINUTE; $i++) {
            $service->find('office', 42.0 + $i / 100, 24.0, 'quote:1', '203.0.113.' . $i);
        }
        // The same points again cost nothing.
        for ($i = 0; $i < NearestLocations::LOOKUPS_PER_MINUTE; $i++) {
            $service->find('office', 42.0 + $i / 100, 24.0, 'quote:1', '203.0.113.' . $i);
        }

        $this->assertRefused(NearestRefusedException::BUSY, function () use ($service) {
            $service->find('office', 43.0, 24.0, 'quote:1', '198.51.100.1');
        });
        $this->assertCount(NearestLocations::LOOKUPS_PER_MINUTE, $this->carrierCalls);

        // Another cart is not held back by this one.
        $service->find('office', 43.0, 24.0, 'quote:2', '198.51.100.1');

        // The next minute, this cart may ask again.
        $this->now += 60;
        $service->find('office', 43.5, 24.0, 'quote:1', '198.51.100.1');
        $this->assertCount(NearestLocations::LOOKUPS_PER_MINUTE + 2, $this->carrierCalls);
    }

    public function testOneAddressGetsTwentyLookupsAMinuteHoweverManyCartsItOpens(): void
    {
        $service = $this->service();

        for ($i = 0; $i < NearestLocations::IP_LOOKUPS_PER_MINUTE; $i++) {
            $service->find('office', 42.0 + $i / 100, 24.0, 'quote:' . $i, '203.0.113.5');
        }

        $this->assertRefused(NearestRefusedException::BUSY, function () use ($service) {
            $service->find('office', 43.0, 24.0, 'quote:999', '203.0.113.5');
        });
        $this->assertCount(NearestLocations::IP_LOOKUPS_PER_MINUTE, $this->carrierCalls);

        // The address is only ever kept as a keyed hash.
        foreach (array_keys($this->cacheData) as $key) {
            $this->assertStringNotContainsString('203.0.113.5', $key);
        }
    }

    public function testTheShopGetsSixtyLookupsAMinute(): void
    {
        $service = $this->service();

        for ($i = 0; $i < NearestLocations::SITE_LOOKUPS_PER_MINUTE; $i++) {
            $service->find('office', 42.0 + $i / 100, 24.0, 'quote:' . $i, '203.0.113.' . $i);
        }

        $this->assertRefused(NearestRefusedException::BUSY, function () use ($service) {
            $service->find('office', 43.0, 24.0, 'quote:999', '198.51.100.1');
        });
        $this->assertCount(NearestLocations::SITE_LOOKUPS_PER_MINUTE, $this->carrierCalls);
    }

    public function testTheShopGetsSixHundredLookupsAnHour(): void
    {
        $service = $this->service();
        $n = 0;

        for ($minute = 0; $minute < 10; $minute++) {
            $this->now = self::T0 + $minute * 60;
            for ($i = 0; $i < NearestLocations::SITE_LOOKUPS_PER_MINUTE; $i++, $n++) {
                $service->find('office', 10.0 + $n / 1000, 24.0, 'quote:' . $n, '10.0.' . intdiv($n, 250) . '.' . ($n % 250));
            }
        }
        $this->assertCount(NearestLocations::SITE_LOOKUPS_PER_HOUR, $this->carrierCalls);

        // A fresh minute, but the hour is used up.
        $this->now = self::T0 + 10 * 60;
        $this->assertRefused(NearestRefusedException::BUSY, function () use ($service) {
            $service->find('office', 43.0, 24.0, 'quote:new', '198.51.100.1');
        });

        // The next hour.
        $this->now = self::T0 + 3600;
        $service->find('office', 43.0, 24.0, 'quote:new', '198.51.100.1');
        $this->assertCount(NearestLocations::SITE_LOOKUPS_PER_HOUR + 1, $this->carrierCalls);
    }

    public function testAfterA429TheCarrierIsLeftAloneForFiveMinutes(): void
    {
        $service = $this->service();
        $this->carrierFails = function () {
            throw new RateLimitedException(__('Slow down.'));
        };

        $this->assertRefused(NearestRefusedException::BUSY, function () use ($service) {
            $service->find('office', 42.0, 24.0, 'quote:1', '203.0.113.5');
        });
        $this->assertCount(1, $this->carrierCalls);

        $this->carrierFails = null;
        $this->now += NearestLocations::BACKOFF - 1;
        $this->assertRefused(NearestRefusedException::BUSY, function () use ($service) {
            $service->find('office', 41.0, 23.0, 'quote:2', '198.51.100.1');
        });
        $this->assertCount(1, $this->carrierCalls, 'No request at all during the back-off.');

        $this->now += 1;
        $this->assertCount(2, $service->find('office', 41.0, 23.0, 'quote:2', '198.51.100.1'));
        $this->assertCount(2, $this->carrierCalls);
    }

    public function testACarrierErrorIsNotCached(): void
    {
        $service = $this->service();
        $this->carrierFails = function () {
            throw new ApiException(__('No answer.'));
        };

        $this->assertRefused(NearestRefusedException::UNAVAILABLE, function () use ($service) {
            $service->find('office', 42.0, 24.0, 'quote:1', '203.0.113.5');
        });

        $this->carrierFails = null;
        $this->assertCount(2, $service->find('office', 42.0, 24.0, 'quote:1', '203.0.113.5'));
        $this->assertCount(2, $this->carrierCalls);
    }

    public function testALookupNeedsACart(): void
    {
        $service = $this->service();

        $this->assertRefused(NearestRefusedException::NO_SESSION, function () use ($service) {
            $service->find('office', 42.0, 24.0, '', '203.0.113.5');
        });
        $this->assertSame([], $this->carrierCalls);
    }

    private function assertRefused(string $reason, callable $call): void
    {
        try {
            $call();
        } catch (NearestRefusedException $e) {
            $this->assertSame($reason, $e->getReason());

            return;
        }

        $this->fail('Expected a refusal: ' . $reason);
    }

    /**
     * @return array<string, mixed>
     */
    private function localRow(int $entityId, int $apiId, string $name): array
    {
        return [
            'id' => $entityId,
            'name' => $name,
            'address' => 'Пловдив, 4000, ' . $name,
            'city' => 'Пловдив',
            'postcode' => '4000',
            'region_id' => 655,
            'type' => 'office',
            'hours' => '',
            'api_id' => $apiId,
            'latitude' => 42.1,
            'longitude' => 24.7,
        ];
    }

    private function service(): NearestLocations
    {
        $test = $this;

        $client = $this->createMock(LocationsClientInterface::class);
        $client->method('nearest')->willReturnCallback(
            function (float $lat, float $lng, string $type, int $limit) use ($test) {
                $test->carrierCalls[] = [$lat, $lng, $type, $limit];
                if ($test->carrierFails !== null) {
                    ($test->carrierFails)();
                }

                return $test->carrierRows;
            }
        );

        $search = $this->createMock(LocationSearchInterface::class);
        $search->method('rowsByApiIds')->willReturnCallback(function (string $type, array $ids) use ($test) {
            $out = [];
            foreach ($ids as $id) {
                if (isset($test->localRows[(int) $id])) {
                    $out[] = $test->localRows[(int) $id];
                }
            }

            return $out;
        });

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(function ($id) use ($test) {
            return $test->cacheData[$id] ?? false;
        });
        $cache->method('save')->willReturnCallback(function ($data, $id) use ($test) {
            $test->cacheData[$id] = (string) $data;

            return true;
        });

        $environment = $this->createMock(Environment::class);
        $environment->method('current')->willReturnCallback(function () use ($test) {
            return $test->environment;
        });

        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('hash')->willReturnCallback(function ($data) {
            return hash_hmac('sha256', (string) $data, 'test-key');
        });

        $locale = $this->createMock(ResolverInterface::class);
        $locale->method('getLocale')->willReturnCallback(function () use ($test) {
            return $test->locale;
        });

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturnCallback(function () use ($test) {
            return $test->now;
        });

        return new NearestLocations($client, $search, $cache, $environment, $encryptor, $locale, $dateTime);
    }
}
