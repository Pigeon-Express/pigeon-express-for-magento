<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\QuickActions;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PigeonExpress\DTO\Response\ShipmentDetails;
use PigeonExpress\Exceptions\NotFoundException;
use PigeonExpress\Exceptions\ValidationException;
use PigeonExpress\Shipping\Api\LocationSearchInterface;
use PigeonExpress\Shipping\Api\ShipmentClientInterface;
use PigeonExpress\Shipping\Exception\ApiException;
use PigeonExpress\Shipping\Exception\OutcomeUnknownException;
use PigeonExpress\Shipping\Model\AdditionalServiceRepository;
use PigeonExpress\Shipping\Model\Api\AddressResolver;
use PigeonExpress\Shipping\Model\Api\ShipmentClient;
use PigeonExpress\Shipping\Model\Magento\OrderComment;
use PigeonExpress\Shipping\Model\QuickActions;
use PigeonExpress\Shipping\Model\QuickActions\QuickActionException;
use PigeonExpress\Shipping\Model\Rate\LocationToApiIdResolver;
use PigeonExpress\Shipping\Model\ResourceModel\Shipment as ShipmentResource;
use PigeonExpress\Shipping\Model\Shipment;
use PigeonExpress\Shipping\Model\Shipment\OrderLock;
use PigeonExpress\Shipping\Model\Shipment\RedirectFollower;
use PigeonExpress\Shipping\Model\ShipmentCanceller;
use PigeonExpress\Shipping\Model\Status\ShipmentDetailsStore;
use Psr\Log\LoggerInterface;

/**
 * Quick changes: when they are offered, the body each form sends, the
 * merchant's words for a refusal, and what a change does to the order.
 */
class QuickActionsTest extends TestCase
{
    /** @var ShipmentClientInterface&MockObject */
    private $client;

    /** @var ShipmentCanceller&MockObject */
    private $finder;

    /** @var OrderLock&MockObject */
    private $lock;

    /** @var RedirectFollower&MockObject */
    private $follower;

    /** @var LocationToApiIdResolver&MockObject */
    private $locations;

    /** @var OrderComment&MockObject */
    private $comments;

    /** @var ShipmentDetailsStore&MockObject */
    private $details;

    protected function setUp(): void
    {
        $this->client = $this->createMock(ShipmentClientInterface::class);
        $this->finder = $this->createMock(ShipmentCanceller::class);
        $this->lock = $this->createMock(OrderLock::class);
        $this->lock->method('acquire')->willReturn(true);
        $this->follower = $this->createMock(RedirectFollower::class);
        $this->locations = $this->createMock(LocationToApiIdResolver::class);
        $this->comments = $this->createMock(OrderComment::class);
        $this->details = $this->createMock(ShipmentDetailsStore::class);
    }

