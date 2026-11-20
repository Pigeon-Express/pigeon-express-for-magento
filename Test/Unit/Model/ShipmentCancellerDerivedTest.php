<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model;

use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Exceptions\ValidationException;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Api\ShipmentClientInterface;
use PigeonExpress\Shipping\Exception\ApiException;
use PigeonExpress\Shipping\Model\ResourceModel\Shipment as ShipmentResource;
use PigeonExpress\Shipping\Model\ResourceModel\Shipment\Collection;
use PigeonExpress\Shipping\Model\ResourceModel\Shipment\CollectionFactory;
use PigeonExpress\Shipping\Model\Shipment;
use PigeonExpress\Shipping\Model\Shipment\OrderLock;
use PigeonExpress\Shipping\Model\ShipmentCanceller;
use PigeonExpress\Shipping\Model\Status\ShipmentDetailsStore;
use PigeonExpress\Shipping\Model\Status\StatusRecorder;
use PigeonExpress\Shipping\Model\Status\TrackingInterpreter;
use Psr\Log\LoggerInterface;

/**
 * A return or a redirect of another shipment cannot be cancelled by the
 * sender: the panel says so, and a 422 for one is said in the same words. A
 * cancel holds the order's lock, and never acts on a redirected row.
 */
class ShipmentCancellerDerivedTest extends TestCase
{
    /** @var ShipmentClientInterface&MockObject */
    private $client;

    /** @var ShipmentDetailsStore&MockObject */
    private $details;

    /** @var OrderLock&MockObject */
    private $lock;

    /** @var array<int, array<int, mixed>> */
    private $filters = [];

    protected function setUp(): void
    {
        $this->client = $this->createMock(ShipmentClientInterface::class);
        $this->details = $this->createMock(ShipmentDetailsStore::class);
        $this->lock = $this->createMock(OrderLock::class);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function row(array $data): Shipment
    {
        $row = $this->getMockBuilder(Shipment::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $row->setData($data + ['id' => 3, 'order_id' => 10, 'reference_number' => 'R1', 'status' => 'shipment_registered',
            'status_code' => 'shipment_registered']);

        return $row;
    }

    private function canceller(?Shipment $row): ShipmentCanceller
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(function (...$args) use ($collection) {
            $this->filters[] = $args;

            return $collection;
        });
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($row ?? $this->row(['id' => null]));
        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return new ShipmentCanceller(
            $this->client,
            $factory,
            $this->createMock(ShipmentResource::class),
            $this->createMock(ConfigInterface::class),
            $this->createMock(LoggerInterface::class),
            null,
            null,
            $this->lock,
            $this->details
        );
    }

    private function original(): string
    {
        return (string) json_encode([
            'fetched_at' => 1,
            'related' => [['reference' => 'R0', 'relation' => 'original']],
        ]);
    }

    public function testADerivedShipmentIsNotOfferedForCancelAndNotSent(): void
    {
        $this->lock->method('acquire')->willReturn(true);
        $row = $this->row(['details_json' => $this->original()]);
        $this->client->expects(self::never())->method('cancel');

        self::assertFalse($this->canceller($row)->isCancellable($row));
        self::assertTrue(ShipmentDetailsStore::isDerived($row));

        try {
            $this->canceller($row)->cancelForOrder(10, 1);
            self::fail('Cancelled a derived shipment');
        } catch (LocalizedException $e) {
            self::assertSame(ShipmentCanceller::derivedCancelMessage(), $e->getMessage());
        }
    }

    public function testA422ForADerivedShipmentIsSaidInThePanelsWords(): void
    {
        $this->lock->method('acquire')->willReturn(true);
        $this->lock->expects(self::once())->method('release')->with(10);
        $row = $this->row([]);
        $this->client->method('cancel')->willThrowException(
            new ApiException(__('Не може да се анулира.'), new ValidationException('Не може да се анулира.', [], 422))
        );
        // The fresh details name the original: kept, so Cancel goes.
        $this->details->expects(self::once())->method('fetch')->willReturnCallback(function (Shipment $shipment): array {
            $shipment->setData('details_json', $this->original());

            return [];
        });

        $this->expectExceptionMessage('This shipment is a return or a redirect. Pigeon Express does not allow cancelling it.');
        $this->canceller($row)->cancelForOrder(10, 1);
    }

    public function testA422ForAnOrdinaryShipmentKeepsTheCarriersWords(): void
    {
        $this->lock->method('acquire')->willReturn(true);
        $this->client->method('cancel')->willThrowException(
            new ApiException(__('Не може да се анулира.'), new ValidationException('Не може да се анулира.', [], 422))
        );
        $this->details->method('fetch')->willReturn([]);

        $this->expectExceptionMessage('Не може да се анулира.');
        $this->canceller($this->row([]))->cancelForOrder(10, 1);
    }

    public function testACancelWaitsForTheOrdersLock(): void
    {
        $this->lock->method('acquire')->willReturn(false);
        $this->client->expects(self::never())->method('cancel');

        $this->expectExceptionMessage('is being created or changed right now');
        $this->canceller($this->row([]))->cancelForOrder(10, 1);
    }

    public function testTheCurrentShipmentIsNeverARedirectedRow(): void
    {
        $this->canceller($this->row([]))->findShipment(10);

        self::assertContains(['status', ['neq' => Shipment::STATUS_REDIRECTED]], $this->filters);
    }

    public function testTheReceiverIsKeptAndShownOnOneLine(): void
    {
        $store = new ShipmentDetailsStore(
            $this->client,
            $this->createMock(ShipmentResource::class),
            $this->createMock(StatusRecorder::class),
            new TrackingInterpreter()
        );

        $trimmed = $store->trim([
            'receiver' => ['name' => ' <b>Ivan</b> Petrov ', 'phone' => '+359881234567'],
            'related_shipments' => [['reference_number' => 'R0', 'relation' => 'original']],
        ], 5);

        self::assertSame(['name' => 'Ivan Petrov', 'phone' => '+359881234567'], $trimmed['receiver']);
        self::assertSame('Ivan Petrov, +359881234567', ShipmentDetailsStore::receiverLine($trimmed));
        self::assertSame('+359881234567', ShipmentDetailsStore::receiverLine(['receiver' => ['name' => '', 'phone' => '+359881234567']]));
        self::assertSame('', ShipmentDetailsStore::receiverLine($store->trim([], 5)));
        self::assertTrue(ShipmentDetailsStore::hasOriginal($trimmed['related']));
        self::assertFalse(ShipmentDetailsStore::hasOriginal([['reference' => 'R2', 'relation' => 'redirected']]));
        self::assertFalse(ShipmentDetailsStore::isDerived(null));
    }
}
