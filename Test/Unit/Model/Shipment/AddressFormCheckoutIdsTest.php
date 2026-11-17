<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Shipment;

use Magento\Sales\Api\Data\OrderAddressInterface;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\Api\AddressResolver;
use PigeonExpress\Shipping\Model\OrderAddressLocationPersistor;
use PigeonExpress\Shipping\Model\Rate\LocationToApiIdResolver;
use PigeonExpress\Shipping\Model\Shipment\AddressForm;

/**
 * The street picked from the carrier's list at checkout is used as picked,
 * not guessed again by its name, which two streets in Sofia share.
 */
class AddressFormCheckoutIdsTest extends TestCase
{
    public function testTheStoredStreetIsUsedAndNotGuessed(): void
    {
        $resolver = $this->getMockBuilder(AddressResolver::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCityById', 'matchStreet', 'matchCityId'])
            ->getMock();
        $resolver->method('getCityById')->willReturn(['id' => 759, 'name' => 'София', 'postal_code' => '1000']);
        $resolver->expects($this->never())->method('matchStreet');

        $persistor = $this->createMock(OrderAddressLocationPersistor::class);
        $persistor->method('getByAddressId')->with(77)->willReturn(['city_id' => '759', 'street_id' => '434699']);

        $form = new AddressForm(
            $resolver,
            $this->createMock(LocationToApiIdResolver::class),
            $this->createMock(ConfigInterface::class),
            $persistor
        );

        $fields = $form->fromOrder($this->address(), '', 'address');
        $this->assertSame('759', $fields['city_id']);
        $this->assertSame('434699', $fields['street_id']);

        $checked = $form->validate($fields, 1);
        $this->assertSame('434699', $checked['fields']['street_id']);
        $this->assertArrayNotHasKey('street', $checked['issues']);
    }

    public function testAnOrderWithoutStoredIdsIsReadAsBefore(): void
    {
        $persistor = $this->createMock(OrderAddressLocationPersistor::class);
        $persistor->method('getByAddressId')->willReturn(null);

        $form = new AddressForm(
            $this->getMockBuilder(AddressResolver::class)->disableOriginalConstructor()->onlyMethods([])->getMock(),
            $this->createMock(LocationToApiIdResolver::class),
            $this->createMock(ConfigInterface::class),
            $persistor
        );

        $fields = $form->fromOrder($this->address(), '', 'address');
        $this->assertSame('', $fields['street_id']);
        $this->assertSame('ВИТОША', $fields['street']);
    }

    private function address(): OrderAddressInterface
    {
        $address = $this->getMockBuilder(OrderAddressInterface::class)->getMockForAbstractClass();
        $address->method('getEntityId')->willReturn(77);
        $address->method('getFirstname')->willReturn('QA');
        $address->method('getLastname')->willReturn('Test');
        $address->method('getTelephone')->willReturn('0888123456');
        $address->method('getCity')->willReturn('София');
        $address->method('getPostcode')->willReturn('1000');
        $address->method('getStreet')->willReturn(['ВИТОША 10']);

        return $address;
    }
}
