<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Api;

use Magento\Framework\App\CacheInterface;
use PHPUnit\Framework\TestCase;
use PigeonExpress\DTO\Response\Street;
use PigeonExpress\PigeonExpress;
use PigeonExpress\Resources\Streets;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\Api\AddressResolver;
use PigeonExpress\Shipping\Model\Api\ClientFactory;
use PigeonExpress\Shipping\Model\Directory\RegionResolver;
use PigeonExpress\Shipping\Model\Environment;
use PigeonExpress\Shipping\Model\ResourceModel\City\CollectionFactory as CityCollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * A typed street resolved to Pigeon's id with the customer's postcode first,
 * as in WooCommerce: in a town with two streets of one name, the postcode
 * often tells which. The carrier's filter is a prefix match and leaves out
 * every street it has no postcode for, so only an exact postcode counts and
 * the name alone decides whenever that finds nothing.
 */
class AddressResolverStreetPostcodeTest extends TestCase
{
    private const PLOVDIV = 5000;

    /** @var array<int, array{query:string, postcode:?string}> */
    private $searches = [];

    /** @var array<string, string> In-memory cache */
    private $cached = [];

    /** @var array<string, array<int, array<string, mixed>>|\Throwable> "query|postcode" => rows or failure */
    private $answers = [];

    public function testTheStreetUnderExactlyThePostcodeIsChosen(): void
    {
        // Two "Иван Вазов" streets in one town: the postcode settles it.
        $this->answers['Иван Вазов|4003'] = [
            $this->street(11, 'ИВАН ВАЗОВ', 'улица', '4003'),
            $this->street(12, 'ИВАН ВАЗОВ', 'улица', '40031'),
        ];

        $match = $this->resolver()->matchStreet(self::PLOVDIV, 'Иван Вазов', 1, '', '4003');

        $this->assertSame(11, $match['id']);
        $this->assertFalse($match['ambiguous']);
        $this->assertSame([['query' => 'Иван Вазов', 'postcode' => '4003']], $this->searches);
    }

    public function testRowsThatOnlyStartWithThePostcodeAreIgnored(): void
    {
        $this->answers['Иван Вазов|4003'] = [$this->street(12, 'ИВАН ВАЗОВ', 'улица', '40031')];
        $this->answers['Иван Вазов|'] = [
            $this->street(11, 'ИВАН ВАЗОВ', 'улица', ''),
            $this->street(12, 'ИВАН ВАЗОВ', 'улица', '40031'),
        ];

        $match = $this->resolver()->matchStreet(self::PLOVDIV, 'Иван Вазов', 1, '', '4003');

        // Fell back to the name, which cannot choose either: the admin is asked.
        $this->assertTrue($match['ambiguous']);
        $this->assertSame(['4003', null], array_column($this->searches, 'postcode'));
    }

    public function testNothingUnderThePostcodeFallsBackToTheName(): void
    {
        $this->answers['Витоша|1000'] = [];
        $this->answers['Витоша|'] = [$this->street(434618, 'ВИТОША', 'булевард', '')];

        $match = $this->resolver()->matchStreet(self::PLOVDIV, 'Витоша', 1, 'булевард', '1000');

        $this->assertSame(434618, $match['id']);
        $this->assertSame(['1000', null], array_column($this->searches, 'postcode'));
    }

    public function testTheTypedStreetTypeMustAgreeUnderThePostcode(): void
    {
        // The "бул." the customer meant has no postcode at the carrier; the
        // "кв." under it is another place.
        $this->answers['Витоша|1000'] = [$this->street(1, 'ВИТОША', 'квартал', '1000')];
        $this->answers['Витоша|'] = [
            $this->street(1, 'ВИТОША', 'квартал', '1000'),
            $this->street(2, 'ВИТОША', 'булевард', ''),
        ];

        $match = $this->resolver()->matchStreet(self::PLOVDIV, 'Витоша', 1, 'булевард', '1000');

        $this->assertSame(2, $match['id']);
        $this->assertFalse($match['ambiguous']);
    }

    public function testOnlyAnExactNameCountsUnderThePostcode(): void
    {
        // A single loose hit is not taken because the postcode narrowed the list.
        $this->answers['Витоша|1000'] = [$this->street(7, '600-НА (ВИТОША)', 'улица', '1000')];
        $this->answers['Витоша|'] = [
            $this->street(7, '600-НА (ВИТОША)', 'улица', '1000'),
            $this->street(8, 'ВИТОША', 'булевард', ''),
        ];

        $this->assertSame(8, $this->resolver()->matchStreet(self::PLOVDIV, 'Витоша', 1, '', '1000')['id']);
    }

