<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Account;

use Magento\Framework\App\CacheInterface;
use PHPUnit\Framework\TestCase;
use PigeonExpress\DTO\Response\SavedAddress;
use PigeonExpress\Shipping\Model\Account\SavedAddresses;
use PigeonExpress\Shipping\Model\Api\ClientFactory;
use PigeonExpress\Shipping\Model\Environment;
use PigeonExpress\PigeonExpress;
use PigeonExpress\Resources\Addresses;
use Psr\Log\LoggerInterface;

/**
 * The sandbox account has no saved addresses, so the shape of one is tested here.
 */
class SavedAddressesTest extends TestCase
{
    public function testAnAddressAsTheApiListsIt(): void
    {
        $row = SavedAddresses::normalise([
            'id'         => 12,
            'label'      => 'Склад',
            'is_default' => true,
            'address'    => [
                'city_id'       => 759,
                'city_name'     => 'Пловдив',
                'street_id'     => 33,
                'street_name'   => 'бул. Марица',
                'street_number' => '5',
                'postal_code'   => '4000',
                'neighborhood'  => 'Център',
            ],
        ]);

        $this->assertSame(12, $row['id']);
        $this->assertSame('Склад (Пловдив, бул. Марица 5)', $row['label']);
        $this->assertTrue($row['is_default']);
        // With a street, the neighbourhood is not part of the description.
        $this->assertSame(
            ['city_id' => 759, 'street_id' => 33, 'street_number' => '5', 'postal_code' => '4000', 'additional_info' => '5'],
            $row['pickup_address']
        );
    }

    public function testWithoutAStreetTheNeighbourhoodDescribesIt(): void
    {
        $address = SavedAddresses::pickupAddress(['city_id' => 759, 'neighborhood' => 'Тракия', 'additional_info' => 'бл. 12']);

        $this->assertSame(['city_id' => 759, 'additional_info' => 'Тракия бл. 12'], $address);
    }

    public function testAnAddressWithoutACityIsSkipped(): void
    {
        $this->assertNull(SavedAddresses::normalise(['id' => 3, 'address' => []]));
        $this->assertNull(SavedAddresses::normalise(['address' => ['city_id' => 1]]));
    }

    public function testAFreshCachedListIsUsedWithoutAskingTheApi(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(json_encode([
            'fetched_at' => time(),
            'rows' => [['id' => 7, 'label' => 'A', 'is_default' => false, 'pickup_address' => ['city_id' => 1]]],
        ]));
        $factory = $this->createMock(ClientFactory::class);
        $factory->expects($this->never())->method('create');

        $saved = new SavedAddresses($factory, $cache, $this->createMock(Environment::class), $this->createMock(LoggerInterface::class));

        $this->assertTrue($saved->hasAny());
        $this->assertSame('A', $saved->find(7)['label']);
        $this->assertNull($saved->find(8));
    }

    public function testNoCredentialsMeansNoAddresses(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);
        $factory = $this->createMock(ClientFactory::class);
        $factory->method('hasCredentials')->willReturn(false);

        $saved = new SavedAddresses($factory, $cache, $this->createMock(Environment::class), $this->createMock(LoggerInterface::class));

        $this->assertFalse($saved->hasAny());
    }

    public function testAListedAddressIsKnownAndAnotherIsNot(): void
    {
        $saved = $this->saved(['fetched_at' => time(), 'rows' => [$this->cachedRow(7)]]);

        $this->assertTrue($saved->isKnown(7));
        $this->assertFalse($saved->isKnown(8));
    }

    public function testAnAccountWithoutSavedAddressesKnowsNone(): void
    {
        $saved = $this->saved(['fetched_at' => time(), 'rows' => []]);

        $this->assertFalse($saved->isKnown(7), 'an empty list that was read is an answer');
    }

    public function testAListThatCouldNotBeReadTellsNothing(): void
    {
        // Nothing cached (a flush, a deploy) and the API fails.
        $saved = $this->saved(null, new \RuntimeException('timeout'));

        $this->assertSame([], $saved->all());
        $this->assertNull($saved->isKnown(7));
    }

    public function testWithoutCredentialsNothingIsKnownEitherWay(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn(false);
        $factory = $this->createMock(ClientFactory::class);
        $factory->method('hasCredentials')->willReturn(false);

        $saved = new SavedAddresses($factory, $cache, $this->createMock(Environment::class), $this->createMock(LoggerInterface::class));

        $this->assertNull($saved->isKnown(7));
    }

    public function testAFailedFetchStillHasTheLastListFetched(): void
    {
        $saved = $this->saved(['fetched_at' => time() - 7200, 'rows' => [$this->cachedRow(7)]], new \RuntimeException('timeout'));

        $this->assertTrue($saved->isKnown(7));
        $this->assertFalse($saved->isKnown(8));
    }

    public function testAFreshFetchIsAnAnswer(): void
    {
        $saved = $this->saved(null, [SavedAddress::fromArray([
            'id' => 9,
            'label' => 'Склад',
            'address' => ['city_id' => 759, 'city_name' => 'Пловдив'],
        ])]);

        $this->assertTrue($saved->isKnown(9));
        $this->assertFalse($saved->isKnown(7));
    }

    /**
     * Saved addresses with this cache entry and this API answer.
     *
     * @param array<string, mixed>|null $cached The cache entry, null for none
     * @param SavedAddress[]|\Throwable $api What GET /addresses gives
     */
    private function saved(?array $cached, $api = []): SavedAddresses
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturn($cached === null ? false : json_encode($cached));

        $addresses = $this->createMock(Addresses::class);
        if ($api instanceof \Throwable) {
            $addresses->method('all')->willThrowException($api);
        } else {
            $addresses->method('all')->willReturn($api);
        }
        $client = $this->createMock(PigeonExpress::class);
        $client->method('addresses')->willReturn($addresses);
        $factory = $this->createMock(ClientFactory::class);
        $factory->method('hasCredentials')->willReturn(true);
        $factory->method('create')->willReturn($client);

        return new SavedAddresses($factory, $cache, $this->createMock(Environment::class), $this->createMock(LoggerInterface::class));
    }

    /**
     * @return array<string, mixed>
     */
    private function cachedRow(int $id): array
    {
        return ['id' => $id, 'label' => 'A', 'is_default' => false, 'pickup_address' => ['city_id' => 1]];
    }
}
