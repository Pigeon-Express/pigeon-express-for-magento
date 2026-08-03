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
use PigeonExpress\Shipping\Exception\UnshippableException;
use PigeonExpress\Shipping\Model\Package\PackageBuilder;
use PigeonExpress\Shipping\Model\Service\CarrierLimits;
use Psr\Log\LoggerInterface;

class PackageBuilderTest extends TestCase
{
    /** @var ConfigInterface&MockObject */
    private $config;

    /** @var ProductCollectionFactory&MockObject */
    private $collectionFactory;

    /** @var CarrierLimits&MockObject */
    private $limits;

    /** @var PackageBuilder */
    private $builder;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigInterface::class);
        $this->collectionFactory = $this->createMock(ProductCollectionFactory::class);

        $this->config->method('isLoggingEnabled')->willReturn(false);

        // What the carrier last said it will carry. Stubbed here so the tests
        // describe the builder's behaviour rather than today's tariff.
        $this->limits = $this->createMock(CarrierLimits::class);
        $this->limits->method('all')->willReturnCallback(
            static function (string $type): array {
                return $type === 'aps'
                    ? ['weight' => 30.0, 'length' => 250.0, 'width' => 180.0, 'height' => 150.0]
                    : ['weight' => 40.0, 'length' => 250.0, 'width' => 180.0, 'height' => 150.0];
            }
        );
        $this->limits->method('maxWeight')->willReturnCallback(
            static function (string $type): float {
                return $type === 'aps' ? 30.0 : 40.0;
            }
        );

        $this->config->method('getLockerCompartment')
            ->willReturn(['length' => 44.0, 'width' => 61.0, 'height' => 37.0]);

        $this->builder = new PackageBuilder(
            $this->config,
            $this->collectionFactory,
            $this->createMock(LoggerInterface::class),
            $this->limits
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
        // Unqualified, a dimension is bounded by the longest side an address or
        // office parcel may have.
        return [
            'below minimum'   => [0.0, 1.0],
            'fractional'      => [0.4, 1.0],
            'at minimum'      => [1.0, 1.0],
            'normal'          => [30.0, 30.0],
            'at maximum'      => [250.0, 250.0],
            'above maximum'   => [5000.0, 250.0],
        ];
    }

    /**
     * @dataProvider deliveryLimitProvider
     */
    public function testEachDeliveryTypeCarriesItsOwnLimits(string $type, float $weight, float $length): void
    {
        // Measured against the API. A locker is the odd one out: its tariff stops
        // at 30 kg, and its compartment is smaller than anything a courier will
        // carry to a door.
        $limits = $this->builder->limitsFor($type);

        $this->assertSame($weight, $limits['weight']);
        $this->assertSame($length, $limits['length']);
    }

    public function deliveryLimitProvider(): array
    {
        return [
            'address' => ['address', 40.0, 250.0],
            'office'  => ['office', 40.0, 250.0],
            'locker'  => ['aps', 30.0, 250.0],
        ];
    }

    public function testAnUnknownDeliveryTypeUsesTheAddressLimits(): void
    {
        $this->assertSame(40.0, $this->builder->maxWeightFor('teleport'));
    }

    /**
     * @dataProvider lockerFitProvider
     */
    public function testALockerCompartmentIsMeasuredAsABox(array $parcel, bool $fits): void
    {
        // A parcel can be turned round, so 44 x 61 x 37 goes into a 61 x 37 x 44
        // compartment perfectly well. Comparing axis to axis would refuse it.
        $compartment = $this->builder->compartmentFor('aps');

        $this->assertSame($fits, $this->builder->fitsWithin($parcel, $compartment));
    }

    public function lockerFitProvider(): array
    {
        return [
            'exactly the compartment' => [['length' => 44.0, 'width' => 61.0, 'height' => 37.0], true],
            'turned round'            => [['length' => 61.0, 'width' => 37.0, 'height' => 44.0], true],
            'small'                   => [['length' => 20.0, 'width' => 20.0, 'height' => 20.0], true],
            'one side too long'       => [['length' => 62.0, 'width' => 20.0, 'height' => 20.0], false],
            'too fat overall'         => [['length' => 50.0, 'width' => 50.0, 'height' => 50.0], false],
        ];
    }

    public function testAParcelTooBigForALockerIsRefusedRatherThanTrimmed(): void
    {
        // Trimming it on paper would let the carrier accept a parcel that then
        // does not go into the locker the recipient was sent to.
        $this->configureAttributes();
        $this->configureDefaults(100.0, 100.0, 100.0);

        $this->expectException(UnshippableException::class);
        $this->expectExceptionMessageMatches('/will not fit/');

        $this->builder->resolveDimensions([], 1, 'aps');
    }

    public function testTheSameParcelIsFineForAnAddress(): void
    {
        $this->configureAttributes();
        $this->configureDefaults(100.0, 100.0, 100.0);

        $dimensions = $this->builder->resolveDimensions([], 1, 'address');

        $this->assertSame(100.0, $dimensions['length']);
    }

    public function testALockerRefusesAHeavierParcelThanADoorDoes(): void
    {
        $this->expectException(UnshippableException::class);

        $this->builder->split(35.0, $this->dims(), 1, $this->builder->maxWeightFor('aps'));
    }

    public function testTheSameWeightIsOneParcelToAnAddress(): void
    {
        $packages = $this->builder->split(35.0, $this->dims(), 1, $this->builder->maxWeightFor('address'));

        $this->assertCount(1, $packages);
    }

    public function testOneWaybillCannotCarryUnlimitedParcels(): void
    {
        // Pigeon Express caps a reference number at ten parcels. The module does
        // not split across two waybills, so the merchant is told rather than
        // handed half a shipment.
        $this->expectException(UnshippableException::class);
        $this->expectExceptionMessageMatches('/at most 10/');

        $this->builder->split(500.0, $this->dims(), 20, 40.0);
    }

    public function testTheNoticeSaysWhatToDoAboutIt(): void
    {
        try {
            $this->builder->split(500.0, $this->dims(), 20, 40.0);
            $this->fail('an over-full waybill should be refused');
        } catch (UnshippableException $e) {
            $message = (string) $e->getMessage();

            $this->assertStringContainsString('13 parcels', $message, 'say how many it needs');
            $this->assertStringContainsString('Split it into separate orders', $message, 'say what to do');
        }
    }

    public function testTenParcelsIsStillOneWaybill(): void
    {
        $packages = $this->builder->split(400.0, $this->dims(), 10, 40.0);

        $this->assertCount(PackageBuilder::MAX_PACKAGES_PER_SHIPMENT, $packages);
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
        $packages = $this->builder->split(95.0, $this->dims());

        $this->assertCount(3, $packages);
        $this->assertSame([40.0, 40.0, 15.0], array_column($packages, 'weight'));
        $this->assertLessThanOrEqual(40.0, max(array_column($packages, 'weight')));
    }

    public function testTheCapIsWhateverTheCarrierLastSaid(): void
    {
        // Nothing here is a number the module chose. The builder asks what the
        // carrier will carry and splits at that, so when the tariff changes the
        // behaviour changes with it and no release is needed.
        $this->assertCount(1, $this->builder->split(40.0, $this->dims()));

        $this->limits = $this->createMock(CarrierLimits::class);
        $this->limits->method('maxWeight')->willReturn(60.0);
        $this->limits->method('all')->willReturn(
            ['weight' => 60.0, 'length' => 250.0, 'width' => 180.0, 'height' => 150.0]
        );
        $builder = new PackageBuilder(
            $this->config,
            $this->collectionFactory,
            $this->createMock(LoggerInterface::class),
            $this->limits
        );

        $this->assertCount(1, $builder->split(55.0, $this->dims()), 'a raised ceiling stops the splitting');
    }

    public function testOneHeavyItemIsNotInventedIntoTwoParcels(): void
    {
        // Arithmetic will turn one 41 kg item into two 20.5 kg parcels. Nobody
        // can hand the courier half of it, and a waybill promising two parcels
        // when one arrives is worse than being told it cannot be carried.
        $this->expectException(UnshippableException::class);
        $this->expectExceptionMessageMatches('/cannot be split/');

        $this->builder->split(41.0, $this->dims(), 1);
    }

    public function testEnoughItemsCanStillBeSplit(): void
    {
        $packages = $this->builder->split(60.0, $this->dims(), 2);

        $this->assertCount(2, $packages);
        $this->assertSame([40.0, 20.0], array_column($packages, 'weight'));
    }

    public function testAnUnknownPieceCountDoesNotBlockSplitting(): void
    {
        // Rating without line data still has to produce a price.
        $this->assertCount(2, $this->builder->split(60.0, $this->dims(), null));
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
