<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Observer\Shipment;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Model\Magento\CompleteOnDelivery;
use PigeonExpress\Shipping\Model\Magento\NativeShipment;
use PigeonExpress\Shipping\Observer\Shipment\CompleteOrderOnDelivery;
use PigeonExpress\Shipping\Observer\Shipment\CreateMagentoShipment;
use PigeonExpress\Shipping\Observer\Shipment\RemoveMagentoTrack;
use Psr\Log\LoggerInterface;

class ShipmentObserversTest extends TestCase
{
    private function observer(array $data): Observer
    {
        return new Observer(['event' => new Event($data)]);
    }

    public function testCreateNeedsAnOrderAndARow(): void
    {
        $native = $this->createMock(NativeShipment::class);
        $native->expects($this->never())->method('createFor');
        $observer = new CreateMagentoShipment($native, $this->createMock(LoggerInterface::class));

        $observer->execute($this->observer(['order' => null, 'shipment_row' => ['reference_number' => 'X']]));
        $observer->execute($this->observer(['order' => $this->createMock(Order::class), 'shipment_row' => null]));
    }

    public function testCreatePassesTheRowAndSwallowsFailures(): void
    {
        $order = $this->createMock(Order::class);
        $row = ['entity_id' => 1, 'reference_number' => 'X'];
        $native = $this->createMock(NativeShipment::class);
        $native->expects($this->once())->method('createFor')->with($order, $row)
            ->willThrowException(new \RuntimeException('boom'));
        $observer = new CreateMagentoShipment($native, $this->createMock(LoggerInterface::class));

        $observer->execute($this->observer(['order' => $order, 'shipment_row' => $row]));
    }

    public function testCancelRemovesTheTrack(): void
    {
        $order = $this->createMock(Order::class);
        $row = ['entity_id' => 1, 'reference_number' => 'X'];
        $native = $this->createMock(NativeShipment::class);
        $native->expects($this->once())->method('removeTrackFor')->with($order, $row);
        $observer = new RemoveMagentoTrack($native, $this->createMock(LoggerInterface::class));

        $observer->execute($this->observer(['order' => $order, 'shipment_row' => $row]));
    }

    public function testCompleteOnlyOnDelivery(): void
    {
        $complete = $this->createMock(CompleteOnDelivery::class);
        $complete->expects($this->never())->method('onDelivered');
        $observer = new CompleteOrderOnDelivery($complete, $this->createMock(LoggerInterface::class));

        $observer->execute($this->observer([
            'order' => $this->createMock(Order::class),
            'shipment_row' => ['reference_number' => 'X'],
            'old_code' => 'shipment_registered',
            'new_code' => 'shipment_in_delivery',
            'is_delivered' => false,
        ]));
    }

    public function testCompleteNotForTheSameCodeReportedAgain(): void
    {
        $complete = $this->createMock(CompleteOnDelivery::class);
        $complete->expects($this->never())->method('onDelivered');
        $observer = new CompleteOrderOnDelivery($complete, $this->createMock(LoggerInterface::class));

        $observer->execute($this->observer([
            'order' => $this->createMock(Order::class),
            'shipment_row' => ['reference_number' => 'X'],
            'old_code' => 'shipment_delivered_to_recipient',
            'new_code' => 'shipment_delivered_to_recipient',
            'is_delivered' => true,
        ]));
    }

    public function testCompleteOnANewDeliveryWithTheRow(): void
    {
        $order = $this->createMock(Order::class);
        $complete = $this->createMock(CompleteOnDelivery::class);
        $complete->expects($this->once())->method('onDelivered')->with($order, ['reference_number' => 'X']);
        $observer = new CompleteOrderOnDelivery($complete, $this->createMock(LoggerInterface::class));

        $observer->execute($this->observer([
            'order' => $order,
            'shipment_row' => ['reference_number' => 'X'],
            'old_code' => 'shipment_in_delivery',
            'new_code' => 'shipment_delivered_to_recipient',
            'is_delivered' => true,
        ]));
    }
}
