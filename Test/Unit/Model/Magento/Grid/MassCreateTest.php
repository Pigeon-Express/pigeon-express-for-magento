<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Magento\Grid;

use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Api\ShipmentSenderInterface;
use PigeonExpress\Shipping\Model\Magento\Grid\MassCreate;
use PigeonExpress\Shipping\Model\Shipment;
use PigeonExpress\Shipping\Model\ShipmentCanceller;

class MassCreateTest extends TestCase
{
    /** @var ShipmentSenderInterface&MockObject */
    private $sender;

    /** @var ShipmentCanceller&MockObject */
    private $finder;

    protected function setUp(): void
    {
        $this->sender = $this->createMock(ShipmentSenderInterface::class);
        $this->finder = $this->createMock(ShipmentCanceller::class);
    }

    /**
     * @return Order&MockObject
     */
    private function order(int $id, string $method, string $state, string $statusLabel = 'Pending'): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getEntityId')->willReturn($id);
        $order->method('getIncrementId')->willReturn('00000000' . $id);
        $order->method('getShippingMethod')->willReturn($method);
        $order->method('getState')->willReturn($state);
        $order->method('getStatusLabel')->willReturn($statusLabel);

        return $order;
    }

    private function shipment(string $status): Shipment
    {
        $shipment = $this->createMock(Shipment::class);
        $shipment->method('getStatus')->willReturn($status);

        return $shipment;
    }

    public function testCountsCreatedSkippedAndFailed(): void
    {
        $processing = $this->order(1, 'pigeonexpress_office', Order::STATE_PROCESSING);
        $other = $this->order(2, 'flatrate_flatrate', Order::STATE_PROCESSING);
        $hasOne = $this->order(3, 'pigeonexpress_office', Order::STATE_PROCESSING);
        $unpaid = $this->order(4, 'pigeonexpress_office', Order::STATE_NEW);
        $cancelledBefore = $this->order(5, 'pigeonexpress_aps', Order::STATE_PROCESSING);

        $this->finder->method('findShipment')->willReturnMap([
            [1, null],
            [3, $this->shipment('shipment_registered')],
            [4, null],
            [5, $this->shipment(ShipmentCanceller::STATUS_CANCELLED)],
        ]);
        $this->sender->method('isCashOnDelivery')->willReturn(false);
        $this->sender->expects($this->exactly(2))->method('send');

        $mass = new MassCreate($this->sender, $this->finder);
        $summary = $mass->run([$processing, $other, $hasOne, $unpaid, $cancelledBefore]);

        $this->assertSame(2, $summary['created']);
        $this->assertSame(2, $summary['skipped']);
        $this->assertSame(['000000004'], array_map('strval', array_keys($summary['failed'])));
        $this->assertStringContainsString('Pending', $summary['failed']['000000004']);
    }

    public function testANewCashOnDeliveryOrderIsShipped(): void
    {
        $this->sender->method('isCashOnDelivery')->willReturn(true);
        $mass = new MassCreate($this->sender, $this->finder);

        $this->assertNull($mass->refusal($this->order(1, 'pigeonexpress_office', Order::STATE_NEW)));
    }

    public function testHeldOrCompleteOrdersAreRefused(): void
    {
        $this->sender->method('isCashOnDelivery')->willReturn(true);
        $mass = new MassCreate($this->sender, $this->finder);

        $this->assertNotNull($mass->refusal($this->order(1, 'pigeonexpress_office', Order::STATE_HOLDED)));
        $this->assertNotNull($mass->refusal($this->order(1, 'pigeonexpress_office', Order::STATE_COMPLETE)));
    }

    public function testAnApiErrorIsReportedForItsOrder(): void
    {
        $this->finder->method('findShipment')->willReturn(null);
        $this->sender->method('send')->willThrowException(new \RuntimeException('Phone is invalid'));
        $mass = new MassCreate($this->sender, $this->finder);

        $summary = $mass->run([$this->order(1, 'pigeonexpress_office', Order::STATE_PROCESSING)]);

        $this->assertSame(['000000001' => 'Phone is invalid'], $summary['failed']);
        $this->assertStringContainsString('#000000001 - Phone is invalid', $mass->summaryText($summary));
    }
}
