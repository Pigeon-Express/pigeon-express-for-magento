<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Carrier;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\Carrier\PigeonExpress;

class PigeonExpressTest extends TestCase
{
    /** @var ConfigInterface&MockObject */
    private $config;

    /** @var PigeonExpress */
    private $carrier;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigInterface::class);

        $this->carrier = (new ObjectManager($this))->getObject(PigeonExpress::class, [
            'config' => $this->config,
        ]);
    }

    public function testAllowedMethodsMapsEachCodeToItsTitle(): void
    {
        // This used to return [carrier_code => "address,office,aps"], which breaks
        // any caller that iterates the map or looks a method up by its code.
        $this->config->method('getEnabledDeliveryTypes')->willReturn(['address', 'office', 'aps']);
        $this->config->method('getDeliveryTypeTitle')->willReturnMap([
            ['address', 0, 'Address Delivery'],
            ['office', 0, 'Office Delivery'],
            ['aps', 0, 'APS (Parcel Locker)'],
        ]);

        $methods = $this->carrier->getAllowedMethods();

        $this->assertSame(
            [
                'address' => 'Address Delivery',
                'office'  => 'Office Delivery',
                'aps'     => 'APS (Parcel Locker)',
            ],
            $methods
        );
    }

    public function testAllowedMethodsIsNotKeyedByTheCarrierCode(): void
    {
        $this->config->method('getEnabledDeliveryTypes')->willReturn(['office']);
        $this->config->method('getDeliveryTypeTitle')->willReturn('Office Delivery');

        $this->assertArrayNotHasKey('pigeonexpress', $this->carrier->getAllowedMethods());
    }

    public function testNoTitleContainsACommaSeparatedList(): void
    {
        $this->config->method('getEnabledDeliveryTypes')->willReturn(['address', 'office']);
        $this->config->method('getDeliveryTypeTitle')->willReturn('Some Title');

        foreach ($this->carrier->getAllowedMethods() as $title) {
            $this->assertStringNotContainsString(',', $title);
        }
    }

    public function testOnlyEnabledDeliveryTypesAreOffered(): void
    {
        $this->config->method('getEnabledDeliveryTypes')->willReturn(['office']);
        $this->config->method('getDeliveryTypeTitle')->willReturn('Office Delivery');

        $methods = $this->carrier->getAllowedMethods();

        $this->assertSame(['office'], array_keys($methods));
    }

    public function testTheCodeIsUsedWhenNoTitleIsConfigured(): void
    {
        $this->config->method('getEnabledDeliveryTypes')->willReturn(['aps']);
        $this->config->method('getDeliveryTypeTitle')->willReturn('');

        $this->assertSame(['aps' => 'aps'], $this->carrier->getAllowedMethods());
    }

    public function testNoDeliveryTypesEnabledYieldsAnEmptyMap(): void
    {
        $this->config->method('getEnabledDeliveryTypes')->willReturn([]);

        $this->assertSame([], $this->carrier->getAllowedMethods());
    }

    public function testTheAllowedMethodsFollowTheConfiguredOrder(): void
    {
        // The config sorts the list, and this map is what the admin order create
        // screen offers, so the merchant sees the same order in both places.
        $this->config->method('getEnabledDeliveryTypes')->willReturn(['office', 'aps', 'address']);
        $this->config->method('getDeliveryTypeTitle')->willReturnMap([
            ['address', 0, 'Address Delivery'],
            ['office', 0, 'Office Delivery'],
            ['aps', 0, 'APS (Parcel Locker)'],
        ]);

        $this->assertSame(['office', 'aps', 'address'], array_keys($this->carrier->getAllowedMethods()));
    }
}
