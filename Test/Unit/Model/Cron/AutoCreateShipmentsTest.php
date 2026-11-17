<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Cron;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Status\History;
use Magento\Sales\Model\Order\Status\HistoryFactory;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Exceptions\ConnectionException;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Api\ShipmentSenderInterface;
use PigeonExpress\Shipping\Exception\ApiException;
use PigeonExpress\Shipping\Exception\OrderLockedException;
use PigeonExpress\Shipping\Exception\OutcomeUnknownException;
use PigeonExpress\Shipping\Model\AutoCreate\Queue;
use PigeonExpress\Shipping\Model\Cron\AutoCreateShipments;
use PigeonExpress\Shipping\Model\ResourceModel\Shipment\Collection;
use PigeonExpress\Shipping\Model\ResourceModel\Shipment\CollectionFactory;
use PigeonExpress\Shipping\Model\Shipment;
use Psr\Log\LoggerInterface;

class AutoCreateShipmentsTest extends TestCase
{
    /** @var string[] */
    private $comments = [];

    /** @var array<int, array{0:string, 1:string}> */
    private $finished = [];

    public function testASuccessIsSaidOnTheOrder(): void
    {
        $shipment = $this->getMockBuilder(Shipment::class)->disableOriginalConstructor()
            ->onlyMethods(['getReferenceNumber'])->getMock();
        $shipment->method('getReferenceNumber')->willReturn('PGN-1');
        $sender = $this->createMock(ShipmentSenderInterface::class);
        $sender->expects($this->once())->method('send')->willReturn($shipment);

        $this->job($sender)->run(42);

        $this->assertSame([Queue::STATUS_DONE, 'PGN-1'], $this->finished[0]);
        $this->assertStringContainsString('created automatically (PGN-1)', $this->comments[0]);
    }

    public function testARefusalBecomesAnOrderComment(): void
    {
        $sender = $this->createMock(ShipmentSenderInterface::class);
        $sender->method('send')->willThrowException(new LocalizedException(__('Please enter a phone number.')));

        $this->job($sender)->run(42);

        $this->assertSame(Queue::STATUS_FAILED, $this->finished[0][0]);
        $this->assertStringContainsString('could not be created automatically. Please enter a phone number.', $this->comments[0]);
    }

    public function testAnUnknownOutcomeIsNeverRepeated(): void
    {
        $sender = $this->createMock(ShipmentSenderInterface::class);
        $sender->expects($this->once())->method('send')->willThrowException(new OutcomeUnknownException(__('no answer')));
        $queue = null;

        $this->job($sender, 'processing', true, false, $queue)->run(42);

        $this->assertSame(Queue::STATUS_UNKNOWN, $this->finished[0][0]);
        $this->assertStringContainsString('not known whether', $this->comments[0]);
    }

    public function testARequestThatNeverLeftIsRetriedLater(): void
    {
        $sender = $this->createMock(ShipmentSenderInterface::class);
        $sender->method('send')->willThrowException(
            new ApiException(__('Could not reach Pigeon Express.'), new ConnectionException('refused'))
        );
        $queue = $this->createMock(Queue::class);
        $queue->method('attempts')->willReturn(1);
        $queue->expects($this->once())->method('retryLater')->with(42, 300);
        $queue->expects($this->never())->method('finish');

        $this->job($sender, 'processing', true, false, $queue)->run(42);

        $this->assertSame([], $this->comments);
    }

    public function testAnOrderBeingSentByHandIsTriedAgainInAMinute(): void
    {
        // Somebody pressed Send at the same moment: not a failure, no comment.
        $sender = $this->createMock(ShipmentSenderInterface::class);
        $sender->method('send')->willThrowException(new OrderLockedException(__('being created right now')));
        $queue = $this->createMock(Queue::class);
        $queue->expects($this->once())->method('retryLater')->with(42, 60);
        $queue->expects($this->never())->method('finish');

        $this->job($sender, 'processing', true, false, $queue)->run(42);

        $this->assertSame([], $this->comments);
    }

    public function testAnOrderThatLeftProcessingIsSkippedWithAComment(): void
    {
        $sender = $this->createMock(ShipmentSenderInterface::class);
        $sender->expects($this->never())->method('send');

        $this->job($sender, 'canceled')->run(42);

        $this->assertSame(Queue::STATUS_SKIPPED, $this->finished[0][0]);
        $this->assertStringContainsString('was skipped', $this->comments[0]);
    }

    public function testAShipmentMadeByHandMeanwhileIsNotMadeTwice(): void
    {
        $sender = $this->createMock(ShipmentSenderInterface::class);
        $sender->expects($this->never())->method('send');

        $this->job($sender, 'processing', true, true)->run(42);

        $this->assertSame(Queue::STATUS_SKIPPED, $this->finished[0][0]);
        $this->assertSame([], $this->comments);
    }

    public function testNothingHappensOnceTheSettingIsOff(): void
    {
        $sender = $this->createMock(ShipmentSenderInterface::class);
        $sender->expects($this->never())->method('send');

        $this->job($sender, 'processing', false)->run(42);

        $this->assertSame(Queue::STATUS_SKIPPED, $this->finished[0][0]);
        $this->assertSame([], $this->comments);
    }

    private function job(
        ShipmentSenderInterface $sender,
        string $state = 'processing',
        bool $enabled = true,
        bool $hasShipment = false,
        ?Queue $queue = null
    ): AutoCreateShipments {
        if ($queue === null) {
            $queue = $this->createMock(Queue::class);
            $queue->method('finish')->willReturnCallback(function (int $id, string $status, string $message = ''): void {
                $this->finished[] = [$status, $message];
            });
        }

        $order = $this->getMockBuilder(Order::class)->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getStoreId', 'getState', 'getStatus', 'getStatusLabel'])->getMock();
        $order->method('getId')->willReturn(42);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getState')->willReturn($state);
        $order->method('getStatus')->willReturn($state);
        $order->method('getStatusLabel')->willReturn(ucfirst($state));

        $orders = $this->createMock(OrderRepositoryInterface::class);
        $orders->method('get')->willReturn($order);

        $config = $this->createMock(ConfigInterface::class);
        $config->method('isAutoCreateEnabled')->willReturn($enabled);

        $history = $this->getMockBuilder(History::class)->disableOriginalConstructor()
            ->onlyMethods(['setComment', 'setParentId', 'setStatus', 'setEntityName', 'setIsCustomerNotified', 'setIsVisibleOnFront'])
            ->getMock();
        foreach (['setParentId', 'setStatus', 'setEntityName', 'setIsCustomerNotified', 'setIsVisibleOnFront'] as $m) {
            $history->method($m)->willReturnSelf();
        }
        $history->method('setComment')->willReturnCallback(function (string $text) use ($history) {
            $this->comments[] = $text;

            return $history;
        });
        $historyFactory = $this->createMock(HistoryFactory::class);
        $historyFactory->method('create')->willReturn($history);

        $row = $this->getMockBuilder(Shipment::class)->disableOriginalConstructor()->onlyMethods(['getId'])->getMock();
        $row->method('getId')->willReturn($hasShipment ? 5 : null);
        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($row);
        $shipments = $this->createMock(CollectionFactory::class);
        $shipments->method('create')->willReturn($collection);

        return new AutoCreateShipments(
            $queue,
            $orders,
            $sender,
            $config,
            $historyFactory,
            $this->createMock(OrderStatusHistoryRepositoryInterface::class),
            $this->createMock(LoggerInterface::class),
            $shipments
        );
    }
}
