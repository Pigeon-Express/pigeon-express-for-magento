<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Magento;

use Magento\Sales\Api\Data\ShipmentInterface;
use Magento\Sales\Api\Data\ShipmentTrackCreationInterface;
use Magento\Sales\Api\Data\ShipmentTrackCreationInterfaceFactory;
use Magento\Sales\Api\Data\ShipmentTrackInterfaceFactory;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Api\ShipmentTrackRepositoryInterface;
use Magento\Sales\Api\ShipOrderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment\Track;
use Magento\Sales\Model\ResourceModel\Order\Shipment\Track\Collection as TrackCollection;
use Magento\Sales\Model\ResourceModel\Order\Shipment\Track\CollectionFactory as TrackCollectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Model\Magento\NativeShipment;
use PigeonExpress\Shipping\Model\Magento\OrderComment;
use PigeonExpress\Shipping\Model\Magento\Settings;
use PigeonExpress\Shipping\Model\Magento\ShipmentLink;
use Psr\Log\LoggerInterface;

class NativeShipmentTest extends TestCase
{
    /** @var Settings&MockObject */
    private $settings;

    /** @var ShipOrderInterface&MockObject */
    private $shipOrder;

    /** @var ShipmentTrackRepositoryInterface&MockObject */
    private $trackRepository;

    /** @var ShipmentTrackInterfaceFactory&MockObject */
    private $trackFactory;

    /** @var ShipmentRepositoryInterface&MockObject */
    private $shipmentRepository;

    /** @var TrackCollectionFactory&MockObject */
    private $trackCollectionFactory;

    /** @var ShipmentLink&MockObject */
    private $link;

    /** @var OrderComment&MockObject */
    private $comment;

    /** @var string[] */
    private $comments = [];

    /** @var Track[] */
    private $existingTracks = [];

    /** @var NativeShipment */
    private $service;

    /** @var array<string, array<int, Track>>|null Tracks by number, when a test tells them apart */
    private $tracksByNumber;

    /** @var string */
    private $askedNumber = '';

    protected function setUp(): void
    {
        $this->settings = $this->createMock(Settings::class);
        $this->settings->method('isCreateMagentoShipment')->willReturn(true);
        $this->shipOrder = $this->createMock(ShipOrderInterface::class);
        $this->trackRepository = $this->createMock(ShipmentTrackRepositoryInterface::class);
        $this->trackFactory = $this->getMockBuilder(ShipmentTrackInterfaceFactory::class)
            ->disableOriginalConstructor()->onlyMethods(['create'])->getMock();
        $this->shipmentRepository = $this->createMock(ShipmentRepositoryInterface::class);
        $shipment = $this->createMock(ShipmentInterface::class);
        $shipment->method('getIncrementId')->willReturn('000000099');
        $shipment->method('getOrderId')->willReturn(42);
        $this->shipmentRepository->method('get')->willReturn($shipment);

        $collection = $this->createMock(TrackCollection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(function ($field, $value) use ($collection) {
            if ($field === 'track_number') {
                $this->askedNumber = (string) $value;
            }

            return $collection;
        });
        $collection->method('getItems')->willReturnCallback(function () {
            return $this->tracksByNumber !== null
                ? ($this->tracksByNumber[$this->askedNumber] ?? [])
                : $this->existingTracks;
        });
        $this->trackCollectionFactory = $this->getMockBuilder(TrackCollectionFactory::class)
            ->disableOriginalConstructor()->onlyMethods(['create'])->getMock();
        $this->trackCollectionFactory->method('create')->willReturn($collection);

        $this->link = $this->createMock(ShipmentLink::class);
        $this->comment = $this->createMock(OrderComment::class);
        $this->comment->method('add')->willReturnCallback(function ($order, string $text) {
            $this->comments[] = $text;
        });

        $creation = $this->createMock(ShipmentTrackCreationInterface::class);
        $creationFactory = $this->getMockBuilder(ShipmentTrackCreationInterfaceFactory::class)
            ->disableOriginalConstructor()->onlyMethods(['create'])->getMock();
        $creationFactory->method('create')->willReturn($creation);

        $this->service = new NativeShipment(
            $this->settings,
            $this->shipOrder,
            $creationFactory,
            $this->trackFactory,
            $this->trackRepository,
            $this->shipmentRepository,
            $this->trackCollectionFactory,
            $this->link,
            $this->comment,
            $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * @return Order&MockObject
     */
    private function order(bool $canShip = true, bool $virtual = false, bool $hasShipments = false): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getEntityId')->willReturn(42);
        $order->method('getStoreId')->willReturn(1);
        $order->method('canShip')->willReturn($canShip);
        $order->method('getIsVirtual')->willReturn($virtual);
        $order->method('hasShipments')->willReturn($hasShipments);

        return $order;
    }

    private function row(array $extra = []): array
    {
        return $extra + ['entity_id' => 7, 'order_id' => 42, 'reference_number' => '460338382811'];
    }

    public function testDoesNothingWhenSwitchedOff(): void
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isCreateMagentoShipment')->willReturn(false);
        $this->setPrivate('settings', $settings);

        $this->shipOrder->expects($this->never())->method('execute');

        $this->assertSame(NativeShipment::RESULT_DISABLED, $this->service->createFor($this->order(), $this->row()));
        $this->assertSame([], $this->comments);
    }

    public function testShipOnDeliveryBypassesTheSettingWithoutEmailOrComment(): void
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isCreateMagentoShipment')->willReturn(false);
        $settings->method('isShipmentEmailEnabled')->willReturn(true);
        $this->setPrivate('settings', $settings);

        // Third argument false: no "shipped" email once the parcel arrived.
        $this->shipOrder->expects($this->once())->method('execute')
            ->with(42, [], false, false, null, $this->isType('array'))
            ->willReturn(11);
        $this->link->expects($this->once())->method('link')->with(7, 11);

        $result = $this->service->shipOnDelivery($this->order(), $this->row());

        $this->assertSame(
            ['result' => NativeShipment::RESULT_CREATED, 'shipment' => '000000099', 'error' => ''],
            $result
        );
        $this->assertSame([], $this->comments);
    }

