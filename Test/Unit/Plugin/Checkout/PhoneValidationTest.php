<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Plugin\Checkout;

use Magento\Checkout\Api\Data\ShippingInformationInterface;
use Magento\Checkout\Model\ShippingInformationManagement;
use Magento\Framework\Exception\InputException;
use Magento\Quote\Api\Data\AddressInterface;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Plugin\Checkout\ShippingInformationManagementValidationPlugin;
use Psr\Log\LoggerInterface;

/**
 * A phone Pigeon Express cannot deliver with is refused on the shipping step,
 * before any order exists, for Pigeon Express methods only.
 */
class PhoneValidationTest extends TestCase
{
    public function testABadPhoneIsRefusedForPigeonExpress(): void
    {
        $this->expectException(InputException::class);
        $this->expectExceptionMessage('valid Bulgarian phone number');

        $this->save('pigeonexpress', '12345');
    }

    public function testAnEmptyPhoneIsRefusedForPigeonExpress(): void
    {
        $this->expectException(InputException::class);

        $this->save('pigeonexpress', ' ');
    }

    public function testABulgarianPhoneIsAccepted(): void
    {
        $this->assertNull($this->save('pigeonexpress', '0881 234 567'));
    }

    public function testAnotherCarrierIsNotChecked(): void
    {
        $this->assertNull($this->save('flatrate', '12345'));
    }

    private function save(string $carrier, string $phone): ?array
    {
        $address = $this->getMockBuilder(AddressInterface::class)->getMockForAbstractClass();
        $address->method('getTelephone')->willReturn($phone);

        $info = $this->getMockBuilder(ShippingInformationInterface::class)->getMockForAbstractClass();
        $info->method('getShippingCarrierCode')->willReturn($carrier);
        $info->method('getShippingAddress')->willReturn($address);

        return (new ShippingInformationManagementValidationPlugin($this->createMock(LoggerInterface::class)))
            ->beforeSaveAddressInformation($this->createMock(ShippingInformationManagement::class), 5, $info);
    }
}
