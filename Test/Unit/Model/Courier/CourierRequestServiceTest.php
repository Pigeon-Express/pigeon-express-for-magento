<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Courier;

use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Exceptions\ValidationException;
use PigeonExpress\Shipping\Model\Account\SavedAddresses;
use PigeonExpress\Shipping\Model\Courier\CourierRequestRepository;
use PigeonExpress\Shipping\Model\Courier\CourierRequestService;
use PigeonExpress\Shipping\Model\Courier\CourierRequestSubmitter;
use PigeonExpress\Shipping\Model\Courier\CourierSettings;
use PigeonExpress\Shipping\Model\Magento\OrderComment;
use PigeonExpress\Shipping\Model\Shipment;
use PigeonExpress\Shipping\Model\ShipmentCanceller;
use Psr\Log\LoggerInterface;

/**
 * A courier request is stored only once Pigeon Express has taken it; a
 * refusal, the dev site's block or a replay of a cancelled request stores
 * nothing.
 */
class CourierRequestServiceTest extends TestCase
{
    /** @var CourierRequestSubmitter&MockObject */
    private $submitter;

    /** @var CourierRequestRepository&MockObject */
    private $repository;

    /** @var OrderComment&MockObject */
    private $comments;

    /** @var string */
    private $statusCode = '';

    protected function setUp(): void
    {
        $this->submitter = $this->createMock(CourierRequestSubmitter::class);
        $this->repository = $this->createMock(CourierRequestRepository::class);
        $this->comments = $this->createMock(OrderComment::class);
    }

    public function testASuccessfulRequestIsStoredWithItsShipmentsAndCommented(): void
    {
        $this->submitter->expects(self::once())->method('submit')
            ->with(self::callback(function (array $body): bool {
                return $body['pickup_city_id'] === 759
                    && $body['pickup_street_id'] === 434699
                    && $body['pickup_street_number'] === '10'
                    && $body['requested_pickup_date'] === '2026-11-17'
                    && $body['requested_pickup_time_type'] === 'interval'
                    && $body['requested_pickup_time_from'] === '10:00'
                    && $body['requested_pickup_time_to'] === '17:00'
                    && $body['shipment_references'] === ['R1']
                    && $body['package_type'] === 'standard'
                    && $body['estimated_weight'] === 0.5
                    && !isset($body['contact_name']);
            }))
            ->willReturn(['number' => 'CR-1', 'status_code' => 'courier_request_pending', 'status_name' => '', 'request_type' => 'single', 'replay' => '']);
        $this->repository->expects(self::once())->method('save')
            ->with(self::callback(function (array $request): bool {
                return $request['number'] === 'CR-1'
                    && $request['shipments'] === ['R1' => 41]
                    && $request['request_type'] === 'single'
                    && $request['address'] === 'София, ВИТОША 10';
            }))
            ->willReturn(true);
        $this->comments->expects(self::once())->method('add')
            ->with(self::anything(), 'Pigeon Express courier requested: CR-1, pickup 17.11.2026, 10:00 - 17:00.');

        $result = $this->service()->create($this->input());

        self::assertSame('Courier requested: CR-1, pickup 17.11.2026, 10:00 - 17:00.', $result['message']);
    }

    public function testTheDevBlockStoresNothing(): void
    {
        $this->submitter->method('submit')->willThrowException(
            new LocalizedException(__('Courier requests are blocked on this dev site. Nothing was sent to Pigeon Express.'))
        );
        $this->repository->expects(self::never())->method('save');
        $this->comments->expects(self::never())->method('add');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Courier requests are blocked on this dev site.');
        $this->service()->create($this->input());
    }

    public function testARefusalIsExplainedPerShipmentAndStoresNothing(): void
    {
        $this->submitter->method('submit')->willThrowException(
            new ValidationException('Validation failed', ['shipment_references.0' => ['Not registered']], 422)
        );
        $this->repository->expects(self::never())->method('save');

        $this->expectExceptionMessage('Shipment R1: Not registered');
        $this->service()->create($this->input());
    }