    public function testShipOnDeliveryReportsAFailureInsteadOfCommenting(): void
    {
        $this->shipOrder->method('execute')->willThrowException(new \RuntimeException('boom'));

        $result = $this->service->shipOnDelivery($this->order(), $this->row());

        $this->assertSame(NativeShipment::RESULT_FAILED, $result['result']);
        $this->assertSame('boom', $result['error']);
        $this->assertSame([], $this->comments);
    }

    public function testNeedsAWaybillNumber(): void
    {
        $this->shipOrder->expects($this->never())->method('execute');

        $result = $this->service->createFor($this->order(), $this->row(['reference_number' => ' ']));

        $this->assertSame(NativeShipment::RESULT_NO_NUMBER, $result);
    }

    public function testNeverASecondShipmentForALinkedRow(): void
    {
        $this->shipOrder->expects($this->never())->method('execute');

        $result = $this->service->createFor($this->order(), $this->row(['magento_shipment_id' => 5]));

        $this->assertSame(NativeShipment::RESULT_ALREADY_LINKED, $result);
    }

    public function testNeverASecondShipmentWhenTheDatabaseAlreadyHasTheLink(): void
    {
        $this->link->method('linkedTo')->with(7)->willReturn(5);
        $this->shipOrder->expects($this->never())->method('execute');

        $this->assertSame(
            NativeShipment::RESULT_ALREADY_LINKED,
            $this->service->createFor($this->order(), $this->row())
        );
    }

    public function testATrackAlreadyCarryingTheNumberIsLinkedNotDuplicated(): void
    {
        $track = $this->createMock(Track::class);
        $track->method('getParentId')->willReturn(11);
        $this->existingTracks = [$track];

        $this->shipOrder->expects($this->never())->method('execute');
        $this->link->expects($this->once())->method('link')->with(7, 11);

        $this->assertSame(
            NativeShipment::RESULT_ALREADY_LINKED,
            $this->service->createFor($this->order(), $this->row())
        );
    }

    public function testCreatesTheShipmentWithTheTrackAndNotifiesAsMagentoIsSet(): void
    {
        $this->settings->method('isShipmentEmailEnabled')->with(1)->willReturn(true);
        $this->shipOrder->expects($this->once())->method('execute')
            ->with(
                42,
                [],
                true,
                false,
                null,
                $this->callback(function (array $tracks) {
                    return count($tracks) === 1 && $tracks[0] instanceof ShipmentTrackCreationInterface;
                })
            )
            ->willReturn(11);
        $this->link->expects($this->once())->method('link')->with(7, 11);

        $result = $this->service->createFor($this->order(), $this->row());

        $this->assertSame(NativeShipment::RESULT_CREATED, $result);
        $this->assertCount(1, $this->comments);
        $this->assertStringContainsString('000000099', $this->comments[0]);
    }

    public function testVirtualOrderIsSkippedWithAComment(): void
    {
        $this->shipOrder->expects($this->never())->method('execute');

        $result = $this->service->createFor($this->order(false, true), $this->row());

        $this->assertSame(NativeShipment::RESULT_SKIPPED, $result);
        $this->assertStringContainsString('nothing to ship', $this->comments[0]);
    }

    public function testShippedOrderWithoutAnEarlierWaybillIsSkipped(): void
    {
        $this->link->method('linkedForOrder')->willReturn([]);
        $this->shipOrder->expects($this->never())->method('execute');

        $result = $this->service->createFor($this->order(false, false, true), $this->row());

        $this->assertSame(NativeShipment::RESULT_SKIPPED, $result);
        $this->assertStringContainsString('already shipped', $this->comments[0]);
    }

