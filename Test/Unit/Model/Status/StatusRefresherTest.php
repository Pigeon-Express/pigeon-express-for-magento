<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Status;

use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Api\ShipmentClientInterface;
use PigeonExpress\Shipping\Exception\ApiException;
use PigeonExpress\Shipping\Exception\RateLimitedException;
use PigeonExpress\Shipping\Model\ResourceModel\Shipment\CollectionFactory;
use PigeonExpress\Shipping\Model\Shipment;
use PigeonExpress\Shipping\Model\Status\ShipmentDetailsStore;
use PigeonExpress\Shipping\Model\Status\StatusRecorder;
use PigeonExpress\Shipping\Model\Status\StatusRefresher;
use PigeonExpress\Shipping\Model\Status\TrackingInterpreter;
use Psr\Log\LoggerInterface;

/**
 * One bulk request per batch; one failing batch does not stop the rest; a
 * rate limit does.
 */
class StatusRefresherTest extends TestCase
{
    public function testShipmentsAreTrackedInBatches(): void
    {
        $client = $this->createMock(ShipmentClientInterface::class);
        $calls = [];
        $client->method('trackBulk')->willReturnCallback(function (array $refs) use (&$calls) {
            $calls[] = $refs;
            $out = [];
            foreach ($refs as $ref) {
                $out[$ref] = ['found' => true, 'status_code' => 'shipment_in_delivery'];
            }

            return $out;
        });

        $recorder = $this->createMock(StatusRecorder::class);
        $recorder->method('record')->willReturnOnConsecutiveCalls(true, false, false, false, false);

        $result = $this->refresher($client, $recorder, 2)->refreshShipments($this->rows(5));

        self::assertSame([['R1', 'R2'], ['R3', 'R4'], ['R5']], $calls);
        self::assertSame(1, $result['updated']);
        self::assertSame(4, $result['unchanged']);
        self::assertSame(0, $result['failed']);
    }

    public function testAFailingBatchDoesNotStopTheRest(): void
    {
        $client = $this->createMock(ShipmentClientInterface::class);
        $client->method('trackBulk')->willReturnCallback(function (array $refs) {
            if ($refs[0] === 'R1') {
                throw new ApiException(__('down'));
            }

            return array_fill_keys($refs, ['found' => true, 'status_code' => 'shipment_in_delivery']);
        });

        $recorder = $this->createMock(StatusRecorder::class);
        $recorder->method('record')->willReturn(false);

        $result = $this->refresher($client, $recorder, 2)->refreshShipments($this->rows(3));

        self::assertSame(2, $result['failed']);
        self::assertSame(1, $result['unchanged']);
        self::assertFalse($result['rate_limited']);
    }

    public function testARateLimitStopsTheRunAndCountsWhatIsLeft(): void
    {
        $client = $this->createMock(ShipmentClientInterface::class);
        $client->expects(self::once())->method('trackBulk')
            ->willThrowException(new RateLimitedException(__('slow down')));

        $recorder = $this->createMock(StatusRecorder::class);
        $recorder->expects(self::never())->method('record');

        $result = $this->refresher($client, $recorder, 2)->refreshShipments($this->rows(5));

        self::assertTrue($result['rate_limited']);
        self::assertSame(5, $result['failed']);
    }

    public function testAnUnknownReferenceIsCountedNotFound(): void
    {
        $client = $this->createMock(ShipmentClientInterface::class);
        $client->method('trackBulk')->willReturn(['R1' => ['found' => false]]);

        $recorder = $this->createMock(StatusRecorder::class);
        $recorder->expects(self::never())->method('record');

        $result = $this->refresher($client, $recorder)->refreshShipments($this->rows(1));

        self::assertSame(1, $result['not_found']);
    }

    public function testCancelledShipmentsAreNotAskedAbout(): void
    {
        $client = $this->createMock(ShipmentClientInterface::class);
        $client->expects(self::never())->method('trackBulk');

        $row = $this->rows(1)[0];
        $row->setStatus('cancelled');

        $this->refresher($client, $this->createMock(StatusRecorder::class))->refreshShipments([$row]);
    }