    public function testAFailedPostcodeSearchFallsBackAndIsNotCached(): void
    {
        $this->answers['Витоша|1000'] = new \RuntimeException('postal_code refused');
        $this->answers['Витоша|'] = [$this->street(8, 'ВИТОША', 'булевард', '')];

        $resolver = $this->resolver();
        $this->assertSame(8, $resolver->matchStreet(self::PLOVDIV, 'Витоша', 1, '', '1000')['id']);
        $this->assertSame(8, $resolver->matchStreet(self::PLOVDIV, 'Витоша', 1, '', '1000')['id']);

        // Asked again the second time; the name search came from the cache.
        $this->assertSame(['1000', null, '1000'], array_column($this->searches, 'postcode'));
        $this->assertCount(1, $this->cached);
    }

    public function testThePostcodeIsPartOfTheCacheKey(): void
    {
        $this->answers['Витоша|1000'] = [$this->street(8, 'ВИТОША', 'булевард', '1000')];
        $this->answers['Витоша|1407'] = [$this->street(9, 'ВИТОША', 'булевард', '1407')];

        $resolver = $this->resolver();
        $this->assertSame(8, $resolver->matchStreet(self::PLOVDIV, 'Витоша', 1, '', '1000')['id']);
        $this->assertSame(9, $resolver->matchStreet(self::PLOVDIV, 'Витоша', 1, '', '1407')['id']);
        $this->assertSame(8, $resolver->matchStreet(self::PLOVDIV, 'Витоша', 1, '', '1000')['id']);

        $prefix = 'pigeonexpress_streets_test_' . self::PLOVDIV . '_' . md5(mb_strtolower('Витоша'));
        $this->assertSame([$prefix . '_pc1000', $prefix . '_pc1407'], array_keys($this->cached));
        $this->assertCount(2, $this->searches, 'the third came from the cache');
    }

    public function testWithoutAUsablePostcodeOnlyTheNameIsSearched(): void
    {
        $this->answers['Витоша|'] = [$this->street(8, 'ВИТОША', 'булевард', '1000')];

        $resolver = $this->resolver();
        $resolver->matchStreet(self::PLOVDIV, 'Витоша', 1, '', '');
        $resolver->matchStreet(self::PLOVDIV, 'Витоша', 1, '', '1');
        $resolver->matchStreet(self::PLOVDIV, 'Витоша', 1, '', '12345678901');

        $this->assertSame([null], array_column($this->searches, 'postcode'));
        $this->assertSame(
            ['pigeonexpress_streets_test_' . self::PLOVDIV . '_' . md5(mb_strtolower('Витоша'))],
            array_keys($this->cached),
            'the name search keeps its old cache key'
        );
    }

    public function testTheAutocompleteSearchStaysByNameAndCarriesThePostcode(): void
    {
        $this->answers['Витоша|'] = [$this->street(8, 'ВИТОША', 'булевард', '1000')];

        $rows = $this->resolver()->searchStreets(self::PLOVDIV, 'Витоша', 1);

        $this->assertSame('1000', $rows[0]['postal_code']);
        $this->assertSame([null], array_column($this->searches, 'postcode'));
    }

    // -----------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function street(int $id, string $name, string $type, string $postcode): array
    {
        return ['id' => $id, 'name' => $name, 'type' => $type, 'name_en' => '', 'postal_code' => $postcode];
    }

    private function resolver(): AddressResolver
    {
        $streets = $this->createMock(Streets::class);
        $streets->method('searchInCity')->willReturnCallback(
            function ($cityId, $name, $limit = null, $postalCode = null): array {
                $this->searches[] = ['query' => (string) $name, 'postcode' => $postalCode];
                $answer = $this->answers[$name . '|' . (string) $postalCode] ?? [];
                if ($answer instanceof \Throwable) {
                    throw $answer;
                }

                return array_map(static function (array $row): Street {
                    return Street::fromArray($row);
                }, $answer);
            }
        );
        $sdk = $this->createMock(PigeonExpress::class);
        $sdk->method('streets')->willReturn($streets);
        $factory = $this->createMock(ClientFactory::class);
        $factory->method('create')->willReturn($sdk);

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(function (string $key) {
            return $this->cached[$key] ?? false;
        });
        $cache->method('save')->willReturnCallback(function (string $data, string $key): bool {
            $this->cached[$key] = $data;

            return true;
        });

        $environment = $this->createMock(Environment::class);
        $environment->method('current')->willReturn(Environment::TEST);

        return new AddressResolver(
            $this->createMock(ConfigInterface::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(CityCollectionFactory::class),
            $cache,
            $this->createMock(RegionResolver::class),
            $environment,
            $factory
        );
    }
}
