<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Pickup;

use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Exception\ApiException;
use PigeonExpress\Shipping\Model\Account\SavedAddresses;
use PigeonExpress\Shipping\Model\Pickup\PickupPayload;
use PigeonExpress\Shipping\Model\Shipment\HandInResolver;

class PickupPayloadTest extends TestCase
{
    public function testOfficePickup(): void
    {
        $payload = $this->builder(['getPickupType' => 'office', 'getPickupOfficeId' => 149])->build(1);

        $this->assertSame(['pickup_type' => 'office', 'pickup_office_id' => 149], $payload);
    }

    public function testALockerHandInSendsTheLockerIdInThePickupOfficeField(): void
    {
        $handIn = $this->createMock(HandInResolver::class);
        $handIn->method('resolve')->willReturn(['type' => 'locker', 'id' => 485, 'reason' => '']);

        $payload = $this->builder($this->lockerConfig(), $handIn)->build(1, [['weight' => 1.0]]);

        $this->assertSame(['pickup_type' => 'locker', 'pickup_office_id' => 485], $payload);
    }

    public function testAShipmentThatDoesNotFitGoesToTheFallbackOffice(): void
    {
        $handIn = $this->createMock(HandInResolver::class);
        $handIn->method('resolve')->willReturn(['type' => 'office', 'id' => 149, 'reason' => 'too big']);

        $payload = $this->builder($this->lockerConfig(), $handIn)->build(1, [['weight' => 40.0]]);

        $this->assertSame(['pickup_type' => 'office', 'pickup_office_id' => 149], $payload);
    }

    public function testALockerPickupWithoutAFallbackOfficeIsIncomplete(): void
    {
        $this->expectException(ApiException::class);

        $this->builder(['getPickupType' => 'locker', 'getPickupLockerId' => 485, 'getPickupFallbackOfficeId' => null])
            ->build(1);
    }

    public function testASavedAddressIsSentByIdOnCreate(): void
    {
        $payload = $this->builder(['getPickupType' => 'saved_address', 'getPickupSavedAddressId' => 12])->build(1);

        $this->assertSame(['pickup_type' => 'address', 'sender_address_id' => 12], $payload);
    }

    public function testASavedAddressIsSentAsTheAddressForAQuote(): void
    {
        $saved = $this->createMock(SavedAddresses::class);
        $saved->method('find')->with(12)->willReturn([
            'id' => 12, 'label' => 'Склад', 'is_default' => true,
            'pickup_address' => ['city_id' => 759, 'street_id' => 33, 'additional_info' => '-'],
        ]);

        $payload = $this->builder(['getPickupType' => 'saved_address', 'getPickupSavedAddressId' => 12], null, $saved)
            ->build(1, [], [], true);

        $this->assertSame(
            ['pickup_type' => 'address', 'pickup_address' => ['city_id' => 759, 'street_id' => 33, 'additional_info' => '-']],
            $payload
        );
    }

    public function testASavedAddressThatLeftTheAccountCannotBeQuoted(): void
    {
        $saved = $this->createMock(SavedAddresses::class);
        $saved->method('find')->willReturn(null);

        $this->expectException(ApiException::class);
        $this->builder(['getPickupType' => 'saved_address', 'getPickupSavedAddressId' => 12], null, $saved)
            ->build(1, [], [], true);
    }

    public function testTheTypedAddressIsKeptWhenTheAccountHasNoSavedAddresses(): void
    {
        $payload = $this->builder([
            'getPickupType'               => 'address',
            'getPickupAddressCityId'      => '759',
            'getPickupAddressStreetId'    => '33',
            'getPickupAddressStreetNumber' => '5',
        ])->build(1);

        $this->assertSame('address', $payload['pickup_type']);
        $this->assertSame(['city_id' => 759, 'street_number' => '5', 'street_id' => 33], $payload['pickup_address']);
    }

    /**
     * @return array<string, mixed>
     */
    private function lockerConfig(): array
    {
        return ['getPickupType' => 'locker', 'getPickupLockerId' => 485, 'getPickupFallbackOfficeId' => 149];
    }

    /**
     * @param array<string, mixed> $values
     */
    private function builder(array $values, ?HandInResolver $handIn = null, ?SavedAddresses $saved = null): PickupPayload
    {
        $config = $this->createMock(ConfigInterface::class);
        foreach ($values as $method => $value) {
            $config->method($method)->willReturn($value);
        }

        return new PickupPayload(
            $config,
            $handIn ?? $this->createMock(HandInResolver::class),
            $saved ?? $this->createMock(SavedAddresses::class)
        );
    }
}
