<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Status;

use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Api\ShipmentClientInterface;
use PigeonExpress\Shipping\Model\ResourceModel\Shipment\CollectionFactory;
use PigeonExpress\Shipping\Model\Shipment;
use PigeonExpress\Shipping\Model\Shipment\RedirectFollower;
use PigeonExpress\Shipping\Model\Status\ShipmentDetailsStore;
use PigeonExpress\Shipping\Model\Status\StatusRecorder;
use PigeonExpress\Shipping\Model\Status\StatusRefresher;
use PigeonExpress\Shipping\Model\Status\TrackingInterpreter;
use Psr\Log\LoggerInterface;

/**
 * A shipment whose own status says redirected moves its order to the new
 * shipment before the chain is read as its status, at most five per call; a
 * redirected row is never asked about again.
 */
class StatusRefresherRedirectTest extends TestCase
{
    /**
     * @return Shipment[]
     */
    private function rows(int $count, string $status = 'shipment_registered'): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $row = $this->getMockBuilder(Shipment::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
            $row->setData(['id' => $i, 'order_id' => 100 + $i, 'reference_number' => 'R' . $i, 'status' => $status]);
            $rows[] = $row;
        }

        return $rows;
    }

    private function refresher(ShipmentClientInterface $client, StatusRecorder $recorder, RedirectFollower $follower): StatusRefresher
    {
        return new StatusRefresher(
            $client,
            $this->createMock(CollectionFactory::class),
            new TrackingInterpreter(),
            $recorder,
            $this->createMock(ShipmentDetailsStore::class),
            $this->createMock(ConfigInterface::class),
            $this->createMock(LoggerInterface::class),
            100,
            $follower
        );
    }

    private function redirecting(): ShipmentClientInterface
    {
        $client = $this->createMock(ShipmentClientInterface::class);
        $client->method('trackBulk')->willReturnCallback(static function (array $refs): array {
            $out = [];
            foreach ($refs as $ref) {
                // The chain would read as the new shipment's status.
                $out[$ref] = ['found' => true, 'reference_number' => $ref, 'status_code' => 'shipment_redirected',
                    'chain_after' => [['reference_number' => $ref . 'N', 'status_code' => 'shipment_in_delivery',
                        'created_at' => '2026-11-20T10:00:00+02:00']]];
            }

            return $out;
        });

        return $client;
    }

    public function testARedirectedShipmentMovesItsOrderFirst(): void
    {
        $follower = $this->createMock(RedirectFollower::class);
        $follower->expects(self::exactly(2))->method('follow')->willReturnCallback(static function (int $orderId, string $ref): string {
            return $ref . 'N';
        });
        $recorder = $this->createMock(StatusRecorder::class);
        $recorder->expects(self::never())->method('record');

        $result = $this->refresher($this->redirecting(), $recorder, $follower)->refreshShipments($this->rows(2));

        self::assertSame(2, $result['updated']);
        self::assertSame([101 => 'R1N', 102 => 'R2N'], $result['moved']);
    }

    public function testAtMostFiveMovesPerCallAndTheRestIsRecordedAsBefore(): void
    {
        $follower = $this->createMock(RedirectFollower::class);
        $follower->expects(self::exactly(StatusRefresher::MAX_REDIRECT_ADOPTIONS))->method('follow')->willReturn('NEW');
        $recorder = $this->createMock(StatusRecorder::class);
        $recorder->expects(self::exactly(2))->method('record')->willReturn(false);

        $result = $this->refresher($this->redirecting(), $recorder, $follower)->refreshShipments($this->rows(7));

        self::assertCount(5, $result['moved']);
        self::assertSame(5, $result['updated']);
        self::assertSame(2, $result['unchanged']);
    }

    public function testAMoveThatFindsNothingLeavesTheStatusToBeRead(): void
    {
        $follower = $this->createMock(RedirectFollower::class);
        $follower->method('follow')->willReturn('');
        $recorder = $this->createMock(StatusRecorder::class);
        $recorder->expects(self::once())->method('record')->willReturn(true);

        $result = $this->refresher($this->redirecting(), $recorder, $follower)->refreshShipments($this->rows(1));

        self::assertSame([], $result['moved']);
        self::assertSame(1, $result['updated']);
    }

    public function testRedirectedRowsAreNotAskedAbout(): void
    {
        $client = $this->createMock(ShipmentClientInterface::class);
        $client->expects(self::never())->method('trackBulk');

        $this->refresher($client, $this->createMock(StatusRecorder::class), $this->createMock(RedirectFollower::class))
            ->refreshShipments($this->rows(2, Shipment::STATUS_REDIRECTED));
    }

    public function testTheOwnStatusIsReadBeforeTheChain(): void
    {
        $interpreter = new TrackingInterpreter();
        $entry = ['status_code' => 'shipment_redirected', 'chain_after' => [['status_code' => 'shipment_in_delivery']]];

        self::assertSame('shipment_redirected', $interpreter->rootCode($entry));
        self::assertSame('', $interpreter->rootCode(['found' => false, 'status_code' => 'shipment_redirected']));
    }
}
