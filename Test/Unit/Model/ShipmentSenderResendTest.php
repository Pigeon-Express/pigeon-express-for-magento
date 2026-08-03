<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Model\ResourceModel\Shipment\Collection;
use PigeonExpress\Shipping\Model\ResourceModel\Shipment\CollectionFactory;
use PigeonExpress\Shipping\Model\Shipment;
use PigeonExpress\Shipping\Model\ShipmentCanceller;
use PigeonExpress\Shipping\Model\ShipmentSender;

/**
 * Sending is blocked while an order already has a waybill, which is correct, but
 * a cancelled waybill is not in transit. Without excluding it the order would be
 * permanently unshippable after a cancellation, with no way out from the admin.
 *
 * The ordering matters too: once an order can hold several rows, the newest is
 * the one that describes reality.
 */
class ShipmentSenderResendTest extends TestCase
{
    public function testCancelledShipmentsAreExcludedSoTheOrderCanBeSentAgain(): void
    {
        $filters = [];
        $sender = $this->sender($filters, $order);

        $this->invokeFind($sender, 10);

        $this->assertContains(
            ['status', ['neq' => ShipmentCanceller::STATUS_CANCELLED]],
            $filters,
            'a cancelled waybill must not block a new one'
        );
    }

    public function testTheOrderIsStillScopedToItsOwnShipments(): void
    {
        $filters = [];
        $sender = $this->sender($filters, $order);

        $this->invokeFind($sender, 10);

        $this->assertContains(['order_id', ['eq' => 10]], $filters);
    }

    public function testTheNewestShipmentWins(): void
    {
        $filters = [];
        $sender = $this->sender($filters, $order);

        $this->invokeFind($sender, 10);

        $this->assertSame(
            ['entity_id', 'DESC'],
            $order,
            'with several rows per order the current waybill is the newest one'
        );
    }

    // -----------------------------------------------------------------------

    /**
     * @param array<int, array{0:string, 1:mixed}> $filters Captured filter calls
     * @param array|null $order Captured sort call
     */
    private function sender(array &$filters, &$order): ShipmentSender
    {
        $order = null;

        $shipment = $this->getMockBuilder(Shipment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();
        $shipment->method('getId')->willReturn(null);

        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(
            static function ($field, $condition) use (&$filters, $collection) {
                $filters[] = [$field, $condition];
                return $collection;
            }
        );
        $collection->method('setOrder')->willReturnCallback(
            static function ($field, $direction) use (&$order, $collection) {
                $order = [$field, $direction];
                return $collection;
            }
        );
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($shipment);

        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return (new ObjectManager($this))->getObject(ShipmentSender::class, [
            'shipmentCollectionFactory' => $factory,
        ]);
    }

    private function invokeFind(ShipmentSender $sender, int $orderId): void
    {
        $method = (new \ReflectionClass(ShipmentSender::class))->getMethod('findExistingShipment');
        $method->setAccessible(true);
        $method->invoke($sender, $orderId);
    }
}
