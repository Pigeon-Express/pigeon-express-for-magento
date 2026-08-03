<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Directory;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Model\Directory\RegionResolver;

class RegionResolverTest extends TestCase
{
    /** The Bulgarian regions Magento ships, as the directory holds them. */
    private const REGIONS = [
        ['region_id' => 640, 'code' => 'BG-01', 'default_name' => 'Blagoevgrad'],
        ['region_id' => 650, 'code' => 'BG-11', 'default_name' => 'Lovech'],
        ['region_id' => 652, 'code' => 'BG-13', 'default_name' => 'Pazardzhik'],
        ['region_id' => 655, 'code' => 'BG-16', 'default_name' => 'Plovdiv'],
        ['region_id' => 661, 'code' => 'BG-22', 'default_name' => 'Sofia City'],
        ['region_id' => 662, 'code' => 'BG-23', 'default_name' => 'Sofia Province'],
    ];

    /** @var RegionResolver */
    private $resolver;

    protected function setUp(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('join')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        // The regions first, then the localised names, which this store has none of.
        $connection->method('fetchAll')->willReturnOnConsecutiveCalls(self::REGIONS, []);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $this->resolver = new RegionResolver($resource);
    }

    public function testResolvesByEnglishDistrictName(): void
    {
        $this->assertSame(655, $this->resolver->resolve('Plovdiv', 'Пловдив'));
        $this->assertSame(650, $this->resolver->resolve('Lovech', 'Ловеч'));
    }

    public function testIgnoresCaseAndSurroundingSpace(): void
    {
        $this->assertSame(652, $this->resolver->resolve('  pazardzhik '));
    }

    public function testResolvesByRegionCode(): void
    {
        $this->assertSame(640, $this->resolver->resolve('BG-01'));
    }

    /**
     * The capital is the one district the English name cannot place: Pigeon
     * Express calls both the city and the province around it "Sofia".
     */
    public function testTellsTheCapitalFromTheProvinceAroundIt(): void
    {
        $this->assertSame(
            661,
            $this->resolver->resolve('Sofia', 'София (столица)'),
            'the capital is Sofia City'
        );
        $this->assertSame(
            662,
            $this->resolver->resolve('Sofia', 'София'),
            'the province of the same name is Sofia Province'
        );
    }

    public function testFallsBackToTheBulgarianNameWhenThereIsNoEnglishOne(): void
    {
        $this->assertSame(662, $this->resolver->resolve(null, 'София'));
        $this->assertSame(661, $this->resolver->resolve('', 'София-град'));
    }

    /**
     * Seven settlements have no district on record. They must come back as
     * nothing rather than as a guess, so the checkout can ask instead.
     */
    public function testReturnsNothingWhenThereIsNoDistrict(): void
    {
        $this->assertNull($this->resolver->resolve(null, null));
        $this->assertNull($this->resolver->resolve('', ''));
        $this->assertNull($this->resolver->resolve('Nowhere in particular'));
    }
}