    public function testShipmentOfACancelledWaybillGetsTheNewNumber(): void
    {
        $this->link->method('linkedForOrder')->with(42, 7)->willReturn([11]);
        $track = $this->createMock(Track::class);
        $this->trackFactory->method('create')->willReturn($track);
        $track->expects($this->once())->method('setTrackNumber')->with('460338382811');
        $track->expects($this->once())->method('setCarrierCode')->with('pigeonexpress');
        $this->trackRepository->expects($this->once())->method('save')->with($track);
        $this->shipOrder->expects($this->never())->method('execute');
        $this->link->expects($this->once())->method('link')->with(7, 11);

        $result = $this->service->createFor($this->order(false, false, true), $this->row());

        $this->assertSame(NativeShipment::RESULT_REUSED, $result);
    }

    public function testAFailureIsCommentedAndNeverThrown(): void
    {
        $this->shipOrder->method('execute')->willThrowException(new \RuntimeException('boom'));

        $result = $this->service->createFor($this->order(), $this->row());

        $this->assertSame(NativeShipment::RESULT_FAILED, $result);
        $this->assertStringContainsString('boom', $this->comments[0]);
    }

    public function testAnErrorAfterTheShipmentWasSavedKeepsTheLink(): void
    {
        // The shipment email fails after Magento saved the shipment and its track.
        $this->shipOrder->method('execute')->willReturnCallback(function () {
            $track = $this->createMock(Track::class);
            $track->method('getParentId')->willReturn(11);
            $this->existingTracks = [$track];
            throw new \RuntimeException('mail server down');
        });
        $this->link->expects($this->once())->method('link')->with(7, 11);

        $result = $this->service->createFor($this->order(), $this->row());

        $this->assertSame(NativeShipment::RESULT_CREATED, $result);
        $this->assertStringContainsString('mail server down', $this->comments[0]);
        $this->assertStringContainsString('000000099', $this->comments[0]);
    }

    public function testCancelRemovesTheTrackAndExplainsTheShipmentStays(): void
    {
        $track = $this->createMock(Track::class);
        $track->method('getParentId')->willReturn(11);
        $this->existingTracks = [$track];
        $this->trackRepository->expects($this->once())->method('delete')->with($track);

        $removed = $this->service->removeTrackFor($this->order(), $this->row());

        $this->assertSame(1, $removed);
        $this->assertStringContainsString('cannot delete a shipment', $this->comments[0]);
    }

    public function testCancelWithoutATrackSaysNothing(): void
    {
        $this->trackRepository->expects($this->never())->method('delete');

        $this->assertSame(0, $this->service->removeTrackFor($this->order(), $this->row()));
        $this->assertSame([], $this->comments);
    }

    public function testARedirectPutsTheNewNumberInPlaceOfTheOldOne(): void
    {
        $track = $this->createMock(Track::class);
        $track->method('getParentId')->willReturn(11);
        $track->expects($this->once())->method('setTrackNumber')->with('460350099999');
        $this->tracksByNumber = ['460338382811' => [$track]];
        $this->trackRepository->expects($this->once())->method('save')->with($track);
        $this->trackRepository->expects($this->never())->method('delete');
        $this->link->expects($this->once())->method('link')->with(8, 11);

        $replaced = $this->service->replaceTrackFor(
            $this->order(),
            $this->row(),
            ['entity_id' => 8, 'reference_number' => '460350099999']
        );

        $this->assertSame(1, $replaced);
        $this->assertStringContainsString('was redirected to 460350099999', $this->comments[0]);
        $this->assertStringContainsString('000000099', $this->comments[0]);
    }

    public function testARedirectReplayedOnlyLinksTheNewRow(): void
    {
        $old = $this->createMock(Track::class);
        $old->method('getParentId')->willReturn(11);
        $new = $this->createMock(Track::class);
        $new->method('getParentId')->willReturn(11);
        $this->tracksByNumber = ['460338382811' => [$old], '460350099999' => [$new]];
        $this->trackRepository->expects($this->never())->method('save');
        $this->trackRepository->expects($this->once())->method('delete')->with($old);
        $this->link->expects($this->once())->method('link')->with(8, 11);

        $this->service->replaceTrackFor($this->order(), $this->row(), ['entity_id' => 8, 'reference_number' => '460350099999']);
    }

    public function testARedirectWithoutAMagentoShipmentAddsNothing(): void
    {
        $this->tracksByNumber = [];
        $this->trackRepository->expects($this->never())->method('save');
        $this->link->expects($this->never())->method('link');

        $this->assertSame(0, $this->service->replaceTrackFor(
            $this->order(),
            $this->row(),
            ['entity_id' => 8, 'reference_number' => '460350099999']
        ));
        $this->assertSame([], $this->comments);
    }

    /**
     * @param mixed $value
     */
    private function setPrivate(string $name, $value): void
    {
        $property = new \ReflectionProperty(NativeShipment::class, $name);
        $property->setAccessible(true);
        $property->setValue($this->service, $value);
    }
}
