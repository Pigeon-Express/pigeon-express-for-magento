<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Magento;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Shipping\Model\Tracking\Result\StatusFactory;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Model\Magento\TrackingInfo;
use PigeonExpress\Shipping\Model\Magento\TrackingUrl;
use PigeonExpress\Shipping\Model\Status\StatusConfig;
use PigeonExpress\Shipping\Model\Status\StatusNames;

class TrackingInfoTest extends TestCase
{
    private function info(bool $sync): TrackingInfo
    {
        $config = $this->createMock(StatusConfig::class);
        $config->method('isStatusSyncEnabled')->willReturn($sync);
        $names = $this->createMock(StatusNames::class);
        $names->method('label')->willReturnCallback(function (string $code) {
            return $code === 'shipment_delivered_to_recipient' ? 'Delivered' : 'Other';
        });
        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->method('formatDateTime')->willReturn('16 Nov 2026, 14:05');

        return new TrackingInfo(
            $this->getMockBuilder(StatusFactory::class)->disableOriginalConstructor()->getMock(),
            $this->createMock(ResourceConnection::class),
            $config,
            $names,
            new TrackingUrl(),
            $timezone
        );
    }

    public function testStatusWithItsTime(): void
    {
        $text = $this->info(true)->statusText([
            'status' => 'sent',
            'status_code' => 'shipment_delivered_to_recipient',
            'status_at' => '2026-11-16 12:05:00',
        ]);

        $this->assertSame('Delivered (16 Nov 2026, 14:05)', $text);
    }

    public function testNoStatusWhenTheBackgroundCheckIsOff(): void
    {
        $this->assertSame('', $this->info(false)->statusText([
            'status' => 'sent',
            'status_code' => 'shipment_delivered_to_recipient',
        ]));
    }

    public function testACancelledWaybillSaysSo(): void
    {
        $this->assertSame('Cancelled', $this->info(false)->statusText(['status' => 'cancelled']));
    }

    public function testTheSamePageAsWooCommerce(): void
    {
        $this->assertSame(
            'https://track.pigeonexpress.com/?tracking_number=460338382811',
            (new TrackingUrl())->forNumber('460338382811')
        );
    }
}
