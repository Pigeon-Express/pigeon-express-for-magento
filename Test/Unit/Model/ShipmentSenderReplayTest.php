<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;
use PigeonExpress\DTO\Response\ShipmentDetails;
use PigeonExpress\Shipping\Api\ShipmentClientInterface;
use PigeonExpress\Shipping\Exception\ApiException;
use PigeonExpress\Shipping\Exception\ReplayedShipmentException;
use PigeonExpress\Shipping\Model\ResourceModel\Shipment\Collection;
use PigeonExpress\Shipping\Model\ResourceModel\Shipment\CollectionFactory;
use PigeonExpress\Shipping\Model\Shipment;
use PigeonExpress\Shipping\Model\Shipment\OrderLock;
use PigeonExpress\Shipping\Model\ShipmentSender;

/**
 * Pigeon Express answers a create identical to one made less than 30 seconds
 * earlier with that first answer. When that shipment has been cancelled since,
 * storing the answer would put a dead waybill on the order.
 */
class ShipmentSenderReplayTest extends TestCase
{
    public function testAReplayOfAShipmentCancelledOnThisOrderIsRefusedWithoutAsking(): void
    {
        $client = $this->createMock(ShipmentClientInterface::class);
        $client->expects(self::never())->method('get');

        $this->expectException(ReplayedShipmentException::class);
        $this->sender($client, true)->refuseReplayedCancelled(37, 'R1', 1, 'earlier');
    }

    public function testAReplayTheCarrierReportsCancelledIsRefused(): void
    {
        $client = $this->createMock(ShipmentClientInterface::class);
        $client->method('get')->willReturn($this->details('shipment_cancelled'));

        $this->expectException(ReplayedShipmentException::class);
        $this->sender($client, false)->refuseReplayedCancelled(37, 'R1', 1, 'earlier');
    }

    public function testAReplayOfALiveShipmentIsKept(): void
    {
        $client = $this->createMock(ShipmentClientInterface::class);
        $client->method('get')->willReturn($this->details('shipment_registered'));

        $this->sender($client, false)->refuseReplayedCancelled(37, 'R1', 1, 'repeat');
        $this->addToAssertionCount(1);
    }

    public function testAReplayIsKeptWhenTheCarrierCannotBeAsked(): void
    {
        $client = $this->createMock(ShipmentClientInterface::class);
        $client->method('get')->willThrowException(new ApiException(__('down')));

        $this->sender($client, false)->refuseReplayedCancelled(37, 'R1', 1, 'earlier');
        $this->addToAssertionCount(1);
    }

    public function testASecondSendForTheSameOrderIsRefusedWhileTheFirstRuns(): void
    {
        $client = $this->createMock(ShipmentClientInterface::class);
        $client->expects(self::never())->method('create');

        $lock = $this->createMock(OrderLock::class);
        $lock->method('acquire')->willReturn(false);
        $lock->expects(self::never())->method('release');

        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getStoreId', 'getShippingMethod'])
            ->getMock();
        $order->method('getId')->willReturn(37);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getShippingMethod')->willReturn('pigeonexpress_address');

        $sender = (new ObjectManager($this))->getObject(ShipmentSender::class, [
            'shipmentClient' => $client,
            'orderLock' => $lock,
        ]);

        $this->expectException(LocalizedException::class);
        $sender->send($order);
    }

    public function testTheLockIsReleasedWhenTheSendFails(): void
    {
        $lock = $this->createMock(OrderLock::class);
        $lock->method('acquire')->willReturn(true);
        $lock->expects(self::once())->method('release')->with(37);

        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getStoreId', 'getShippingMethod', 'getShippingAddress'])
            ->getMock();
        $order->method('getId')->willReturn(37);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getShippingMethod')->willReturn('pigeonexpress_address');
        $order->method('getShippingAddress')->willReturn(null);

        $sender = (new ObjectManager($this))->getObject(ShipmentSender::class, [
            'shipmentCollectionFactory' => $this->collectionFactory(false),
            'orderLock' => $lock,
        ]);

        $this->expectException(LocalizedException::class);
        $sender->send($order);
    }

    // -----------------------------------------------------------------------

    private function sender(ShipmentClientInterface $client, bool $cancelledLocally): ShipmentSender
    {
        return (new ObjectManager($this))->getObject(ShipmentSender::class, [
            'shipmentClient' => $client,
            'shipmentCollectionFactory' => $this->collectionFactory($cancelledLocally),
        ]);
    }

    private function collectionFactory(bool $found): CollectionFactory
    {
        $row = $this->getMockBuilder(Shipment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();
        $row->method('getId')->willReturn($found ? 5 : null);

        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($row);

        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return $factory;
    }

    private function details(string $code): ShipmentDetails
    {
        return ShipmentDetails::fromArray([
            'reference_number' => 'R1',
            'status' => ['code' => $code, 'name' => ''],
        ]);
    }
}
