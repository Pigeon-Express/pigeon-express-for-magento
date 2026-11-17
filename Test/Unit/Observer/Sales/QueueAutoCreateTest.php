<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Observer\Sales;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Api\ShipmentSenderInterface;
use PigeonExpress\Shipping\Model\AutoCreate\Queue;
use PigeonExpress\Shipping\Observer\Sales\QueueAutoCreate;
use Psr\Log\LoggerInterface;

class QueueAutoCreateTest extends TestCase
{
    public function testAnOrderThatBecomesProcessingIsQueued(): void
    {
        $queue = $this->createMock(Queue::class);
        $queue->expects($this->once())->method('add')->with(42)->willReturn(true);

        $this->observer($queue)->execute($this->event($this->order('new', 'processing')));
    }

    public function testAnOrderAlreadyProcessingIsNotQueuedAgain(): void
    {
        $queue = $this->createMock(Queue::class);
        $queue->expects($this->never())->method('add');

        $this->observer($queue)->execute($this->event($this->order('processing', 'processing')));
    }

    public function testACashOnDeliveryOrderIsQueuedWhenPlaced(): void
    {
        $queue = $this->createMock(Queue::class);
        $queue->expects($this->once())->method('add')->with(42)->willReturn(true);

        $this->observer($queue, true, true)->execute($this->event($this->order(null, 'new')));
    }

    public function testAPendingOrderPaidOnlineIsNotQueued(): void
    {
        $queue = $this->createMock(Queue::class);
        $queue->expects($this->never())->method('add');

        $this->observer($queue, true, false)->execute($this->event($this->order(null, 'new')));
    }

    public function testAPendingCashOnDeliveryOrderSavedAgainIsNotQueuedAgain(): void
    {
        $queue = $this->createMock(Queue::class);
        $queue->expects($this->never())->method('add');

        $this->observer($queue, true, true)->execute($this->event($this->order('new', 'new')));
    }

    public function testAnotherCarrierIsIgnored(): void
    {
        $queue = $this->createMock(Queue::class);
        $queue->expects($this->never())->method('add');

        $this->observer($queue)->execute($this->event($this->order('new', 'processing', 'flatrate_flatrate')));
    }

    public function testNothingIsQueuedWhenTheSettingIsOff(): void
    {
        $queue = $this->createMock(Queue::class);
        $queue->expects($this->never())->method('add');

        $this->observer($queue, false)->execute($this->event($this->order('new', 'processing')));
    }

    public function testAQueueFailureNeverBreaksTheOrderSave(): void
    {
        $queue = $this->createMock(Queue::class);
        $queue->method('add')->willThrowException(new \RuntimeException('db down'));

        $this->observer($queue)->execute($this->event($this->order('new', 'processing')));
        $this->addToAssertionCount(1);
    }

    private function observer(Queue $queue, bool $enabled = true, bool $cod = false): QueueAutoCreate
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('isAutoCreateEnabled')->willReturn($enabled);
        $sender = $this->createMock(ShipmentSenderInterface::class);
        $sender->method('isCashOnDelivery')->willReturn($cod);

        return new QueueAutoCreate($config, $queue, $this->createMock(LoggerInterface::class), $sender);
    }

    private function order(?string $before, string $now, string $method = 'pigeonexpress_office'): Order
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getState', 'getOrigData', 'getShippingMethod', 'getStoreId', 'getIncrementId'])
            ->getMock();
        $order->method('getId')->willReturn(42);
        $order->method('getState')->willReturn($now);
        $order->method('getOrigData')->willReturn($before);
        $order->method('getShippingMethod')->willReturn($method);
        $order->method('getStoreId')->willReturn(1);

        return $order;
    }

    private function event(Order $order): Observer
    {
        $event = new Event(['order' => $order]);

        return new Observer(['event' => $event]);
    }
}
