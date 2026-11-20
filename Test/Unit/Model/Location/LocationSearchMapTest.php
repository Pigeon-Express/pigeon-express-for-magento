<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Location;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\DB\Select;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\Aps;
use PigeonExpress\Shipping\Model\Directory\RegionResolver;
use PigeonExpress\Shipping\Model\Environment;
use PigeonExpress\Shipping\Model\LocationSearch;
use PigeonExpress\Shipping\Model\ResourceModel\Aps\Collection as ApsCollection;
use PigeonExpress\Shipping\Model\ResourceModel\Aps\CollectionFactory as ApsCollectionFactory;
use PigeonExpress\Shipping\Model\ResourceModel\Office\Collection as OfficeCollection;
use PigeonExpress\Shipping\Model\ResourceModel\Office\CollectionFactory as OfficeCollectionFactory;

/**
 * The lookups the map, the nearest list and the locker boxes read: map
 * points only where there is a real point, nearest rows in the carrier's
 * order, a locker's code by the id the order keeps.
 */
class LocationSearchMapTest extends TestCase
{
    /** @var array<int, array{0: string, 1: mixed}> Filters the collection was given */
    private $filters = [];

    public function testTheMapListsOnlyRealPointsInThePickerShape(): void
    {
        $search = $this->search($this->officeCollection([
            $this->row(1, 101, 'Център', '42.6977', '23.3219'),
            $this->row(2, 102, 'Открито море', '0', '0'),
            $this->row(3, 103, 'Счупено', '95.1', '23.3'),
        ]));

        $rows = $search->listWithCoordinates(ConfigInterface::DELIVERY_TYPE_OFFICE);

        $this->assertSame([[
            'id' => 1,
            'name' => 'Център',
            'address' => 'ул. Примерна 1',
            'city' => 'София',
            'postcode' => '1000',
            'region_id' => 655,
            'type' => 'office',
            'hours' => '',
            'latitude' => 42.6977,
            'longitude' => 23.3219,
        ]], $rows, '0,0 and out of range points are no places');
        $this->assertContains(['is_active', 1], $this->filters);
        $this->assertContains(['main_table.latitude', ['notnull' => true]], $this->filters);
    }

    public function testAnUnknownTypeHasNothingToShow(): void
    {
        $search = $this->search($this->officeCollection([]));

        $this->assertSame([], $search->listWithCoordinates('address'));
        $this->assertSame([], $search->rowsByApiIds('address', [1]));
    }

    public function testNearestRowsKeepTheOrderAsked(): void
    {
        $search = $this->search($this->officeCollection([
            $this->row(1, 101, 'Първи', '42.1', '23.1'),
            $this->row(2, 102, 'Втори', null, null),
            $this->row(3, 103, 'Трети', '42.3', '23.3'),
        ]));

        $rows = $search->rowsByApiIds(ConfigInterface::DELIVERY_TYPE_OFFICE, ['103', 999, 101, 'x', 102, 101]);

        $this->assertSame([103, 101, 102], array_column($rows, 'api_id'), 'carrier order, unknown and repeated ids dropped');
        $this->assertSame([3, 1, 2], array_column($rows, 'id'));
        $this->assertNull($rows[2]['latitude'], 'a row without a point says so');
        $this->assertSame(42.3, $rows[0]['latitude']);
        $this->assertContains(['main_table.api_id', ['in' => [103, 999, 101, 102]]], $this->filters);
    }

    public function testNoIdsAskNothing(): void
    {
        $factory = $this->createMock(OfficeCollectionFactory::class);
        $factory->expects($this->never())->method('create');

        $search = new LocationSearch(
            $factory,
            $this->createMock(ApsCollectionFactory::class),
            $this->createMock(ConfigInterface::class),
            $this->createMock(StoreManagerInterface::class),
            $this->resource(),
            $this->createMock(RegionResolver::class),
            $this->createMock(Environment::class)
        );

        $this->assertSame([], $search->rowsByApiIds('office', []));
        $this->assertSame([], $search->rowsByApiIds('office', ['', 'abc', 0, -4]));
    }

