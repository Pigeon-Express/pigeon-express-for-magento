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
use PigeonExpress\Shipping\Exception\NotEditableException;
use PigeonExpress\Shipping\Exception\ReplayedShipmentException;
use PigeonExpress\Shipping\Model\ResourceModel\Shipment as ShipmentResource;
use PigeonExpress\Shipping\Model\Shipment;
use PigeonExpress\Shipping\Model\Shipment\OrderLock;
use PigeonExpress\Shipping\Model\ShipmentCanceller;
use PigeonExpress\Shipping\Model\ShipmentEditor;
use PigeonExpress\Shipping\Model\ShipmentFactory;
use PigeonExpress\Shipping\Model\ShipmentSender;
use PigeonExpress\Shipping\Model\Status\ShipmentDetailsStore;
use PigeonExpress\Shipping\Model\Status\StatusRefresher;

/**
 * Pigeon Express takes a change only while the shipment is newly registered,
 * and answers a change identical to one sent less than 30 seconds earlier
 * with that earlier result, without acting on it.
 */
class ShipmentEditorTest extends TestCase
{
    public function testARegisteredOrUnreadShipmentCanBeChanged(): void
    {
        $editor = $this->editor();

        self::assertSame('', $editor->editBlockReason($this->row('shipment_registered')));
        self::assertSame('', $editor->editBlockReason($this->row('')));
    }

    public function testAShipmentThatMovedOnCannotBeChanged(): void
    {
        $editor = $this->editor();

        self::assertStringContainsString(
            'until the courier picks it up',
            $editor->editBlockReason($this->row('shipment_courier_assigned'))
        );
        self::assertNotSame('', $editor->editBlockReason($this->row('shipment_registered', 'cancelled')));
        self::assertNotSame('', $editor->editBlockReason(null));
    }

    public function testABlockedShipmentIsRefusedWithoutCallingTheCarrier(): void
    {
        $client = $this->createMock(ShipmentClientInterface::class);
        $client->expects(self::never())->method('update');
        $sender = $this->createMock(ShipmentSender::class);
        $sender->expects(self::never())->method('payloadFor');

        $this->expectException(NotEditableException::class);
        $this->editor($client, $sender, $this->row('shipment_in_delivery'))->update($this->order(), []);
    }

    public function testASecondChangeIsRefusedWhileTheFirstRuns(): void
    {
        $client = $this->createMock(ShipmentClientInterface::class);
        $client->expects(self::never())->method('update');
        $lock = $this->createMock(OrderLock::class);
        $lock->method('acquire')->willReturn(false);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/being created or changed/');
        $this->editor($client, null, $this->row('shipment_registered'), $lock)->update($this->order(), []);
    }

    public function testTheChangeLeavesOutWhatTheCarrierIgnores(): void
    {
        $client = $this->createMock(ShipmentClientInterface::class);
        $client->expects(self::once())->method('update')
            ->with('PGN-1', self::callback(function (array $payload): bool {
                return !isset($payload['pickup_type'], $payload['external_reference'])
                    && !array_key_exists('pickup_office_id', $payload)
                    && $payload['receiver_phone'] === '+359888111222';
            }), 1)
            ->willReturn(['details' => ShipmentDetails::fromArray([]), 'replay' => '']);

        $sender = $this->createMock(ShipmentSender::class);
        $sender->method('payloadFor')->willReturn([
            'payload' => [
                'receiver_phone'     => '+359888111222',
                'pickup_type'        => 'office',
                'pickup_office_id'   => 149,
                'external_reference' => '000000123',
                'packages'           => [['weight' => 2.0]],
            ],
            'fields'  => ['contact_phone' => '+359888111222'],
        ]);

        $row = $this->row('shipment_registered');
        $result = $this->editor($client, $sender, $row)->update($this->order(), []);

        $stored = json_decode((string) $result->getPayload(), true);
        // Kept as created: the carrier did not change it.
        self::assertSame(149, $stored['pickup_office_id']);
        self::assertSame('+359888111222', $stored['receiver_phone']);
    }

