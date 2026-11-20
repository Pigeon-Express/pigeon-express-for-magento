<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Api;

use PHPUnit\Framework\TestCase;
use PigeonExpress\Exceptions\ConflictException;
use PigeonExpress\Exceptions\ConnectionException;
use PigeonExpress\Exceptions\NotFoundException;
use PigeonExpress\Exceptions\RateLimitException;
use PigeonExpress\Exceptions\ServerException;
use PigeonExpress\Exceptions\ValidationException;
use PigeonExpress\PigeonExpress;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Exception\ApiException;
use PigeonExpress\Shipping\Exception\OutcomeUnknownException;
use PigeonExpress\Shipping\Exception\RateLimitedException;
use PigeonExpress\Shipping\Model\Api\ClientFactory;
use PigeonExpress\Shipping\Model\Api\ShipmentClient;
use PigeonExpress\Shipping\Model\Service\CarrierLimits;
use Psr\Log\NullLogger;

/**
 * The quick actions, a locker's free boxes and the cancel refusal: what the
 * order panel builds on. Refusals keep Pigeon Express's status, code and
 * body, and a real change that may have been made is never reported as a
 * plain failure.
 */
class ShipmentClientActionsTest extends TestCase
{
    /** @var FakeHttpClient */
    private $http;

    /** @var array<int, int|null> Timeouts the client factory was asked for */
    private $timeouts = [];

    public function testActionsComeBackWithAllFourInOneShape(): void
    {
        $client = $this->client([FakeHttpClient::json(200, ['success' => true, 'data' => [
            'actions' => [
                'change_delivery' => ['allowed' => true],
                'declared_value' => ['allowed' => false, 'reason_code' => 'action_not_allowed', 'reason' => 'Не може.'],
            ],
            'current' => ['delivery_type' => 'office', 'delivery_office_id' => 12],
            'active_reference_number' => null,
        ]])]);

        $actions = $client->actions(' 459073686609 ', 1);

        $this->assertSame('GET', $this->http->requests[0]['method']);
        $this->assertSame('shipments/459073686609/actions', $this->http->requests[0]['url']);
        $this->assertSame([ShipmentClient::ACTIONS_TIMEOUT], $this->timeouts);
        $this->assertSame(['change_delivery', 'services', 'cod', 'declared_value'], array_keys($actions['actions']));
        $this->assertTrue($actions['actions']['change_delivery']['allowed']);
        $this->assertSame(['allowed' => false, 'reason_code' => '', 'reason' => ''], $actions['actions']['cod']);
        $this->assertSame('Не може.', $actions['actions']['declared_value']['reason']);
        $this->assertSame(12, $actions['current']['delivery_office_id']);
    }

    public function testActionsOfAShipmentNotOursSayNotFound(): void
    {
        $client = $this->client([$this->sdkError(
            new NotFoundException('Пратката не е намерена'),
            ['success' => false, 'message' => 'Пратката не е намерена', 'code' => 'not_found', 'status_code' => 404]
        )]);

        try {
            $client->actions('1', 1);
            $this->fail('expected an ApiException');
        } catch (ApiException $e) {
            $this->assertSame(404, $e->getHttpStatus());
            $this->assertSame('not_found', $e->getApiErrorCode());
            $this->assertSame('Пратката не е намерена', $e->getMessage(), 'Pigeon Express reason, as is');
        }
    }

    public function testABackendUnavailableIsRecognisable(): void
    {
        $client = $this->client([$this->sdkError(
            new ServerException('Промяната не може да бъде направена в момента.', 502),
            ['success' => false, 'code' => 'backend_unavailable', 'status_code' => 502]
        )]);

        try {
            $client->actions('1', 1);
            $this->fail('expected an ApiException');
        } catch (ApiException $e) {
            $this->assertNotInstanceOf(OutcomeUnknownException::class, $e);
            $this->assertSame(502, $e->getHttpStatus());
            $this->assertSame('backend_unavailable', $e->getApiErrorCode());
        }
    }

    public function testADryRunIsPricedWithAShortTimeoutAndNoReplay(): void
    {
        $client = $this->client([FakeHttpClient::json(200, [
            'success' => true,
            'message' => 'Цената на промяната е изчислена.',
            'data' => [
                'dry_run' => true,
                'price' => ['previous_total' => 5.4, 'new_total' => 7.35, 'amount_due' => 7.35, 'payment_mode' => 'deferred'],
                'result' => ['reference_number' => '459073686609', 'new_reference_number' => null, 'removed_services' => []],
            ],
        ], ['X-Duplicate-Request' => 'true'])]);

        $result = $client->runAction('459073686609', 'change-delivery', ['delivery_type' => 'office', 'delivery_office_id' => 12], true, 1);

        $this->assertSame('POST', $this->http->requests[0]['method']);
        $this->assertSame('shipments/459073686609/actions/change-delivery', $this->http->requests[0]['url']);
        $this->assertTrue($this->http->requests[0]['body']['dry_run']);
        $this->assertSame([ShipmentClient::ACTIONS_TIMEOUT], $this->timeouts);
        $this->assertSame(7.35, $result['data']['price']['amount_due']);
        $this->assertSame('Цената на промяната е изчислена.', $result['message']);
        $this->assertSame(ShipmentClient::REPLAY_NONE, $result['replay'], 'a price is never a replayed change');
        $this->assertSame('', $result['new_reference_number']);
    }