    public function testAReplayOfACancelledRequestIsRefused(): void
    {
        $this->submitter->method('submit')
            ->willReturn(['number' => 'CR-1', 'status_code' => 'courier_request_pending', 'status_name' => '', 'request_type' => 'single', 'replay' => 'earlier']);
        $this->submitter->method('get')
            ->willReturn(['number' => 'CR-1', 'status_code' => 'courier_request_cancelled', 'status_name' => '', 'request_type' => 'single']);
        $this->repository->expects(self::never())->method('save');

        $this->expectExceptionMessage('The same request was cancelled less than half a minute ago.');
        $this->service()->create($this->input());
    }

    public function testAShipmentPastRegisteredIsLeftOut(): void
    {
        $this->statusCode = 'shipment_in_delivery';
        $collected = $this->service()->collect([41]);

        self::assertSame([], $collected['shipments']);
        self::assertStringContainsString('no longer waiting', $collected['skipped'][0]['reason']);
    }

    public function testAnIntervalEndingBeforeItStartsIsRefused(): void
    {
        $input = $this->input();
        $input['time_to'] = '09:00';
        $this->submitter->expects(self::never())->method('submit');

        $this->expectExceptionMessage('The end of the pickup time must be later than its start.');
        $this->service()->create($input);
    }

    public function testCancelStoresTheNewStatusAndComments(): void
    {
        $this->repository->method('find')->willReturn(['request_number' => 'CR-1', 'status_code' => 'courier_request_pending', 'shipments' => ['R1' => 41]]);
        $this->submitter->method('cancel')->willReturn(['number' => 'CR-1', 'status_code' => '', 'status_name' => '', 'request_type' => '']);
        $this->repository->expects(self::once())->method('updateStatus')
            ->with('CR-1', self::callback(function (array $data): bool {
                return $data['status_code'] === 'courier_request_cancelled';
            }));
        $this->comments->expects(self::once())->method('add');

        self::assertSame('Courier request CR-1 cancelled.', $this->service()->cancel('CR-1')['message']);
    }

    /**
     * @return array<string, mixed>
     */
    private function input(): array
    {
        return [
            'pickup_date' => '2026-11-17', 'time_type' => 'interval', 'time_from' => '10:00', 'time_to' => '17:00',
            'address_mode' => 'other', 'city_id' => 759, 'city_label' => 'София', 'street_id' => 434699,
            'street_label' => 'ВИТОША', 'street_number' => '10', 'address_info' => '', 'contact_name' => '',
            'contact_phone' => '', 'contact_email' => '', 'company_name' => '', 'orders' => [41],
            'package_type' => 'standard', 'weight' => 0.5, 'length' => 0.0, 'width' => 0.0, 'height' => 0.0, 'instructions' => '',
        ];
    }

    private function service(): CourierRequestService
    {
        $settings = $this->createMock(CourierSettings::class);
        $settings->method('isAvailable')->willReturn(true);
        $settings->method('packageType')->willReturn('standard');

        $order = $this->createMock(OrderInterface::class);
        $order->method('getIncrementId')->willReturn('000000043');
        $orders = $this->createMock(OrderRepositoryInterface::class);
        $orders->method('get')->willReturn($order);

        $shipment = $this->createMock(Shipment::class);
        $shipment->method('getStatus')->willReturn('sent');
        $shipment->method('getReferenceNumber')->willReturn('R1');
        $shipment->method('getStatusCode')->willReturnCallback(function () {
            return $this->statusCode;
        });
        $shipment->method('getFormFields')->willReturn('{"packages":[{"weight":0.5}]}');
        $shipments = $this->createMock(ShipmentCanceller::class);
        $shipments->method('findShipment')->willReturn($shipment);

        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->method('date')->willReturn(new \DateTime('2026-11-16 16:00'));

        return new CourierRequestService(
            $this->submitter,
            $this->repository,
            $settings,
            $orders,
            $shipments,
            $this->createMock(SavedAddresses::class),
            $this->comments,
            $timezone,
            $this->createMock(AuthSession::class),
            $this->createMock(LoggerInterface::class)
        );
    }
}
