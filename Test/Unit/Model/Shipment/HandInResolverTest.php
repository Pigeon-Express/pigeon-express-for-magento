<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Shipment;

use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\AdditionalServiceRepository;
use PigeonExpress\Shipping\Model\Service\CarrierLimits;
use PigeonExpress\Shipping\Model\Shipment\HandInResolver;

class HandInResolverTest extends TestCase
{
    private const SMALL = ['weight' => 2.0, 'length' => 30.0, 'width' => 20.0, 'height' => 15.0];

    public function testAShipmentThatFitsGoesToTheLocker(): void
    {
        $point = $this->resolver()->resolve([self::SMALL], 1, ['cod_amount' => 50.0, 'sms_notification_receiver' => true]);

        $this->assertSame(['type' => 'locker', 'id' => 485, 'reason' => ''], $point);
    }

    public function testTwoPackagesGoToTheFallbackOffice(): void
    {
        $point = $this->resolver()->resolve([self::SMALL, self::SMALL], 1);

        $this->assertSame('office', $point['type']);
        $this->assertSame(149, $point['id']);
        $this->assertSame('the shipment is more than one package', $point['reason']);
    }

    public function testTooHeavyGoesToTheOffice(): void
    {
        $point = $this->resolver()->resolve([['weight' => 31.0] + self::SMALL], 1);

        $this->assertSame('office', $point['type']);
        $this->assertSame('the shipment is over 30 kg', $point['reason']);
    }

    public function testLargerThanTheCompartmentGoesToTheOffice(): void
    {
        $point = $this->resolver()->resolve([['length' => 70.0] + self::SMALL], 1);

        $this->assertSame('office', $point['type']);
        $this->assertSame('the shipment is larger than 61 x 37 x 44 cm', $point['reason']);
    }

    public function testAParcelTurnedRoundStillFits(): void
    {
        $point = $this->resolver()->resolve([['length' => 44.0, 'width' => 61.0, 'height' => 37.0, 'weight' => 1.0]], 1);

        $this->assertSame('locker', $point['type']);
    }

    public function testAServiceALockerDoesNotTakeGoesToTheOffice(): void
    {
        $point = $this->resolver()->resolve([self::SMALL], 1, ['declared_value' => 100.0, 'return_receipt' => true]);

        $this->assertSame('office', $point['type']);
        $this->assertSame('a parcel locker does not take declared_value, return_receipt', $point['reason']);
    }

    public function testAnotherPickupTypeIsTheOfficeWithoutAReason(): void
    {
        $point = $this->resolver(ConfigInterface::PICKUP_TYPE_OFFICE)->resolve([self::SMALL, self::SMALL], 1);

        $this->assertSame(['type' => 'office', 'id' => 200, 'reason' => ''], $point);
    }

    private function resolver(string $pickupType = ConfigInterface::PICKUP_TYPE_LOCKER): HandInResolver
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('getPickupType')->willReturn($pickupType);
        $config->method('getPickupLockerId')->willReturn(485);
        $config->method('getPickupFallbackOfficeId')->willReturn(149);
        $config->method('getPickupOfficeId')->willReturn(200);
        $config->method('getLockerCompartment')->willReturn(['length' => 44.0, 'width' => 61.0, 'height' => 37.0]);

        $limits = $this->createMock(CarrierLimits::class);
        $limits->method('maxWeight')->willReturn(30.0);

        $services = $this->createMock(AdditionalServiceRepository::class);
        $services->method('getByCode')->willReturn(null);

        return new HandInResolver($config, $limits, $services);
    }
}