    public function testAReplayedChangeIsNotRecordedAndTheShipmentIsReadAgain(): void
    {
        $client = $this->createMock(ShipmentClientInterface::class);
        $client->method('update')->willReturn([
            'details' => ShipmentDetails::fromArray(['status' => ['code' => 'shipment_registered']]),
            'replay'  => 'earlier',
        ]);
        $sender = $this->createMock(ShipmentSender::class);
        $sender->method('payloadFor')->willReturn(['payload' => ['receiver_phone' => 'x'], 'fields' => []]);

        $resource = $this->createMock(ShipmentResource::class);
        $resource->expects(self::never())->method('save');
        $details = $this->createMock(ShipmentDetailsStore::class);
        $details->expects(self::once())->method('fetch');
        $details->expects(self::never())->method('remember');

        $this->expectException(ReplayedShipmentException::class);
        $this->expectExceptionMessageMatches('/Wait 30 seconds/');
        $this->editor($client, $sender, $this->row('shipment_registered'), null, $resource, $details)
            ->update($this->order(), []);
    }

    public function testARefusalBecauseItMovedOnReadsTheStatus(): void
    {
        $client = $this->createMock(ShipmentClientInterface::class);
        $client->method('update')->willThrowException(new NotEditableException(__('moved on')));
        $sender = $this->createMock(ShipmentSender::class);
        $sender->method('payloadFor')->willReturn(['payload' => [], 'fields' => []]);
        $refresher = $this->createMock(StatusRefresher::class);
        $refresher->expects(self::once())->method('refreshShipments');

        $this->expectException(NotEditableException::class);
        $this->editor($client, $sender, $this->row('shipment_registered'), null, null, null, $refresher)
            ->update($this->order(), []);
    }

    private function row(string $code, string $status = 'shipment_registered'): Shipment
    {
        $row = $this->getMockBuilder(Shipment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();
        $row->method('getId')->willReturn(5);
        $row->setData([
            'entity_id'        => 5,
            'order_id'         => 37,
            'reference_number' => 'PGN-1',
            'status'           => $status,
            'status_code'      => $code !== '' ? $code : null,
            'payload'          => json_encode(['pickup_type' => 'office', 'pickup_office_id' => 149]),
        ]);

        return $row;
    }

    private function order(): Order
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getStoreId'])
            ->getMock();
        $order->method('getId')->willReturn(37);
        $order->method('getStoreId')->willReturn(1);

        return $order;
    }

    private function editor(
        ?ShipmentClientInterface $client = null,
        ?ShipmentSender $sender = null,
        ?Shipment $row = null,
        ?OrderLock $lock = null,
        ?ShipmentResource $resource = null,
        ?ShipmentDetailsStore $details = null,
        ?StatusRefresher $refresher = null
    ): ShipmentEditor {
        if ($lock === null) {
            $lock = $this->createMock(OrderLock::class);
            $lock->method('acquire')->willReturn(true);
        }
        $canceller = $this->createMock(ShipmentCanceller::class);
        $canceller->method('findShipment')->willReturn($row);

        // A reload returns the same row.
        $factory = $this->createMock(ShipmentFactory::class);
        $factory->method('create')->willReturnCallback(function () use ($row) {
            return $row;
        });

        return (new ObjectManager($this))->getObject(ShipmentEditor::class, [
            'sender'          => $sender ?? $this->createMock(ShipmentSender::class),
            'client'          => $client ?? $this->createMock(ShipmentClientInterface::class),
            'canceller'       => $canceller,
            'resource'        => $resource ?? $this->createMock(ShipmentResource::class),
            'shipmentFactory' => $factory,
            'lock'            => $lock,
            'details'         => $details ?? $this->createMock(ShipmentDetailsStore::class),
            'refresher'       => $refresher ?? $this->createMock(StatusRefresher::class),
        ]);
    }
}
