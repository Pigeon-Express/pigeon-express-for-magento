<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Courier;

use PHPUnit\Framework\TestCase;
use PigeonExpress\DTO\Response\CourierRequest;
use PigeonExpress\PigeonExpress;
use PigeonExpress\Resources\CourierRequests;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\Api\ClientFactory;
use PigeonExpress\Shipping\Model\Courier\CourierRequestSubmitter;
use Psr\Log\LoggerInterface;

/**
 * The one service that reaches Pigeon Express for courier requests, against
 * a mocked SDK: the body goes out as given, the answer comes back trimmed.
 */
class CourierRequestSubmitterTest extends TestCase
{
    public function testSubmitSendsTheBodyAndReadsTheAnswer(): void
    {
        $body = ['pickup_city_id' => 759, 'requested_pickup_date' => '2026-11-17'];
        $resource = $this->createMock(CourierRequests::class);
        $resource->expects(self::once())->method('create')->with($body)->willReturn(CourierRequest::fromArray([
            'request_number' => 'CR-458106744669',
            'request_type'   => 'single',
            'status'         => ['code' => 'courier_request_pending', 'name' => 'Нова <b>заявка</b>'],
        ]));
        $resource->method('getLastReplay')->willReturn('');

        $result = $this->submitter($resource)->submit($body);

        self::assertSame([
            'number'       => 'CR-458106744669',
            'status_code'  => 'courier_request_pending',
            'status_name'  => 'Нова заявка',
            'request_type' => 'single',
            'replay'       => '',
        ], $result);
    }

    public function testCancelAndGetReadTheRequestByNumber(): void
    {
        $resource = $this->createMock(CourierRequests::class);
        $resource->expects(self::once())->method('cancel')->with('CR-1')
            ->willReturn(CourierRequest::fromArray(['request_number' => 'CR-1', 'status' => ['code' => 'courier_request_cancelled', 'name' => '']]));
        $resource->expects(self::once())->method('get')->with('CR-1')
            ->willReturn(CourierRequest::fromArray(['request_number' => 'CR-1', 'status' => ['code' => 'courier_request_assigned', 'name' => '']]));

        $submitter = $this->submitter($resource);

        self::assertSame('courier_request_cancelled', $submitter->cancel('CR-1')['status_code']);
        self::assertSame('courier_request_assigned', $submitter->get('CR-1')['status_code']);
    }

    private function submitter(CourierRequests $resource): CourierRequestSubmitter
    {
        $client = $this->createMock(PigeonExpress::class);
        $client->method('courierRequests')->willReturn($resource);
        $factory = $this->createMock(ClientFactory::class);
        $factory->method('create')->willReturn($client);

        return new CourierRequestSubmitter($factory, $this->createMock(ConfigInterface::class), $this->createMock(LoggerInterface::class));
    }
}
