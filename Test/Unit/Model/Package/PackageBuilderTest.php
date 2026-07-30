<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Package;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Exception\ApiException;
use PigeonExpress\Shipping\Model\Package\PackageBuilder;
use Psr\Log\LoggerInterface;

class PackageBuilderTest extends TestCase
{
    /** @var ConfigInterface&MockObject */
    private $config;

    /** @var ProductCollectionFactory&MockObject */
    private $collectionFactory;

    /** @var PackageBuilder */
    private $builder;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigInterface::class);
        $this->collectionFactory = $this->createMock(ProductCollectionFactory::class);

        $this->config->method('isLoggingEnabled')->willReturn(false);

        $this->builder = new PackageBuilder(
            $this->config,
            $this->collectionFactory,
            $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * @dataProvider dimensionClampProvider
     */
    public function testNormalizeDimensionRespectsApiLimits(float $input, float $expected): void
    {
        $this->assertSame($expected, $this->builder->normalizeDimension($input));
    }

    public function dimensionClampProvider(): array
    {
        return [
            'below minimum'   => [0.0, 1.0],
            'fractional'      => [0.4, 1.0],
            'at minimum'      => [1.0, 1.0],
            'normal'          => [30.0, 30.0],
            'at maximum'      => [200.0, 200.0],
            'above maximum'   => [5000.0, 200.0],
        ];
    }

    /**
     * @dataProvider weightFloorProvider
     */
    public function testNormalizeWeightNeverGoesBelowApiMinimum(float $input, float $expected): void
    {
        $this->assertSame($expected, $this->builder->normalizeWeight($input));
    }

    public function weightFloorProvider(): array
    {
        return [
            'zero'     => [0.0, 0.1],
            'negative' => [-5.0, 0.1],
            'tiny'     => [0.05, 0.1],
            'normal'   => [1.5, 1.5],
        ];
    }

    public function testSplitKeepsASingleParcelUnderTheWeightCap(): void
    {
        $packages = $this->builder->split(12.0, $this->dims());

        $this->assertCount(1, $packages);
        $this->assertSame(12.0, $packages[0]['weight']);
        $this->assertSame(30.0, $packages[0]['length']);
    }

    public function testSplitBreaksHeavyCartsIntoCappedParcels(): void
    {
        $packages = $this->builder->split(75.0, $this->dims());

        $this->assertCount(3, $packages);
        $this->assertSame([30.0, 30.0, 15.0], array_column($packages, 'weight'));
        $this->assertLessThanOrEqual(
            PackageBuilder::MAX_PACKAGE_WEIGHT,
            max(array_column($packages, 'weight'))
        );
    }

    public function testSplitPreservesTotalWeight(): void
    {
        $packages = $this->builder->split(67.3, $this->dims());

        $this->assertEqualsWithDelta(67.3, array_sum(array_column($packages, 'weight')), 0.001);
    }

    public function testSplitGivesEveryParcelTheSameDimensions(): void
    {
        $packages = $this->builder->split(90.0, $this->dims());

        foreach ($packages as $package) {
            $this->assertSame(30.0, $package['length']);
            $this->assertSame(20.0, $package['width']);
            $this->assertSame(15.0, $package['height']);
        }
    }

    public function testEveryPackageCarriesTheFourFieldsTheApiRequires(): void
    {
        $packages = $this->builder->split(45.0, $this->dims());

        foreach ($packages as $package) {
            $this->assertSame(
                ['length', 'width', 'height', 'weight'],
                array_keys($package),
                'API rejects packages missing any of weight, length, width, height'
            );
        }
    }

    public function testDimensionsAreSummedAcrossLinesNotTakenFromTheFirstProduct(): void
    {
        // The shipment payload used to take the first product with dimensions,
        // while rating summed them. That mismatch is the bug this guards.
        $this->configureAttributes();
        $this->configureDefaults(0.0, 0.0, 0.0);
        $this->expectProducts([
            1 => ['pe_length' => 10, 'pe_width' => 5, 'pe_height' => 2],
            2 => ['pe_length' => 20, 'pe_width' => 5, 'pe_height' => 3],
        ]);

        $dimensions = $this->builder->resolveDimensions(
            [
                ['product_id' => 1, 'qty' => 1.0],
                ['product_id' => 2, 'qty' => 1.0],
            ],
            1
        );

        $this->assertSame(30.0, $dimensions['length']);
        $this->assertSame(10.0, $dimensions['width']);
        $this->assertSame(5.0, $dimensions['height']);
    }

    public function testConfiguredDefaultsCoverProductsMissingDimensions(): void
    {
        $this->configureAttributes();
        $this->configureDefaults(30.0, 20.0, 15.0);
        $this->expectProducts([
            1 => ['pe_length' => 10, 'pe_width' => 5, 'pe_height' => 2],
            2 => ['pe_length' => null, 'pe_width' => null, 'pe_height' => null],
        ]);

        $dimensions = $this->builder->resolveDimensions(
            [
                ['product_id' => 1, 'qty' => 1.0],
                ['product_id' => 2, 'qty' => 1.0],
            ],
            1
        );

        $this->assertSame(40.0, $dimensions['length'], 'product 10 plus default 30');
        $this->assertSame(25.0, $dimensions['width']);
        $this->assertSame(17.0, $dimensions['height']);
    }

    public function testMissingDimensionsWithNoDefaultsIsRefusedRatherThanGuessed(): void
    {
        $this->configureAttributes();
        $this->configureDefaults(0.0, 0.0, 0.0);
        $this->expectProducts([
            1 => ['pe_length' => null, 'pe_width' => null, 'pe_height' => null],
        ]);

        $this->expectException(ApiException::class);
        $this->builder->resolveDimensions([['product_id' => 1, 'qty' => 1.0]], 1);
    }

    public function testWeightIsMultipliedByQuantity(): void
    {
        $this->config->method('getWeightAttributeCode')->willReturn('weight');
        $this->config->method('getDefaultWeight')->willReturn(0.0);
        $this->expectProducts([1 => ['weight' => 2.5]]);

        $weight = $this->builder->resolveWeight([['product_id' => 1, 'qty' => 4.0]], 1);

        $this->assertSame(10.0, $weight);
    }

    public function testDefaultWeightCoversProductsWithoutOne(): void
    {
        $this->config->method('getWeightAttributeCode')->willReturn('weight');
        $this->config->method('getDefaultWeight')->willReturn(0.5);
        $this->expectProducts([
            1 => ['weight' => 2.0],
            2 => ['weight' => 0],
        ]);

        $weight = $this->builder->resolveWeight(
            [
                ['product_id' => 1, 'qty' => 1.0],
                ['product_id' => 2, 'qty' => 3.0],
            ],
            1
        );

        $this->assertSame(3.5, $weight, '2.0 plus three units of the 0.5 default');
    }

    public function testCallerFallbackWeightIsUsedWhenNothingCanBeDerived(): void
    {
        $this->config->method('getWeightAttributeCode')->willReturn('');
        $this->config->method('getDefaultWeight')->willReturn(0.0);

        $weight = $this->builder->resolveWeight([['product_id' => 1, 'qty' => 1.0]], 1, 7.25);

        $this->assertSame(7.25, $weight);
    }

    public function testHasCompleteDimensionsIsFalseWhenAProductLacksThemAndNoDefaultsExist(): void
    {
        $this->configureAttributes();
        $this->configureDefaults(0.0, 0.0, 0.0);
        $this->expectProducts([
            1 => ['pe_length' => 10, 'pe_width' => 5, 'pe_height' => 2],
            2 => ['pe_length' => null, 'pe_width' => null, 'pe_height' => null],
        ]);

        $this->assertFalse($this->builder->hasCompleteDimensions(
            [
                ['product_id' => 1, 'qty' => 1.0],
                ['product_id' => 2, 'qty' => 1.0],
            ],
            1
        ));
    }

    public function testHasCompleteDimensionsIsTrueWhenDefaultsFillTheGap(): void
    {
        $this->configureAttributes();
        $this->configureDefaults(30.0, 20.0, 15.0);
        $this->expectProducts([
            1 => ['pe_length' => null, 'pe_width' => null, 'pe_height' => null],
        ]);

        $this->assertTrue($this->builder->hasCompleteDimensions([['product_id' => 1, 'qty' => 1.0]], 1));
    }

    public function testBuildProducesAnApiReadyPayloadForATypicalCart(): void
    {
        $this->configureAttributes();
        $this->configureDefaults(30.0, 20.0, 15.0);
        $this->config->method('getWeightAttributeCode')->willReturn('weight');
        $this->config->method('getDefaultWeight')->willReturn(0.5);
        $this->expectProducts([
            1 => ['pe_length' => 10, 'pe_width' => 5, 'pe_height' => 2, 'weight' => 1.0],
        ]);

        $packages = $this->builder->build([['product_id' => 1, 'qty' => 2.0]], 1);

        $this->assertCount(1, $packages);
        $this->assertSame(2.0, $packages[0]['weight']);
        $this->assertSame(10.0, $packages[0]['length']);
    }

    // -----------------------------------------------------------------------

    /** @return array{length: float, width: float, height: float} */
    private function dims(): array
    {
        return ['length' => 30.0, 'width' => 20.0, 'height' => 15.0];
    }

    private function configureAttributes(): void
    {
        $this->config->method('getLengthAttributeCode')->willReturn('pe_length');
        $this->config->method('getWidthAttributeCode')->willReturn('pe_width');
        $this->config->method('getHeightAttributeCode')->willReturn('pe_height');
    }

    private function configureDefaults(float $length, float $width, float $height): void
    {
        $this->config->method('getDefaultLength')->willReturn($length);
        $this->config->method('getDefaultWidth')->willReturn($width);
        $this->config->method('getDefaultHeight')->willReturn($height);
    }

    /**
     * @param array<int, array<string, mixed>> $productData Keyed by product id
     */
    private function expectProducts(array $productData): void
    {
        $products = [];
        foreach ($productData as $id => $data) {
            $product = $this->createMock(Product::class);
            $product->method('getData')->willReturnCallback(
                static function (string $attribute) use ($data) {
                    return $data[$attribute] ?? null;
                }
            );
            $products[$id] = $product;
        }

        $collection = $this->createMock(ProductCollection::class);
        $collection->method('setStoreId')->willReturnSelf();
        $collection->method('addAttributeToSelect')->willReturnSelf();
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('load')->willReturnSelf();
        $collection->method('getItemById')->willReturnCallback(
            static function ($id) use ($products) {
                return $products[$id] ?? null;
            }
        );

        $this->collectionFactory->method('create')->willReturn($collection);
    }
}
