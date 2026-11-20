<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Location;

use Magento\Framework\DB\Transaction;
use Magento\Framework\DB\TransactionFactory;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Api\AdditionalServicesClientInterface;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Api\LocationsClientInterface;
use PigeonExpress\Shipping\Model\Account\SavedAddresses;
use PigeonExpress\Shipping\Model\AdditionalServiceRepository;
use PigeonExpress\Shipping\Model\Aps;
use PigeonExpress\Shipping\Model\ApsFactory;
use PigeonExpress\Shipping\Model\Environment;
use PigeonExpress\Shipping\Model\LocationSync;
use PigeonExpress\Shipping\Model\Office;
use PigeonExpress\Shipping\Model\OfficeFactory;
use PigeonExpress\Shipping\Model\ResourceModel\Aps\Collection as ApsCollection;
use PigeonExpress\Shipping\Model\ResourceModel\Aps\CollectionFactory as ApsCollectionFactory;
use PigeonExpress\Shipping\Model\ResourceModel\City\CollectionFactory as CityCollectionFactory;
use PigeonExpress\Shipping\Model\ResourceModel\Office\Collection as OfficeCollection;
use PigeonExpress\Shipping\Model\ResourceModel\Office\CollectionFactory as OfficeCollectionFactory;
use Psr\Log\NullLogger;

/**
 * The sync keeps a locker's code, which its free boxes are looked up by.
 */
class LocationSyncCodeTest extends TestCase
{
    public function testLockersGetTheirCodeAndAnEmptyCodeIsNone(): void
    {
        $existing = $this->createPartialMock(Aps::class, []);
        $existing->setData(['api_id' => 486, 'code' => 'OLD']);

        $created = $this->createPartialMock(Aps::class, []);

        $sync = $this->sync(
            [
                ['id' => 485, 'code' => ' 1132 ', 'name' => 'Билла АПС', 'address' => 'София', 'city_id' => 759],
                ['id' => 486, 'code' => '', 'name' => 'Без код', 'address' => 'София', 'city_id' => 759],
            ],
            [$existing],
            $created,
            $saved
        );

        $stats = $sync->syncAps();

        $this->assertSame(['created' => 1, 'updated' => 1, 'deactivated' => 0], $stats);
        $this->assertSame('1132', $created->getCode());
        $this->assertSame(485, $created->getApiId());
        $this->assertNull($existing->getCode(), 'a code the API no longer gives is cleared');
        $this->assertCount(2, $saved);
    }

    public function testAnOfficeIsNotGivenACode(): void
    {
        $office = $this->createPartialMock(Office::class, []);

        $officeFactory = $this->createMock(OfficeFactory::class);
        $officeFactory->method('create')->willReturn($office);

        $offices = $this->createMock(OfficeCollection::class);
        $offices->method('getIterator')->willReturn(new \ArrayIterator([]));
        $officeCollections = $this->createMock(OfficeCollectionFactory::class);
        $officeCollections->method('create')->willReturn($offices);

        $client = $this->createMock(LocationsClientInterface::class);
        $client->method('fetchOffices')->willReturn([['id' => 144, 'code' => 'SOF-028', 'name' => 'НДК', 'address' => 'София']]);

        $sync = new LocationSync(
            $client,
            $officeFactory,
            $this->createMock(ApsFactory::class),
            $officeCollections,
            $this->createMock(ApsCollectionFactory::class),
            $this->createMock(CityCollectionFactory::class),
            $this->transactionFactory($saved),
            $this->createMock(ConfigInterface::class),
            new NullLogger(),
            $this->createMock(AdditionalServicesClientInterface::class),
            $this->createMock(AdditionalServiceRepository::class),
            $this->environment(),
            $this->createMock(SavedAddresses::class)
        );

        $sync->syncOffices();

        $this->assertFalse($office->hasData('code'), 'the office table has no code column');
        $this->assertSame(144, $office->getApiId());
    }

    /**
     * @param array<int, array<string, mixed>> $apiRows
     * @param Aps[] $existing
     * @param array<int, object>|null $saved Filled with what the transaction saved
     */
    private function sync(array $apiRows, array $existing, Aps $created, &$saved): LocationSync
    {
        $client = $this->createMock(LocationsClientInterface::class);
        $client->method('fetchAps')->willReturn($apiRows);

        $collection = $this->createMock(ApsCollection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($existing));
        $collections = $this->createMock(ApsCollectionFactory::class);
        $collections->method('create')->willReturn($collection);

        $factory = $this->createMock(ApsFactory::class);
        $factory->method('create')->willReturn($created);

        return new LocationSync(
            $client,
            $this->createMock(OfficeFactory::class),
            $factory,
            $this->createMock(OfficeCollectionFactory::class),
            $collections,
            $this->createMock(CityCollectionFactory::class),
            $this->transactionFactory($saved),
            $this->createMock(ConfigInterface::class),
            new NullLogger(),
            $this->createMock(AdditionalServicesClientInterface::class),
            $this->createMock(AdditionalServiceRepository::class),
            $this->environment(),
            $this->createMock(SavedAddresses::class)
        );
    }

    /**
     * @param array<int, object>|null $saved
     */
    private function transactionFactory(&$saved): TransactionFactory
    {
        $saved = [];
        $transaction = $this->createMock(Transaction::class);
        $transaction->method('addObject')->willReturnCallback(function ($object) use (&$saved, $transaction) {
            $saved[] = $object;

            return $transaction;
        });

        $factory = $this->createMock(TransactionFactory::class);
        $factory->method('create')->willReturn($transaction);

        return $factory;
    }

    private function environment(): Environment
    {
        $environment = $this->createMock(Environment::class);
        $environment->method('current')->willReturn(Environment::TEST);

        return $environment;
    }
}
