<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\TestCase;
use PigeonExpress\DTO\Response\ShipmentDetails;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Api\LocationSearchInterface;
use PigeonExpress\Shipping\Api\ShipmentClientInterface;
use PigeonExpress\Shipping\Exception\NotEditableException;
use PigeonExpress\Shipping\Exception\ReplayedShipmentException;
use PigeonExpress\Shipping\Logger\Logger;
use PigeonExpress\Shipping\Model\Account\SavedAddresses;
use PigeonExpress\Shipping\Model\ResourceModel\Shipment as ShipmentResource;
use PigeonExpress\Shipping\Model\Shipment;
use PigeonExpress\Shipping\Model\Shipment\OrderLock;
use PigeonExpress\Shipping\Model\Shipment\ReturnDestination;
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

    public function testAChangeKeepsTheReturnDestinationTheShipmentWasCreatedWith(): void
    {
        // Created with the return office; the settings now say a saved address.
        $sent = [];
        $client = $this->createMock(ShipmentClientInterface::class);
        $client->method('update')->willReturnCallback(static function (string $ref, array $payload) use (&$sent) {
            $sent = $payload;

            return ['details' => ShipmentDetails::fromArray([]), 'replay' => ''];
        });
        $sender = $this->createMock(ShipmentSender::class);
        $sender->method('payloadFor')->willReturn([
            'payload' => [
                'receiver_phone'       => '+359888111222',
                'return_at_my_expense' => true,
                'return_address_id'    => 130,
            ],
            'fields'  => [],
        ]);
        $row = $this->row('shipment_registered', 'shipment_registered', [
            'pickup_type'      => 'office',
            'return_office_id' => 149,
        ]);

        $result = $this->editor($client, $sender, $row, null, null, null, null, [
            'returnDestination' => $this->returnDestination([130]),
        ])->update($this->order(), []);

        self::assertSame(149, $sent['return_office_id']);
        self::assertArrayNotHasKey('return_address_id', $sent, 'an office or an address, never both');
        self::assertTrue($sent['return_at_my_expense'], 'who pays for a return follows the setting');
        $stored = json_decode((string) $result->getPayload(), true);
        self::assertSame(149, $stored['return_office_id']);
    }

    public function testARecordedAddressThatLeftTheAccountIsDroppedAndTheCommentSaysSo(): void
    {
        $sent = null;
        $client = $this->createMock(ShipmentClientInterface::class);
        $client->method('update')->willReturnCallback(static function (string $ref, array $payload) use (&$sent) {
            $sent = $payload;

            return ['details' => ShipmentDetails::fromArray([]), 'replay' => ''];
        });
        $sender = $this->createMock(ShipmentSender::class);
        $sender->method('payloadFor')->willReturn([
            'payload' => ['receiver_phone' => '+359888111222', 'return_office_id' => 55],
            'fields'  => [],
        ]);
        $row = $this->row('shipment_registered', 'shipment_registered', ['return_address_id' => 131]);

        $comment = '';
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addCommentToStatusHistory'])
            ->getMock();
        $order->method('addCommentToStatusHistory')->willReturnCallback(
            static function ($text) use (&$comment, $order) {
                $comment = (string) $text;

                return $order;
            }
        );
        $orders = $this->createMock(OrderRepositoryInterface::class);
        $orders->method('get')->with(37)->willReturn($order);

        $this->editor($client, $sender, $row, null, null, null, null, [
            'returnDestination' => $this->returnDestination([130]),
            'orderRepository'   => $orders,
        ])->update($this->order(), []);

        self::assertIsArray($sent);
        self::assertSame([], array_intersect_key($sent, array_flip(ReturnDestination::KEYS)));
        self::assertStringContainsString('no longer in your Pigeon Express account', $comment);
    }

    public function testARecordedAddressIsKeptAndNotReportedWhenTheListCannotBeRead(): void
    {
        $sent = null;
        $client = $this->createMock(ShipmentClientInterface::class);
        $client->method('update')->willReturnCallback(static function (string $ref, array $payload) use (&$sent) {
            $sent = $payload;

            return ['details' => ShipmentDetails::fromArray([]), 'replay' => ''];
        });
        $sender = $this->createMock(ShipmentSender::class);
        $sender->method('payloadFor')->willReturn([
            'payload' => ['receiver_phone' => '+359888111222', 'return_office_id' => 55],
            'fields'  => [],
        ]);
        $row = $this->row('shipment_registered', 'shipment_registered', ['return_address_id' => 131]);

        $comment = '';
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['addCommentToStatusHistory'])
            ->getMock();
        $order->method('addCommentToStatusHistory')->willReturnCallback(
            static function ($text) use (&$comment, $order) {
                $comment .= (string) $text;

                return $order;
            }
        );
        $orders = $this->createMock(OrderRepositoryInterface::class);
        $orders->method('get')->willReturn($order);

        // The addresses call failed and nothing was cached.
        $result = $this->editor($client, $sender, $row, null, null, null, null, [
            'returnDestination' => $this->returnDestination([], false),
            'orderRepository'   => $orders,
        ])->update($this->order(), []);

        self::assertIsArray($sent);
        self::assertSame(131, $sent['return_address_id']);
        self::assertArrayNotHasKey('return_office_id', $sent);
        self::assertStringNotContainsString('no longer in your Pigeon Express account', $comment);
        $stored = json_decode((string) $result->getPayload(), true);
        self::assertSame(131, $stored['return_address_id'], 'the record keeps it too');
    }

    public function testAKeptAddressThatIsStillListedIsNotReported(): void
    {
        $payload = ['return_office_id' => 55];
        $dropped = $this->editor(null, null, null, null, null, null, null, [
            'returnDestination' => $this->returnDestination([131]),
        ])->keepRecordedReturn($payload, ['return_address_id' => 131], 1);

        self::assertFalse($dropped);
        self::assertSame(['return_address_id' => 131], $payload);
    }

    public function testTheDocumentsDestinationIsKeptOnlyWhileTheChangeCarriesADocumentsService(): void
    {
        $editor = $this->editor(null, null, null, null, null, null, null, [
            'returnDestination' => $this->returnDestination([]),
        ]);
        $recorded = ['return_office_id' => 149, 'return_documents_office_id' => 77];

        $with = ['service_codes' => ['service_return_documents' => true]];
        $editor->keepRecordedReturn($with, $recorded, 1);
        self::assertSame(77, $with['return_documents_office_id']);

        $without = ['service_codes' => ['cod_amount' => 10.0], 'return_documents_office_id' => 77];
        $editor->keepRecordedReturn($without, $recorded, 1);
        self::assertSame(149, $without['return_office_id']);
        self::assertArrayNotHasKey('return_documents_office_id', $without);
    }

    public function testAShipmentWithNoStoredBodyKeepsWhatTheSettingsGave(): void
    {
        $payload = ['return_office_id' => 55];
        $dropped = $this->editor(null, null, null, null, null, null, null, [
            'returnDestination' => $this->returnDestination([]),
        ])->keepRecordedReturn($payload, [], 1);

        self::assertFalse($dropped);
        self::assertSame(['return_office_id' => 55], $payload);
    }

    public function testTheChangeLinesSayNothingAboutAKeptDestination(): void
    {
        $before = ['receiver_name' => 'A', 'return_office_id' => 149];

        self::assertSame([], $this->editor()->describeChanges($before, $before));
    }

    /**
     * @param int[] $savedAddressIds The saved addresses the account lists
     * @param bool $readable False when the list could not be read at all
     */
    private function returnDestination(array $savedAddressIds, bool $readable = true): ReturnDestination
    {
        $addresses = $this->createMock(SavedAddresses::class);
        $addresses->method('find')->willReturnCallback(static function (int $id) use ($savedAddressIds): ?array {
            return in_array($id, $savedAddressIds, true) ? ['id' => $id, 'label' => 'Address ' . $id] : null;
        });
        $addresses->method('isKnown')->willReturnCallback(static function (int $id) use ($savedAddressIds, $readable): ?bool {
            return in_array($id, $savedAddressIds, true) ? true : ($readable ? false : null);
        });

        return new ReturnDestination(
            $this->createMock(ConfigInterface::class),
            $addresses,
            $this->createMock(LocationSearchInterface::class),
            $this->createMock(Logger::class)
        );
    }

    /**
     * @param array<string, mixed>|null $payload The stored body
     */
    private function row(string $code, string $status = 'shipment_registered', ?array $payload = null): Shipment
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
            'payload'          => json_encode($payload ?? ['pickup_type' => 'office', 'pickup_office_id' => 149]),
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
        ?StatusRefresher $refresher = null,
        array $extra = []
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

        return (new ObjectManager($this))->getObject(ShipmentEditor::class, $extra + [
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
