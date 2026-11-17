<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Account;

use Magento\Framework\App\CacheInterface;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Model\Account\SavedAddresses;
use PigeonExpress\Shipping\Model\Api\ClientFactory;
use PigeonExpress\Shipping\Model\Environment;
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
}