    public function testARealRedirectReportsTheNewNumberAndTheReplay(): void
    {
        $client = $this->client([FakeHttpClient::json(200, [
            'success' => true,
            'message' => 'Промяната е направена.',
            'data' => [
                'dry_run' => false,
                'price' => ['new_total' => 7.35],
                'result' => ['reference_number' => '459073686609', 'new_reference_number' => '459073712345'],
            ],
        ], ['X-Duplicate-Request' => 'true'])]);

        $result = $client->runAction('459073686609', ShipmentClient::ACTION_CHANGE_DELIVERY, ['delivery_type' => 'office', 'delivery_office_id' => 12], false, 1);

        $this->assertFalse($this->http->requests[0]['body']['dry_run']);
        $this->assertSame([null], $this->timeouts, 'a real change gets the full timeout');
        $this->assertSame('459073712345', $result['new_reference_number']);
        $this->assertSame(ShipmentClient::REPLAY_EARLIER, $result['replay']);
    }

    public function testARefusalKeepsItsReasonCodeAndFields(): void
    {
        $client = $this->client([$this->sdkError(
            new ValidationException('Промяната ... само в наш офис.', [], 422),
            [
                'success' => false,
                'message' => 'Промяната ... само в наш офис.',
                'code' => 'requires_office_payment',
                'errors' => [],
                'price' => ['amount_due' => 1.75],
            ]
        )]);

        try {
            $client->runAction('1', 'services', ['add' => ['shipment_review']], false, 1);
            $this->fail('expected an ApiException');
        } catch (ApiException $e) {
            $this->assertNotInstanceOf(OutcomeUnknownException::class, $e, 'a refusal changed nothing');
            $this->assertSame('Промяната ... само в наш офис.', $e->getMessage());
            $this->assertSame(422, $e->getHttpStatus());
            $this->assertSame('requires_office_payment', $e->getApiErrorCode());
            $this->assertSame(1.75, $e->getApiErrorData()['price']['amount_due']);
        }
        $this->assertCount(1, $this->http->requests, 'a refusal is not repeated');
    }

    public function testAFieldRefusalReadsAsItsSentences(): void
    {
        $client = $this->client([new ValidationException('Моля, коригирайте грешките във формуляра', [
            'delivery_type' => ['типът доставка е задължително поле.'],
            'add.0' => ['Услугата не е разрешена.', 'Опитайте друга.'],
        ], 422)]);

        try {
            $client->runAction('1', 'change_delivery', [], true, 1);
            $this->fail('expected an ApiException');
        } catch (ApiException $e) {
            $this->assertSame('типът доставка е задължително поле.; Услугата не е разрешена. Опитайте друга.', $e->getMessage());
            $this->assertSame('', $e->getApiErrorCode());
        }
    }

    public function testAConflictOnAPriceIsAPlainRetry(): void
    {
        $client = $this->client([new ConflictException('in progress', 3)]);

        try {
            $client->runAction('1', 'cod', ['cod_amount' => 10], true, 1);
            $this->fail('expected an ApiException');
        } catch (ApiException $e) {
            $this->assertNotInstanceOf(OutcomeUnknownException::class, $e);
            $this->assertSame(409, $e->getHttpStatus());
        }
        $this->assertCount(1, $this->http->requests, 'a price is never repeated');
    }

    public function testAConflictThatOutlastsTheRepeatsLeavesARealChangeUnknown(): void
    {
        $client = $this->client([new ConflictException('in progress', 0)], 0);

        $this->expectException(OutcomeUnknownException::class);
        $client->runAction('1', 'cod', ['cod_amount' => 10], false, 1);
    }

    public function testNoAnswerToARealChangeLeavesItUnknown(): void
    {
        $client = $this->client([new ConnectionException('cURL error 28: Operation timed out')]);

        $this->expectException(OutcomeUnknownException::class);
        $client->runAction('1', 'declared_value', ['declared_value' => null], false, 1);
    }

    public function testARealChangeThatNeverLeftChangedNothing(): void
    {
        $client = $this->client([new ConnectionException('cURL error 7: Failed to connect')]);

        try {
            $client->runAction('1', 'declared_value', ['declared_value' => 300], false, 1);
            $this->fail('expected an ApiException');
        } catch (ApiException $e) {
            $this->assertNotInstanceOf(OutcomeUnknownException::class, $e);
            $this->assertSame(0, $e->getHttpStatus());
        }
    }