    public function testALockerCodeIsReadByItsEntityId(): void
    {
        $locker = $this->createPartialMock(Aps::class, ['getId']);
        $locker->method('getId')->willReturn(7);
        $locker->setData('code', ' 1132 ');

        $this->assertSame('1132', $this->searchWithLocker($locker)->getApsCode(7));
        $this->assertContains(['main_table.entity_id', 7], $this->filters);
    }

    public function testAMissingLockerOrCodeIsNull(): void
    {
        $empty = $this->createPartialMock(Aps::class, ['getId']);
        $empty->method('getId')->willReturn(null);
        $this->assertNull($this->searchWithLocker($empty)->getApsCode(7));

        $noCode = $this->createPartialMock(Aps::class, ['getId']);
        $noCode->method('getId')->willReturn(7);
        $this->assertNull($this->searchWithLocker($noCode)->getApsCode(7), 'before a sync filled it');

        $this->assertNull($this->searchWithLocker($noCode)->getApsCode(0));
    }

    public function testCoordinatesOf(): void
    {
        $this->assertSame(['latitude' => 42.5, 'longitude' => 23.25], LocationSearch::coordinatesOf('42.50000000', 23.25));
        $this->assertNull(LocationSearch::coordinatesOf(null, '23.3'));
        $this->assertNull(LocationSearch::coordinatesOf('0.0', '0.0'));
        $this->assertNull(LocationSearch::coordinatesOf('42', '181'));
        $this->assertNull(LocationSearch::coordinatesOf('abc', '23'));
    }

    /**
     * A joined location row as the collection yields it.
     */
    private function row(int $entityId, int $apiId, string $name, ?string $lat, ?string $lng): DataObject
    {
        return new DataObject([
            'id' => $entityId,
            'entity_id' => $entityId,
            'api_id' => $apiId,
            'name' => $name,
            'address' => 'ул. Примерна 1',
            'latitude' => $lat,
            'longitude' => $lng,
            'city_resolved' => 'София',
            'postcode_resolved' => '1000',
            'district_resolved' => 'София (столица)',
            'district_en_resolved' => 'Sofia City',
            'working_hours' => null,
        ]);
    }

    /**
     * @param DataObject[] $rows
     * @return OfficeCollection&MockObject
     */
    private function officeCollection(array $rows)
    {
        $collection = $this->createMock(OfficeCollection::class);
        $this->wire($collection);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($rows));

        return $collection;
    }

    /**
     * @param OfficeCollection|ApsCollection|MockObject $collection
     */
    private function wire($collection): void
    {
        $this->filters = [];
        $select = $this->createMock(Select::class);
        $select->method('joinLeft')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $collection->method('getSelect')->willReturn($select);
        $collection->method('addFieldToFilter')->willReturnCallback(
            function ($field, $condition) use ($collection) {
                $this->filters[] = [$field, $condition];

                return $collection;
            }
        );
        $collection->method('setPageSize')->willReturnSelf();
    }

    /**
     * @param OfficeCollection&MockObject $offices
     */
    private function search($offices): LocationSearch
    {
        $factory = $this->createMock(OfficeCollectionFactory::class);
        $factory->method('create')->willReturn($offices);

        $regions = $this->createMock(RegionResolver::class);
        $regions->method('resolve')->willReturn(655);

        return new LocationSearch(
            $factory,
            $this->createMock(ApsCollectionFactory::class),
            $this->createMock(ConfigInterface::class),
            $this->createMock(StoreManagerInterface::class),
            $this->resource(),
            $regions,
            $this->createMock(Environment::class)
        );
    }

    private function searchWithLocker(Aps $locker): LocationSearch
    {
        $collection = $this->createMock(ApsCollection::class);
        $this->wire($collection);
        $collection->method('getFirstItem')->willReturn($locker);

        $factory = $this->createMock(ApsCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return new LocationSearch(
            $this->createMock(OfficeCollectionFactory::class),
            $factory,
            $this->createMock(ConfigInterface::class),
            $this->createMock(StoreManagerInterface::class),
            $this->resource(),
            $this->createMock(RegionResolver::class),
            $this->createMock(Environment::class)
        );
    }

    private function resource(): ResourceConnection
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getTableName')->willReturnArgument(0);

        return $resource;
    }
}
