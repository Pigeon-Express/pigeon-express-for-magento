<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\ReturnShipment;

use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderCommentSender;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PigeonExpress\DTO\Response\ShipmentDetails;
use PigeonExpress\Exceptions\ValidationException;
use PigeonExpress\Shipping\Api\ShipmentClientInterface;
use PigeonExpress\Shipping\Exception\ApiException;
use PigeonExpress\Shipping\Model\Environment;
use PigeonExpress\Shipping\Model\Magento\OrderComment;
use PigeonExpress\Shipping\Model\Magento\TrackingUrl;
use PigeonExpress\Shipping\Model\ReturnShipment\Availability;
use PigeonExpress\Shipping\Model\ReturnShipment\NotEnabledException;
use PigeonExpress\Shipping\Model\ReturnShipment\ReturnBodyBuilder;
use PigeonExpress\Shipping\Model\ReturnShipment\ReturnShipmentRepository;
use PigeonExpress\Shipping\Model\ReturnShipment\ReturnShipmentService;
use PigeonExpress\Shipping\Model\Shipment\OrderLock;
use PigeonExpress\Shipping\Model\Status\ShipmentDetailsStore;
use Psr\Log\LoggerInterface;

/**
 * One return per order, stored with what was sent; the customer is told by
 * an order comment and its email; a refusal for the account hides the form.
 */
class ReturnShipmentServiceTest extends TestCase
{
    /** @var ShipmentClientInterface&MockObject */
    private $client;

    /** @var ReturnShipmentRepository&MockObject */
    private $repository;

    /** @var Availability&MockObject */
    private $availability;

    /** @var OrderComment&MockObject */
    private $comments;

    /** @var OrderCommentSender&MockObject */
    private $sender;

    /** @var array<string, mixed> */
    private $body = [
        'is_reverse' => true, 'sender_name' => 'A', 'sender_phone' => '+359888123456',
        'pickup_type' => 'office', 'pickup_office_id' => 5, 'delivery_type' => 'office', 'delivery_office_id' => 149,
        'packages' => [], 'who_pays' => 'receiver',
    ];

    protected function setUp(): void
    {
        $this->client = $this->createMock(ShipmentClientInterface::class);
        $this->repository = $this->createMock(ReturnShipmentRepository::class);
        $this->availability = $this->createMock(Availability::class);
        $this->availability->method('isEnabled')->willReturn(true);
        $this->comments = $this->createMock(OrderComment::class);
        $this->sender = $this->createMock(OrderCommentSender::class);
    }

    public function testACreatedReturnIsStoredAndTheCustomerIsNotified(): void
    {
        $this->client->expects(self::once())->method('create')->with($this->body, 1)
            ->willReturn(['reference_number' => 'RET1', 'tracking_number' => 'RET1-1', 'status' => 'shipment_registered', 'replay' => '']);
        $this->repository->method('findActive')->willReturnOnConsecutiveCalls(null, ['reference_number' => 'RET1']);
        $this->repository->expects(self::once())->method('insert')
            ->with(self::callback(function (array $row): bool {
                return $row['order_id'] === 41 && $row['reference_number'] === 'RET1'
                    && $row['environment'] === 'test' && $row['sent_json']['pickup_label'] === 'Office Sofia';
            }));
        $this->comments->expects(self::once())->method('add')
            ->with(self::anything(), self::stringContains('Pigeon Express return shipment RET1 was created.'));
        $this->comments->expects(self::once())->method('addForCustomer')
            ->with(self::anything(), self::stringContains("Waybill number: RET1. Track it here: https://track.pigeonexpress.com/?tracking_number=RET1\nHand the parcel in at: Office Sofia."), true)
            ->willReturn(true);
        $this->sender->expects(self::once())->method('send')->with(self::anything(), true, self::stringContains('RET1'));

        $row = $this->service()->create($this->order(), ['notify_customer' => true, 'pickup_office_label' => 'Office Sofia']);

        self::assertSame('RET1', $row['reference_number']);
    }

    public function testNoCustomerCommentWithoutNotify(): void
    {
        $this->client->method('create')->willReturn(['reference_number' => 'RET1', 'replay' => '']);
        $this->comments->expects(self::never())->method('addForCustomer');
        $this->sender->expects(self::never())->method('send');

        $this->service()->create($this->order(), ['notify_customer' => false]);
    }

    public function testOnlyOneReturnPerOrder(): void
    {
        $this->repository->method('findActive')->willReturn(['reference_number' => 'OLD']);
        $this->client->expects(self::never())->method('create');

        $this->expectExceptionMessage('This order already has a return shipment.');
        $this->service()->create($this->order(), []);
    }

    public function testARefusalForTheAccountIsRemembered(): void
    {
        $sdk = new ValidationException('Нямате разрешение за създаване на обратни пратки. Обърнете се към администратор.', [], 422);
        $this->client->method('create')->willThrowException(new ApiException(__('Failed'), $sdk));
        $this->availability->expects(self::once())->method('markUnavailable');
        $this->repository->expects(self::never())->method('insert');

        $this->expectException(NotEnabledException::class);
        $this->service()->create($this->order(), []);
    }

    public function testCancelAcceptsAReturnAlreadyCancelledAtTheCarrier(): void
    {
        $this->repository->method('findActive')->willReturn(['entity_id' => 3, 'reference_number' => 'RET1']);
        $this->client->method('cancel')->willThrowException(new ApiException(__('Already cancelled')));
        $this->client->method('get')->willReturn(ShipmentDetails::fromArray(['status' => ['code' => 'shipment_cancelled', 'name' => '']]));
        $this->repository->expects(self::once())->method('update')
            ->with(3, ['status' => 'cancelled', 'status_code' => 'shipment_cancelled']);
        $this->comments->expects(self::once())->method('add')
            ->with(self::anything(), self::stringContains('had already been cancelled at the carrier'));

        self::assertSame('RET1', $this->service()->cancel($this->order()));
    }

    public function testACancelRefusedForALiveReturnKeepsIt(): void
    {
        $this->repository->method('findActive')->willReturn(['entity_id' => 3, 'reference_number' => 'RET1']);
        $this->client->method('cancel')->willThrowException(new ApiException(__('Too late')));
        $this->client->method('get')->willReturn(ShipmentDetails::fromArray(['status' => ['code' => 'shipment_in_delivery', 'name' => '']]));
        $this->repository->expects(self::never())->method('update');

        $this->expectExceptionMessage('Too late');
        $this->service()->cancel($this->order());
    }

    private function order(): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getEntityId')->willReturn(41);
        $order->method('getStoreId')->willReturn(1);

        return $order;
    }

    private function service(): ReturnShipmentService
    {
        $builder = $this->createMock(ReturnBodyBuilder::class);
        $builder->method('build')->willReturn($this->body);
        $lock = $this->createMock(OrderLock::class);
        $lock->method('acquire')->willReturn(true);
        $env = $this->createMock(Environment::class);
        $env->method('current')->willReturn('test');

        return new ReturnShipmentService(
            $this->client,
            $this->repository,
            $builder,
            $this->availability,
            $lock,
            $this->comments,
            $this->sender,
            new TrackingUrl(),
            $env,
            $this->createMock(ShipmentDetailsStore::class),
            $this->createMock(AuthSession::class),
            $this->createMock(LoggerInterface::class)
        );
    }
}
