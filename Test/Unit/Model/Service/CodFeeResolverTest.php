<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Service;

use Magento\Framework\App\CacheInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\AdditionalService;
use PigeonExpress\Shipping\Model\AdditionalServiceRepository;
use PigeonExpress\Shipping\Model\Service\CodFeeResolver;
use Psr\Log\LoggerInterface;

class CodFeeResolverTest extends TestCase
{
    /** @var AdditionalServiceRepository&MockObject */
    private $repository;

    /** @var CacheInterface&MockObject */
    private $cache;

    /** @var LoggerInterface&MockObject */
    private $logger;

    /** @var CodFeeResolver */
    private $resolver;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(AdditionalServiceRepository::class);
        $this->cache = $this->createMock(CacheInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $config = $this->createMock(ConfigInterface::class);
        $config->method('isLoggingEnabled')->willReturn(false);

        $this->resolver = new CodFeeResolver($this->repository, $this->cache, $config, $this->logger);
    }

    public function testNoFeeForANonPositiveAmount(): void
    {
        $this->assertSame(0.0, $this->resolver->resolve(1, 0.0));
        $this->assertSame(0.0, $this->resolver->resolve(1, -50.0));
    }

    public function testFeeJustResolvedFromTheApiIsReturnedForTheSameAmount(): void
    {
        $this->cache->method('load')->willReturn(false);

        $this->resolver->rememberFee(1, 100.0, 1.50);

        $this->assertSame(1.50, $this->resolver->resolve(1, 100.0));
    }

    public function testRememberedFeeIsScopedToItsStore(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->repository->method('getByCode')->willReturn(null);

        $this->resolver->rememberFee(1, 100.0, 1.50);

        $this->assertSame(0.0, $this->resolver->resolve(2, 100.0), 'store 2 must not inherit store 1');
    }

    public function testFeeIsReadBackFromTheMagentoCache(): void
    {
        $this->cache->method('load')->willReturn('2.25');

        $this->assertSame(2.25, $this->resolver->resolve(1, 150.0));
    }

    public function testStoredApiRatioIsAppliedWhenTheApiCannotBeReached(): void
    {
        $this->cache->method('load')->willReturn(false);

        // The API previously charged 1.50 on 100.00, so 1.5 percent.
        $this->repository->method('getByCode')
            ->with(AdditionalService::CODE_COD_AMOUNT)
            ->willReturn($this->serviceWithFee(1.50, 100.0));

        $this->assertSame(3.75, $this->resolver->resolve(1, 250.0), '1.5 percent of 250');
    }

    public function testNoFeeIsChargedWhenNothingApiDerivedIsKnown(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->repository->method('getByCode')->willReturn(null);

        $this->logger->expects($this->once())->method('warning');

        $this->assertSame(
            0.0,
            $this->resolver->resolve(1, 500.0),
            'charging a guessed fee is worse than charging none'
        );
    }

    public function testNoFeeWhenTheStoredServiceHasNoUsableRatio(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->repository->method('getByCode')->willReturn($this->serviceWithFee(null, null));

        $this->assertSame(0.0, $this->resolver->resolve(1, 500.0));
    }

    public function testRememberingAFeeRecordsTheAmountAndTheBasisItWasCalculatedOn(): void
    {
        $this->repository->expects($this->once())
            ->method('recordFee')
            ->with(AdditionalService::CODE_COD_AMOUNT, 1.50, 100.0);

        $this->resolver->rememberFee(1, 100.0, 1.50);
    }

    public function testAZeroFeeFromTheApiIsStillRecorded(): void
    {
        // A carrier may legitimately charge nothing, and that is a real answer.
        $this->repository->expects($this->once())
            ->method('recordFee')
            ->with(AdditionalService::CODE_COD_AMOUNT, 0.0, 100.0);

        $this->resolver->rememberFee(1, 100.0, 0.0);
    }

    public function testNothingIsRecordedWithoutABasis(): void
    {
        $this->repository->expects($this->never())->method('recordFee');

        $this->resolver->rememberFee(1, 0.0, 1.50);
    }

    private function serviceWithFee(?float $amount, ?float $basis): AdditionalService
    {
        $service = $this->createMock(AdditionalService::class);
        $rate = ($amount !== null && $basis !== null && $basis > 0) ? $amount / $basis : null;
        $service->method('getLastFeeRate')->willReturn($rate);

        return $service;
    }
}
