<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model;

use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Api\ShipmentClientInterface;
use PigeonExpress\Shipping\Model\ResourceModel\Shipment as ShipmentResource;
use PigeonExpress\Shipping\Model\ResourceModel\Shipment\Collection;
use PigeonExpress\Shipping\Model\ResourceModel\Shipment\CollectionFactory;
use PigeonExpress\Shipping\Model\Shipment;
use PigeonExpress\Shipping\Model\ShipmentCanceller;
use Psr\Log\LoggerInterface;

/**
 * Pigeon Express only allows cancelling before the courier collects the parcel.
 * Getting the guard wrong either blocks a legitimate cancellation or lets the
 * admin believe a collected parcel was stopped when it was not.
 */
class ShipmentCancellerTest extends TestCase
{
    /** @var ShipmentClientInterface&MockObject */
    private $client;

    /** @var ShipmentResource&MockObject */
    private $resource;

    protected function setUp(): void
    {
        $this->client = $this->createMock(ShipmentClientInterface::class);
        $this->resource = $this->createMock(ShipmentResource::class);
    }

    public function testARegisteredShipmentIsCancelledAndRecorded(): void
    {
        $shipment = $this->shipment('PGN-1', ShipmentCanceller::STATUS_REGISTERED);

        $this->client->expects($this->once())->method('cancel')->with('PGN-1', 1);
        $this->resource->expects($this->once())->method('save');

        $result = $this->canceller($shipment)->cancelForOrder(10, 1);

        $this->assertSame(ShipmentCanceller::STATUS_CANCELLED, $result->getStatus());
    }

    public function testTheModuleDefaultStatusIsAlsoCancellable(): void
    {
        // Rows written before the API status was recorded carry 'sent'.
        $shipment = $this->shipment('PGN-2', 'sent');

        $this->client->expects($this->once())->method('cancel');

        $this->canceller($shipment)->cancelForOrder(10, 1);
    }

    public function testACollectedShipmentIsRefusedWithoutCallingTheApi(): void
    {
        $shipment = $this->shipment('PGN-3', 'in_transit');

        $this->client->expects($this->never())->method('cancel');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/before the courier collects/');

        $this->canceller($shipment)->cancelForOrder(10, 1);
    }

    public function testCancellingTwiceIsRefused(): void
    {
        $shipment = $this->shipment('PGN-4', ShipmentCanceller::STATUS_CANCELLED);

        $this->client->expects($this->never())->method('cancel');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/already been cancelled/');

        $this->canceller($shipment)->cancelForOrder(10, 1);
    }

    public function testAnOrderWithNoShipmentIsRefused(): void
    {
        $this->client->expects($this->never())->method('cancel');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/no Pigeon Express shipment/');

        $this->canceller(null)->cancelForOrder(10, 1);
    }

    public function testAShipmentWithoutAReferenceNumberIsRefused(): void
    {
        $shipment = $this->shipment('', ShipmentCanceller::STATUS_REGISTERED);

        $this->client->expects($this->never())->method('cancel');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/no reference number/');

        $this->canceller($shipment)->cancelForOrder(10, 1);
    }

    public function testNothingIsSavedWhenTheApiRefuses(): void
    {
        // A local "cancelled" flag on a shipment the carrier still has would be
        // worse than no flag at all.
        $shipment = $this->shipment('PGN-5', ShipmentCanceller::STATUS_REGISTERED);

        $this->client->method('cancel')->willThrowException(new \RuntimeException('api said no'));
        $this->resource->expects($this->never())->method('save');

        $this->expectException(\RuntimeException::class);

        $this->canceller($shipment)->cancelForOrder(10, 1);
    }

    /**
     * @dataProvider cancellableProvider
     */
    public function testIsCancellable(string $status, bool $expected): void
    {
        $this->assertSame($expected, $this->canceller(null)->isCancellable($this->shipment('X', $status)));
    }

    public function cancellableProvider(): array
    {
        return [
            'registered' => [ShipmentCanceller::STATUS_REGISTERED, true],
            'sent'       => ['sent', true],
            'in transit' => ['in_transit', false],
            'delivered'  => ['delivered', false],
            'cancelled'  => [ShipmentCanceller::STATUS_CANCELLED, false],
        ];
    }

    // -----------------------------------------------------------------------

    private function shipment(string $reference, string $status): Shipment
    {
        // getStatus/setStatus/getReferenceNumber are declared on the model, so
        // they need onlyMethods. Status is held in a closure so setStatus is
        // observable by the assertions.
        $current = $status;

        $shipment = $this->getMockBuilder(Shipment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getReferenceNumber', 'getStatus', 'setStatus'])
            ->getMock();

        $shipment->method('getId')->willReturn(1);
        $shipment->method('getReferenceNumber')->willReturn($reference);
        $shipment->method('getStatus')->willReturnCallback(
            static function () use (&$current): string {
                return $current;
            }
        );
        $shipment->method('setStatus')->willReturnCallback(
            static function (string $new) use (&$current, $shipment) {
                $current = $new;
                return $shipment;
            }
        );

        return $shipment;
    }

    private function canceller(?Shipment $shipment): ShipmentCanceller
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn(
            $shipment ?? $this->emptyShipment()
        );

        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $config = $this->createMock(ConfigInterface::class);
        $config->method('isLoggingEnabled')->willReturn(false);

        return new ShipmentCanceller(
            $this->client,
            $factory,
            $this->resource,
            $config,
            $this->createMock(LoggerInterface::class)
        );
    }

    private function emptyShipment(): Shipment
    {
        $shipment = $this->getMockBuilder(Shipment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();
        $shipment->method('getId')->willReturn(null);

        return $shipment;
    }
}