    public function testARateLimitIsItsOwnException(): void
    {
        $client = $this->client([new RateLimitException('slow down', 30)]);

        try {
            $client->actions('1', 1);
            $this->fail('expected a RateLimitedException');
        } catch (RateLimitedException $e) {
            $this->assertSame(429, $e->getHttpStatus());
        }
    }

    public function testAnUnknownActionOrMissingReferenceIsNotSent(): void
    {
        $client = $this->client([FakeHttpClient::json(200, ['success' => true, 'data' => []])]);

        foreach ([['1', 'edit'], ['  ', 'cod']] as [$reference, $action]) {
            try {
                $client->runAction($reference, $action, [], true, 1);
                $this->fail('expected an ApiException');
            } catch (ApiException $e) {
                $this->assertSame(0, $e->getHttpStatus());
            }
        }
        $this->assertSame([], $this->http->requests);
    }

    public function testLockerAvailabilityComesBackInAFixedShape(): void
    {
        $client = $this->client([FakeHttpClient::json(200, ['success' => true, 'data' => [
            'id' => 156,
            'name' => 'Бушидо АПС',
            'code' => '1001',
            'is_blocked' => false,
            'blocked_until' => null,
            'box_sizes' => [
                ['size' => 'small', 'length' => 10, 'width' => 10, 'height' => 10, 'max_weight' => 2, 'total' => 16],
                ['size' => 'large', 'length' => 50, 'width' => 50, 'height' => 50, 'max_weight' => null, 'total' => 4],
            ],
            'availability' => [
                'today' => ['date' => '2026-09-21', 'small' => 10, 'large' => 3],
                'tomorrow' => ['date' => '2026-09-22', 'small' => 9],
            ],
        ]])]);

        $locker = $client->lockerAvailability(' 1001 ', 1);

        $this->assertSame('lockers/1001/availability', $this->http->requests[0]['url']);
        $this->assertSame([ShipmentClient::AVAILABILITY_TIMEOUT], $this->timeouts);
        $this->assertSame([
            'code' => '1001',
            'name' => 'Бушидо АПС',
            'is_blocked' => false,
            'blocked_until' => '',
            'box_sizes' => [
                ['size' => 'small', 'length' => 10, 'width' => 10, 'height' => 10, 'max_weight' => 2.0, 'total' => 16],
                ['size' => 'large', 'length' => 50, 'width' => 50, 'height' => 50, 'max_weight' => null, 'total' => 4],
            ],
            'today' => ['small' => 10, 'large' => 3],
            'tomorrow' => ['small' => 9, 'large' => 0],
            'today_date' => '2026-09-21',
            'tomorrow_date' => '2026-09-22',
        ], $locker);
    }

    public function testAnUnknownLockerSaysNotFoundAndNoCodeAsksNothing(): void
    {
        $client = $this->client([new NotFoundException('АПС локърът не е намерен')]);

        try {
            $client->lockerAvailability('', 1);
            $this->fail('expected an ApiException');
        } catch (ApiException $e) {
            $this->assertSame(0, $e->getHttpStatus());
            $this->assertSame([], $this->http->requests);
        }

        try {
            $client->lockerAvailability('052', 1);
            $this->fail('expected an ApiException');
        } catch (ApiException $e) {
            $this->assertSame(404, $e->getHttpStatus());
        }
    }

    public function testACancelRefusalKeepsItsStatus(): void
    {
        $client = $this->client([$this->sdkError(
            new ValidationException('Пратката не може да бъде отказана.', [], 422),
            ['success' => false, 'message' => 'Пратката не може да бъде отказана.', 'status_code' => 422]
        )]);

        try {
            $client->cancel('459073686609', 1);
            $this->fail('expected an ApiException');
        } catch (ApiException $e) {
            $this->assertSame(422, $e->getHttpStatus(), 'a derived shipment refusal can be recognised');
            $this->assertStringContainsString('Пратката не може да бъде отказана.', $e->getMessage());
        }
    }

    /**
     * @param array<int, \PigeonExpress\HttpClient\Response|\Throwable> $script
     */
    private function client(array $script, int $conflictRetries = ShipmentClient::CONFLICT_RETRIES): ShipmentClient
    {
        $this->http = new FakeHttpClient($script);
        $this->timeouts = [];

        $sdk = new PigeonExpress('k', 's');
        $sdk->setHttpClient($this->http);

        $factory = $this->createMock(ClientFactory::class);
        $factory->method('create')->willReturnCallback(function (?int $timeout = null) use ($sdk): PigeonExpress {
            $this->timeouts[] = $timeout;

            return $sdk;
        });

        return new ShipmentClient(
            $this->createMock(ConfigInterface::class),
            new NullLogger(),
            $this->createMock(CarrierLimits::class),
            $factory,
            $conflictRetries
        );
    }

    /**
     * An SDK exception as the HTTP client throws it: with the decoded body.
     *
     * @param array<string, mixed> $body
     */
    private function sdkError(\PigeonExpress\Exceptions\PigeonExpressException $e, array $body): \Throwable
    {
        return $e->withResponseData($body);
    }
}
