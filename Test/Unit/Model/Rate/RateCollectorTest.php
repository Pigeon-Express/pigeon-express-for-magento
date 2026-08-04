<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Rate;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Quote\Model\Quote\Address\RateRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Api\RateClientInterface;
use PigeonExpress\Shipping\Exception\MissingDimensionsException;
use PigeonExpress\Shipping\Model\Package\PackageBuilder;
use PigeonExpress\Shipping\Model\Rate\RateCollector;
use PigeonExpress\Shipping\Model\Service\CodFeeResolver;
use PigeonExpress\Shipping\Model\Service\ServiceCodesBuilder;

/**
 * What a free delivery type must and must not skip.
 *
 * There is no price to ask the carrier for, so the call is skipped, but the
 * payload is still assembled because assembling it is what rejects a parcel the
 * carrier will not take. Skipping both would offer a free locker delivery for
 * something that does not fit in a locker.
 */
class RateCollectorTest extends TestCase
{
    private const STORE_ID = 1;

    /** @var ObjectManager */
    private $objectManager;

    /** @var ConfigInterface&MockObject */
    private $config;

    /** @var RateClientInterface&MockObject */
    private $rateClient;

    /** @var PackageBuilder&MockObject */
    private $packageBuilder;

    protected function setUp(): void
    {
        $this->objectManager = new ObjectManager($this);
        $this->config = $this->createMock(ConfigInterface::class);
        $this->rateClient = $this->createMock(RateClientInterface::class);
        $this->packageBuilder = $this->createMock(PackageBuilder::class);

        $this->config->method('getDeliveryTypePricingMode')->willReturn('dynamic');
        $this->config->method('isLoggingEnabled')->willReturn(false);
    }

    public function testAFreeDeliveryTypeDoesNotAskTheCarrierForAPrice(): void
    {
        $this->packageBuilder->method('hasCompleteDimensions')->willReturn(true);
        $this->packageBuilder->method('build')->willReturn([]);
        $this->rateClient->expects($this->never())->method('calculate');

        $price = $this->collector()->getRatePrice($this->request(), 'office', self::STORE_ID, true);

        $this->assertSame(0.0, $price);
    }

    public function testAFreeLockerDeliveryIsStillRefusedForAParcelWithNoDimensions(): void
    {
        // The check lives inside the payload build, so a short circuit that
        // skipped it would sell a locker delivery that cannot be fulfilled.
        $this->packageBuilder->method('hasCompleteDimensions')->willReturn(false);
        $this->rateClient->expects($this->never())->method('calculate');

        $this->expectException(MissingDimensionsException::class);

        $this->collector()->getRatePrice($this->request(), 'aps', self::STORE_ID, true);
    }

    public function testAFreeDeliveryTypeOnFixedPricingIgnoresTheFlatRate(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('getDeliveryTypePricingMode')->willReturn('fixed');
        $config->method('isLoggingEnabled')->willReturn(false);
        $config->method('getDeliveryTypeFlatRate')->willReturn(7.50);

        $price = $this->collector($config)->getRatePrice($this->request(), 'office', self::STORE_ID, true);

        $this->assertSame(0.0, $price);
    }

    public function testAFlatRateIsStillChargedWhenNothingMadeItFree(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('getDeliveryTypePricingMode')->willReturn('fixed');
        $config->method('isLoggingEnabled')->willReturn(false);
        $config->method('getDeliveryTypeFlatRate')->willReturn(7.50);

        $price = $this->collector($config)->getRatePrice($this->request(), 'office', self::STORE_ID, false);

        $this->assertSame(7.50, $price);
    }

    private function collector(?ConfigInterface $config = null): RateCollector
    {
        /** @var RateCollector $collector */
        $collector = $this->objectManager->getObject(RateCollector::class, [
            'config' => $config ?? $this->config,
            'rateClient' => $this->rateClient,
            'logger' => $this->createMock(\Psr\Log\LoggerInterface::class),
            'packageBuilder' => $this->packageBuilder,
            'codFeeResolver' => $this->createMock(CodFeeResolver::class),
            'serviceCodesBuilder' => $this->createMock(ServiceCodesBuilder::class),
        ]);

        return $collector;
    }

    private function request(): RateRequest
    {
        /** @var RateRequest $request */
        $request = $this->objectManager->getObject(RateRequest::class);
        $request->setPackageWeight(1.0);
        $request->setPackageValueWithDiscount(100.0);
        $request->setAllItems([]);

        return $request;
    }
}