    private function quick(): QuickActions
    {
        $connection = $this->createMock(AdapterInterface::class);
        $resource = $this->createMock(ShipmentResource::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getMainTable')->willReturn('pigeonexpress_shipment');

        $price = $this->createMock(PriceCurrencyInterface::class);
        $price->method('format')->willReturnCallback(static function ($amount, $includeContainer, $precision, $scope, $currency): string {
            return number_format((float) $amount, 2) . ' ' . $currency;
        });

        $locationSearch = $this->createMock(LocationSearchInterface::class);
        $locationSearch->method('rowsByApiIds')->willReturnCallback(static function (string $type, array $ids): array {
            return (int) $ids[0] === 149
                ? [['id' => 12, 'api_id' => 149, 'name' => 'Sofia Center', 'address' => 'Vitosha 1', 'city' => 'Sofia']]
                : [];
        });

        return new QuickActions(
            $this->client,
            $this->finder,
            $this->details,
            $this->lock,
            $this->follower,
            $this->locations,
            $locationSearch,
            $this->createMock(AddressResolver::class),
            $this->createMock(AdditionalServiceRepository::class),
            $this->comments,
            $resource,
            $price,
            $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function row(array $data): Shipment
    {
        $row = $this->getMockBuilder(Shipment::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $row->setData($data + ['id' => 5, 'order_id' => 9, 'reference_number' => 'R1', 'status' => 'shipment_registered']);

        return $row;
    }

    private function order(): Order
    {
        $order = $this->getMockBuilder(Order::class)->disableOriginalConstructor()
            ->onlyMethods(['getEntityId', 'getStoreId', 'getOrderCurrencyCode'])->getMock();
        $order->method('getEntityId')->willReturn(9);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getOrderCurrencyCode')->willReturn('EUR');

        return $order;
    }

    public function testOfferedOnlyOnceEditIsOverAndBeforeTheEnd(): void
    {
        $picked = $this->row(['status_code' => 'shipment_picked_up']);

        self::assertTrue(QuickActions::isOffered($picked, 'It can be changed only until ...'));
        // Edit still works: no quick changes.
        self::assertFalse(QuickActions::isOffered($this->row(['status_code' => 'shipment_registered']), ''));
        self::assertFalse(QuickActions::isOffered($this->row(['status_code' => 'shipment_delivered_to_recipient']), 'x'));
        self::assertFalse(QuickActions::isOffered($this->row(['status_code' => 'shipment_redirected']), 'x'));
        self::assertFalse(QuickActions::isOffered($this->row(['status' => 'cancelled', 'status_code' => 'shipment_picked_up']), 'x'));
        self::assertFalse(QuickActions::isOffered($this->row(['status' => Shipment::STATUS_REDIRECTED, 'status_code' => 'shipment_picked_up']), 'x'));
        self::assertFalse(QuickActions::isOffered(null, 'x'));
    }

    public function testServicesNeedAtLeastOneTick(): void
    {
        self::assertSame(['add' => ['sms_notification']], $this->quick()->bodyFor('services', ['add' => ['sms_notification', 'sms_notification']]));
        self::assertSame(['remove' => ['fragile']], $this->quick()->bodyFor('services', ['remove' => ['fragile']]));

        $this->expectException(QuickActionException::class);
        $this->expectExceptionMessage('Tick at least one service to add or remove.');
        $this->quick()->bodyFor('services', ['add' => [], 'remove' => []]);
    }

    public function testCashOnDeliveryEmptyRemovesItAndAnAmountIsChecked(): void
    {
        self::assertSame(['cod_amount' => null], $this->quick()->bodyFor('cod', ['cod_amount' => '']));
        self::assertSame(
            ['cod_amount' => 12.35, 'allow_cod_card_payment' => true],
            $this->quick()->bodyFor('cod', ['cod_amount' => '12,345', 'allow_cod_card_payment' => true])
        );

        $this->expectException(QuickActionException::class);
        $this->quick()->bodyFor('cod', ['cod_amount' => '5001']);
    }

    public function testDeclaredValueIsCheckedAgainstTheCarriersLimits(): void
    {
        self::assertSame(['declared_value' => null], $this->quick()->bodyFor('declared_value', ['declared_value' => ' ']));
        self::assertSame(['declared_value' => 150.0], $this->quick()->bodyFor('declared_value', ['declared_value' => '150']));

        $this->expectException(QuickActionException::class);
        $this->quick()->bodyFor('declared_value', ['declared_value' => '0.5']);
    }

    public function testAnOfficeIsSentByTheCarriersId(): void
    {
        $this->locations->method('resolveLocation')->willReturnCallback(static function (string $type, string $id): ?array {
            return $type === 'office' && $id === '12' ? ['entity_id' => 12, 'api_id' => 149] : null;
        });

        self::assertSame(
            ['delivery_type' => 'office', 'delivery_office_id' => 149],
            $this->quick()->bodyFor('change_delivery', ['delivery_type' => 'office', 'location_id' => '12'])
        );

        $this->expectExceptionMessage('Pick the parcel locker to deliver to.');
        $this->quick()->bodyFor('change_delivery', ['delivery_type' => 'locker', 'location_id' => '12']);
    }

    public function testAnAddressNeedsACityAndAHouseNumber(): void
    {
        self::assertSame(
            ['delivery_type' => 'address', 'delivery_address' => ['city_id' => 68134, 'additional_info' => '12', 'street_id' => 7]],
            $this->quick()->bodyFor('change_delivery', ['delivery_type' => 'address', 'city_id' => 68134, 'street_id' => 7, 'additional_info' => '12'])
        );

        foreach ([
            [['delivery_type' => 'address', 'additional_info' => 'x 12'], 'Pick the city of the new address.'],
            [['delivery_type' => 'address', 'city_id' => 1], 'Enter the house number'],
            [['delivery_type' => 'address', 'city_id' => 1, 'additional_info' => '12'], 'With no street picked'],
            [['delivery_type' => 'moon'], 'Choose where the shipment should go.'],
        ] as [$fields, $message]) {
            try {
                $this->quick()->bodyFor('change_delivery', $fields);
                self::fail('Accepted: ' . json_encode($fields));
            } catch (QuickActionException $e) {
                self::assertStringStartsWith($message, $e->getMessage());
            }
        }
    }

    public function testRefusalsAreSaidInTheMerchantsWords(): void
    {
        $quick = $this->quick();
        $due = new ApiException(
            __('Трябва да се плати в офис.'),
            (new ValidationException('Трябва да се плати в офис.', [], 422))->withResponseData([
                'code' => 'requires_office_payment',
                'price' => ['amount_due' => 3.5],
            ])
        );
        self::assertSame('Трябва да се плати в офис. Amount due: 3.50 EUR.', $quick->errorMessage($due, true));

        $redirected = new ApiException(
            __('Пренасочена.'),
            (new ValidationException('Пренасочена.', [], 422))->withResponseData([
                'code' => 'already_redirected',
                'active_reference_number' => 'R2',
            ])
        );
        self::assertSame('Пренасочена. The active shipment is R2.', $quick->errorMessage($redirected, false));

        $missing = new ApiException(__('Not found'), (new NotFoundException('Not found'))->withResponseData(['code' => 'not_found']));
        self::assertStringStartsWith('Pigeon Express did not find this shipment.', $quick->errorMessage($missing, true));

        $unknown = new OutcomeUnknownException(__('Pigeon Express did not confirm the change.'));
        self::assertSame('Pigeon Express did not confirm the change.', $quick->errorMessage($unknown, false));

        $local = new ApiException(__('Could not reach Pigeon Express. Nothing was changed. Try again.'));
        self::assertSame('Could not reach Pigeon Express. Nothing was changed. Try again.', $quick->errorMessage($local, false));
    }

    public function testThePriceIsShownWithWhoPays(): void
    {
        $rows = $this->quick()->priceRows(
            ['previous_total' => 4, 'new_total' => 5.5, 'amount_due' => 1.5, 'payment_mode' => 'sender_unpaid'],
            'EUR',
            ['sms_notification']
        );

        self::assertSame(['Price now', 'New price', 'Amount due', 'Who pays the courier', 'Also removed'], array_column($rows, 'label'));
        self::assertSame('5.50 EUR', $rows[1]['value']);
        self::assertSame('Sender, not paid yet', $rows[3]['value']);
        self::assertSame('Sms notification', $rows[4]['value']);
    }

    public function testTheSamePlaceIsRecognised(): void
    {
        self::assertTrue(QuickActions::sameDestination(
            ['delivery_type' => 'office', 'delivery_office_id' => '149'],
            ['delivery_type' => 'office', 'delivery_office_id' => 149]
        ));
        self::assertFalse(QuickActions::sameDestination(
            ['delivery_type' => 'office', 'delivery_office_id' => 149],
            ['delivery_type' => 'locker', 'delivery_office_id' => 149]
        ));
        self::assertTrue(QuickActions::sameDestination(
            ['delivery_type' => 'address', 'delivery_address' => ['city_id' => 1, 'street_id' => 2, 'additional_info' => 'a']],
            ['delivery_type' => 'address', 'delivery_address' => ['city_id' => 1, 'street_id' => 2, 'additional_info' => 'b']]
        ));
    }

    public function testAChangeIsKeptAsWhatTheShipmentWasSentWith(): void
    {
        $quick = $this->quick();
        $payload = ['service_codes' => ['cod_amount' => 20, 'fragile' => true], 'delivery_type' => 'address',
            'delivery_address' => ['city_id' => 1, 'contact_name' => 'Ivan'], 'return_office_id' => 55];

        [$p, $f] = $quick->applySent($payload, [], 'cod', ['cod_amount' => null], [], 0);
        self::assertArrayNotHasKey('cod_amount', $p['service_codes']);
        self::assertSame(55, $p['return_office_id']);

        [$p] = $quick->applySent($payload, [], 'services', ['add' => ['sms_notification'], 'remove' => ['fragile']], [], 0);
        self::assertSame(['cod_amount' => 20, 'sms_notification' => true], $p['service_codes']);

        [$p, $f] = $quick->applySent($payload, [], 'change_delivery', ['delivery_type' => 'office', 'delivery_office_id' => 149],
            ['location_id' => '12', 'location_label' => 'Sofia Center'], 0);
        self::assertSame(149, $p['delivery_office_id']);
        self::assertArrayNotHasKey('delivery_address', $p);
        self::assertSame(55, $p['return_office_id']);
        self::assertSame(['delivery_type' => 'office', 'location_id' => '12', 'location' => 'Sofia Center'], $f);
    }

    public function testThePriceIsAskedOnlyForTheShipmentTheFormWasOpenedFor(): void
    {
        $this->finder->method('findShipment')->willReturn($this->row(['reference_number' => 'R2']));
        $this->client->expects(self::never())->method('runAction');

        $this->expectExceptionMessage('The shipment on this order changed in the meantime. Reload the order.');
        $this->quick()->run($this->order(), 'cod', ['cod_amount' => '10'], true, 'R1');
    }

    public function testADryRunReturnsThePrice(): void
    {
        $this->finder->method('findShipment')->willReturn($this->row([]));
        $this->client->expects(self::once())->method('runAction')
            ->with('R1', 'cod', ['cod_amount' => 10.0, 'allow_cod_card_payment' => false], true, 1)
            ->willReturn(['data' => ['price' => ['new_total' => 5], 'result' => ['removed_services' => ['pay_with_pos']]], 'replay' => '', 'new_reference_number' => '', 'message' => '']);
        $this->lock->expects(self::never())->method('acquire');

        $result = $this->quick()->run($this->order(), 'cod', ['cod_amount' => '10'], true, 'R1');

        self::assertSame(['new_total' => 5], $result['price']);
        self::assertSame(['pay_with_pos'], $result['removed']);
    }

    public function testAChangeHoldsTheLockAndLeavesAComment(): void
    {
        $this->finder->method('findShipment')->willReturn($this->row([]));
        $this->client->method('actions')->willReturn(['current' => ['cod_amount' => 20]]);
        $this->client->method('runAction')->willReturn(['data' => ['price' => []], 'replay' => ShipmentClient::REPLAY_NONE, 'new_reference_number' => '', 'message' => '']);
        $this->client->method('get')->willReturn(ShipmentDetails::fromArray(['reference_number' => 'R1']));
        $this->lock->expects(self::once())->method('release')->with(9, 'send');
        $this->details->expects(self::once())->method('remember');
        $this->comments->expects(self::once())->method('add')->with(
            self::anything(),
            self::callback(static function (string $text): bool {
                return strpos($text, 'Pigeon Express shipment R1 was changed.') === 0
                    && strpos($text, 'COD amount: 20.00 to 10.00') !== false;
            })
        );

        $result = $this->quick()->run($this->order(), 'cod', ['cod_amount' => '10'], false, 'R1');

        self::assertSame('R1', $result['reference']);
        self::assertStringStartsWith('The shipment was changed.', $result['message']);
    }

    public function testABusyOrderIsNotChanged(): void
    {
        $lock = $this->createMock(OrderLock::class);
        $lock->method('acquire')->willReturn(false);
        $this->lock = $lock;
        $this->client->expects(self::never())->method('runAction');

        $this->expectExceptionMessage('is being created or changed right now');
        $this->quick()->run($this->order(), 'cod', ['cod_amount' => '10'], false, 'R1');
    }

    public function testANewNumberMovesTheOrderToTheNewShipment(): void
    {
        $row = $this->row([]);
        $this->finder->method('findShipment')->willReturn($row);
        $this->locations->method('resolveLocation')->willReturn(['entity_id' => 12, 'api_id' => 149]);
        $this->client->method('actions')->willReturn([]);
        $this->client->method('runAction')->willReturn([
            'data' => ['price' => ['new_total' => 6]],
            'replay' => ShipmentClient::REPLAY_NONE,
            'new_reference_number' => 'R2',
            'message' => '',
        ]);
        $this->follower->method('readableTarget')->with('R2')->willReturn(['reference_number' => 'R2']);
        $this->follower->expects(self::once())->method('move')
            ->with($row, 'R2', self::isType('array'), 1, ['reference_number' => 'R2'], self::isType('callable'))
            ->willReturn(true);

        $result = $this->quick()->run($this->order(), 'change_delivery', ['delivery_type' => 'office', 'location_id' => '12'], false, 'R1');

        self::assertSame('R2', $result['reference']);
        self::assertStringContainsString('The customer was emailed the new tracking number.', $result['message']);
    }

    public function testAnUnreadableNewShipmentLeavesTheOrderAsItIs(): void
    {
        $this->finder->method('findShipment')->willReturn($this->row([]));
        $this->locations->method('resolveLocation')->willReturn(['entity_id' => 12, 'api_id' => 149]);
        $this->client->method('runAction')->willReturn(['data' => [], 'replay' => '', 'new_reference_number' => 'R2', 'message' => '']);
        $this->follower->method('readableTarget')->willReturn([]);
        $this->follower->expects(self::never())->method('move');

        try {
            $this->quick()->run($this->order(), 'change_delivery', ['delivery_type' => 'office', 'location_id' => '12'], false, 'R1');
            self::fail('Moved without reading the new shipment');
        } catch (QuickActionException $e) {
            self::assertTrue($e->redraw());
            self::assertStringContainsString('the customer was not emailed', $e->getMessage());
        }
    }

    public function testAnEarlierRedirectWhoseAnswerWasLostIsAdopted(): void
    {
        $row = $this->row([]);
        $this->finder->method('findShipment')->willReturn($row);
        $this->locations->method('resolveLocation')->willReturn(['entity_id' => 12, 'api_id' => 149]);
        $this->client->method('actions')->willReturnCallback(static function (string $reference): array {
            // The active shipment goes where the merchant asked.
            return $reference === 'R2' ? ['current' => ['delivery_type' => 'office', 'delivery_office_id' => 149]] : [];
        });
        $this->client->method('runAction')->willThrowException(new ApiException(
            __('Пренасочена.'),
            (new ValidationException('Пренасочена.', [], 422))->withResponseData([
                'code' => 'already_redirected',
                'active_reference_number' => 'R2',
            ])
        ));
        $this->follower->method('readableTarget')->willReturn(['reference_number' => 'R2']);
        $this->follower->expects(self::once())->method('move')
            ->with($row, 'R2', self::callback(static function (array $lines): bool {
                return in_array('Pigeon Express had made this change on an earlier attempt whose answer did not arrive.', $lines, true);
            }))
            ->willReturn(false);

        $result = $this->quick()->run($this->order(), 'change_delivery', ['delivery_type' => 'office', 'location_id' => '12'], false, 'R1');

        self::assertSame('R2', $result['reference']);
        self::assertSame('The shipment was redirected. Its new number is R2; print the new label.', $result['message']);
    }

    public function testTheFormStartsFromTheCarriersCurrentValues(): void
    {
        $view = $this->quick()->view($this->order(), $this->row([]), [
            'actions' => [
                'change_delivery' => ['allowed' => true, 'reason' => ''],
                'services'        => ['allowed' => 'false', 'reason' => '<b>Не</b> може'],
            ],
            'current' => ['delivery_type' => 'office', 'delivery_office_id' => 149, 'cod_amount' => '19.80', 'allow_cod_card_payment' => 1],
            'addable_services' => [['code' => 'sms_notification', 'name' => 'SMS'], ['code' => '']],
            'pricing' => ['currency' => 'eur'],
            'active_reference_number' => null,
        ]);

        self::assertTrue($view['actions']['change_delivery']['allowed']);
        self::assertFalse($view['actions']['services']['allowed']);
        self::assertSame('Не може', $view['actions']['services']['reason']);
        self::assertFalse($view['actions']['cod']['allowed']);
        self::assertSame('12', $view['prefill']['location_id']);
        self::assertSame('Sofia Center - Vitosha 1 - Sofia', $view['prefill']['location_label']);
        self::assertSame(19.8, $view['prefill']['cod_amount']);
        self::assertTrue($view['prefill']['cod_card']);
        self::assertSame([['code' => 'sms_notification', 'name' => 'SMS']], $view['add']);
        self::assertSame('EUR', $view['currency']);
        self::assertSame('', $view['active_reference']);
    }
}