    public function testDetailsAreReadOnlyForAChangeIntoAFinalStatus(): void
    {
        $refresher = $this->refresher(
            $this->createMock(ShipmentClientInterface::class),
            $this->createMock(StatusRecorder::class)
        );

        self::assertTrue($refresher->wantsDetails('shipment_in_delivery', 'shipment_delivered_to_recipient'));
        self::assertTrue($refresher->wantsDetails('shipment_in_delivery', 'shipment_returned'));
        self::assertFalse($refresher->wantsDetails('shipment_registered', 'shipment_in_delivery'));
        // First check after an update: no GET for every old shipment.
        self::assertFalse($refresher->wantsDetails('', 'shipment_delivered_to_recipient'));
        self::assertFalse($refresher->wantsDetails('shipment_returned', 'shipment_returned'));
    }

    public function testAFirstSyncReadsNoDetails(): void
    {
        $details = $this->createMock(ShipmentDetailsStore::class);
        $details->expects(self::never())->method('fetch');

        $recorder = $this->createMock(StatusRecorder::class);
        $recorder->method('record')->willReturn(true);

        $result = $this->refresher($this->delivering(), $recorder, 100, $details)
            ->refreshShipments($this->rows(5));

        self::assertSame(5, $result['updated']);
    }

    public function testARateLimitOnDetailsStopsTheRestOfTheReads(): void
    {
        $details = $this->createMock(ShipmentDetailsStore::class);
        $details->expects(self::once())->method('fetch')
            ->willThrowException(new RateLimitedException(__('slow down')));

        $recorder = $this->createMock(StatusRecorder::class);
        $recorder->method('record')->willReturn(true);

        $rows = $this->rows(3);
        foreach ($rows as $row) {
            $row->setData('status_code', 'shipment_in_delivery');
        }

        $result = $this->refresher($this->delivering(), $recorder, 100, $details)->refreshShipments($rows);

        self::assertSame(3, $result['updated']);
    }

    public function testABulkRefreshReadsAtMostTheLimitAndCountsTheRest(): void
    {
        $collection = $this->createMock(\PigeonExpress\Shipping\Model\ResourceModel\Shipment\Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('getItems')->willReturn($this->rows(5));
        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $asked = [];
        $client = $this->createMock(ShipmentClientInterface::class);
        $client->method('trackBulk')->willReturnCallback(static function (array $refs) use (&$asked) {
            $asked = $refs;

            return [];
        });

        $refresher = new StatusRefresher(
            $client,
            $factory,
            new TrackingInterpreter(),
            $this->createMock(StatusRecorder::class),
            $this->createMock(ShipmentDetailsStore::class),
            $this->createMock(ConfigInterface::class),
            $this->createMock(LoggerInterface::class)
        );

        $result = $refresher->refreshOrders([101, 102, 103, 104, 105], 3);

        self::assertSame(['R1', 'R2', 'R3'], $asked);
        self::assertSame(2, $result['left_out']);
    }

    private function delivering(): ShipmentClientInterface
    {
        $client = $this->createMock(ShipmentClientInterface::class);
        $client->method('trackBulk')->willReturnCallback(static function (array $refs) {
            $out = [];
            foreach ($refs as $ref) {
                $out[$ref] = ['found' => true, 'status_code' => 'shipment_delivered_to_recipient'];
            }

            return $out;
        });

        return $client;
    }

    /**
     * @return Shipment[]
     */
    private function rows(int $count): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $row = $this->getMockBuilder(Shipment::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['getId'])
                ->getMock();
            $row->method('getId')->willReturn($i);
            $row->setData(['entity_id' => $i, 'order_id' => 100 + $i, 'reference_number' => 'R' . $i,
                'status' => 'shipment_registered']);
            $rows[] = $row;
        }

        return $rows;
    }

    private function refresher(
        ShipmentClientInterface $client,
        StatusRecorder $recorder,
        int $batch = 100,
        ?ShipmentDetailsStore $details = null
    ): StatusRefresher {
        return new StatusRefresher(
            $client,
            $this->createMock(CollectionFactory::class),
            new TrackingInterpreter(),
            $recorder,
            $details ?? $this->createMock(ShipmentDetailsStore::class),
            $this->createMock(ConfigInterface::class),
            $this->createMock(LoggerInterface::class),
            $batch
        );
    }
}
