<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Package;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Exception\UnshippableException;
use PigeonExpress\Shipping\Model\Package\PackageBuilder;
use PigeonExpress\Shipping\Model\Service\CarrierLimits;
use Psr\Log\LoggerInterface;

/**
 * The package rows of the shipment form, and the description and fragile
 * mark every package carries.
 */
class PackageFormAndFinishTest extends TestCase
{
    private const ROW = ['weight' => 2.0, 'length' => 30.0, 'width' => 20.0, 'height' => 15.0];

    public function testEveryPackageGetsTheLabelDescription(): void
    {
        $packages = $this->builder()->finish([self::ROW, self::ROW], 'Дрехи', 1);

        $this->assertSame('Дрехи', $packages[0]['description']);
        $this->assertSame('Дрехи', $packages[1]['description']);
    }

    public function testAPackageKeepsItsOwnDescription(): void
    {
        $packages = $this->builder()->finish([self::ROW + ['description' => 'Книги'], self::ROW], 'Дрехи', 1);

        $this->assertSame('Книги', $packages[0]['description']);
        $this->assertSame('Дрехи', $packages[1]['description']);
    }

    public function testNeverFragileSendsNoMark(): void
    {
        $packages = $this->builder(ConfigInterface::FRAGILE_NEVER)->finish([self::ROW + ['fragile' => true]], '', 1);

        $this->assertArrayNotHasKey('is_fragile', $packages[0]);
        $this->assertArrayNotHasKey('fragile', $packages[0]);
        $this->assertArrayNotHasKey('description', $packages[0]);
    }

    public function testAlwaysFragileMarksEveryPackage(): void
    {
        $packages = $this->builder(ConfigInterface::FRAGILE_ALWAYS)->finish([self::ROW, self::ROW], '', 1);

        $this->assertTrue($packages[0]['is_fragile']);
        $this->assertTrue($packages[1]['is_fragile']);
    }

    public function testPerPackageFollowsTheFormTick(): void
    {
        $packages = $this->builder(ConfigInterface::FRAGILE_PER_PACKAGE)
            ->finish([self::ROW + ['fragile' => true], self::ROW + ['fragile' => false]], '', 1);

        $this->assertTrue($packages[0]['is_fragile']);
        $this->assertArrayNotHasKey('is_fragile', $packages[1]);
    }

    public function testFormRowsBecomePackagesAndBlankRowsAreSkipped(): void
    {
        $packages = $this->builder()->fromForm([
            ['weight' => '1,5', 'length' => 30, 'width' => 20, 'height' => 10, 'description' => 'А', 'fragile' => true],
            ['weight' => '', 'length' => '', 'width' => '', 'height' => '', 'description' => ''],
            ['weight' => 3, 'length' => 0, 'width' => 0, 'height' => 0],
        ], 'address', 1);

        $this->assertCount(2, $packages);
        $this->assertSame(1.5, $packages[0]['weight']);
        $this->assertSame('А', $packages[0]['description']);
        $this->assertTrue($packages[0]['fragile']);
        // Empty sides take the default size.
        $this->assertSame(35.0, $packages[1]['length']);
        $this->assertSame(25.0, $packages[1]['width']);
        $this->assertSame(12.0, $packages[1]['height']);
    }

    public function testNoUsableRowLeavesThePackagesToTheOrder(): void
    {
        $this->assertNull($this->builder()->fromForm([['weight' => '']], 'address', 1));
    }

    public function testATooHeavyRowIsRefused(): void
    {
        $this->expectException(UnshippableException::class);

        $this->builder()->fromForm([['weight' => 31] + self::ROW], 'aps', 1);
    }

    private function builder(string $fragile = ConfigInterface::FRAGILE_NEVER): PackageBuilder
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('getFragileMode')->willReturn($fragile);
        $config->method('getDefaultLength')->willReturn(35.0);
        $config->method('getDefaultWidth')->willReturn(25.0);
        $config->method('getDefaultHeight')->willReturn(12.0);
        $config->method('getLockerCompartment')->willReturn(['length' => 44.0, 'width' => 61.0, 'height' => 37.0]);

        $limits = $this->createMock(CarrierLimits::class);
        $limits->method('all')->willReturn(['weight' => 30.0, 'length' => 250.0, 'width' => 180.0, 'height' => 150.0]);
        $limits->method('maxWeight')->willReturn(30.0);

        return new PackageBuilder(
            $config,
            $this->createMock(ProductCollectionFactory::class),
            $this->createMock(LoggerInterface::class),
            $limits
        );
    }
}
