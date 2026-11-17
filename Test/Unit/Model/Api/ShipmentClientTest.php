<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Api;

use PHPUnit\Framework\TestCase;
use PigeonExpress\Exceptions\ConflictException;
use PigeonExpress\Exceptions\ConnectionException;
use PigeonExpress\Exceptions\ServerException;
use PigeonExpress\Exceptions\ValidationException;
use PigeonExpress\PigeonExpress;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Exception\ApiException;
use PigeonExpress\Shipping\Exception\OutcomeUnknownException;
use PigeonExpress\Shipping\Model\Api\ClientFactory;
use PigeonExpress\Shipping\Model\Api\ShipmentClient;
use PigeonExpress\Shipping\Model\Service\CarrierLimits;
use Psr\Log\NullLogger;

/**
 * A create is sent once and every field of the payload reaches Pigeon
 * Express. A create that may have been carried out is never repeated, so an
 * order cannot end up with two waybills.
 */
class ShipmentClientTest extends TestCase
{
    /** @var FakeHttpClient */
    private $http;

    /** @var CarrierLimits|\PHPUnit\Framework\MockObject\MockObject */
    private $limits;

    public function testEveryPayloadFieldReachesTheRequestBody(): void
    {
        $client = $this->client([$this->created('R1')]);

        $client->create($this->payload() + [
            'allow_partial_acceptance' => true,
            'receiver_company' => ['name' => 'ACME'],
            'external_reference' => '000000039',
            'hide_content_on_label' => true,
            'service_codes' => ['sms_notification_receiver' => true, 'cod_amount' => 12.5],
            'inventory_items' => [['description' => 'Bag', 'quantity' => 1]],
        ], 1);

        $body = $this->http->requests[0]['body'];
        self::assertSame('POST', $this->http->requests[0]['method']);
        self::assertTrue($body['allow_partial_acceptance'], 'a field without a setter must not be dropped');
        self::assertSame(['name' => 'ACME'], $body['receiver_company']);
        self::assertSame('000000039', $body['external_reference']);
        self::assertTrue($body['hide_content_on_label']);
        self::assertSame(['sms_notification_receiver' => true, 'cod_amount' => 12.5], $body['service_codes']);
        self::assertSame([['description' => 'Bag', 'quantity' => 1]], $body['inventory_items']);
        self::assertSame(149, $body['pickup_office_id']);
        self::assertSame('Оставете на портиера', $body['note']);
        self::assertSame('receiver', $body['who_pays']);
    }

    public function testThePickupAddressIsSentAsTheSenderAddress(): void
    {
        $client = $this->client([$this->created('R1')]);
        $payload = $this->payload();
        unset($payload['pickup_office_id']);
        $payload['pickup_type'] = 'address';
        $payload['pickup_address'] = ['city_id' => 759, 'street_number' => '1'];

        $client->create($payload, 1);

        $body = $this->http->requests[0]['body'];
        self::assertArrayNotHasKey('pickup_address', $body);
        self::assertSame(759, (int) $body['sender_address']['city_id']);
    }

    public function testALongExternalReferenceIsCutToTheApiLimit(): void
    {
        $client = $this->client([$this->created('R1')]);

        $client->create($this->payload() + ['external_reference' => str_repeat('9', 150)], 1);

        self::assertSame(100, strlen($this->http->requests[0]['body']['external_reference']));
    }

    public function testNoCashToCollectSendsNoCodAmount(): void
    {
        $client = $this->client([$this->created('R1')]);

        $client->create($this->payload() + ['cod_amount' => 0], 1);

        self::assertArrayNotHasKey('cod_amount', $this->http->requests[0]['body']);
    }

    public function testATimeoutIsNotRepeatedAndTheOutcomeIsUnknown(): void
    {
        $client = $this->client([
            new ConnectionException('Failed to connect: cURL error 28: Operation timed out'),
            $this->created('R2'),
        ]);

        try {
            $client->create($this->payload(), 1);
            self::fail('expected the outcome to be unknown');
        } catch (OutcomeUnknownException $e) {
            self::assertCount(1, $this->http->requests, 'a create that may have worked must not be sent again');
        }
    }

    public function testARefusedConnectionSaysNothingWasCreated(): void
    {
        $client = $this->client([new ConnectionException('cURL error 7: Failed to connect')]);

        try {
            $client->create($this->payload(), 1);
            self::fail('expected a failure');
        } catch (OutcomeUnknownException $e) {
            self::fail('a request that never left is not of unknown outcome');
        } catch (ApiException $e) {
            self::assertCount(1, $this->http->requests);
        }
    }

    public function testAServerErrorIsNotRepeated(): void
    {
        $client = $this->client([new ServerException('Bad gateway', 502), $this->created('R2')]);

        $this->expectException(OutcomeUnknownException::class);
        try {
            $client->create($this->payload(), 1);
        } finally {
            self::assertCount(1, $this->http->requests);
        }
    }

    public function testAPersisting409IsReportedAsUnknownOutcome(): void
    {
        $client = $this->client([new ConflictException('Identical request in progress', 1)], 0);

        $this->expectException(OutcomeUnknownException::class);
        try {
            $client->create($this->payload(), 1);
        } finally {
            self::assertCount(1, $this->http->requests);
        }
    }

    public function testARefusalIsReportedWithItsFieldsAndTaught(): void
    {
        $client = $this->client([
            new ValidationException('The given data was invalid.', ['packages.0.weight' => ['Too heavy']], 422),
        ]);
        $this->limits->expects(self::once())->method('learnFromRefusal');

        try {
            $client->create($this->payload(), 1);
            self::fail('expected a refusal');
        } catch (OutcomeUnknownException $e) {
            self::fail('a refusal is a known outcome');
        } catch (ApiException $e) {
            self::assertStringContainsString('Too heavy', $e->getMessage());
            self::assertCount(1, $this->http->requests);
        }
    }

    public function testAReplayedAnswerIsReported(): void
    {
        $client = $this->client([$this->created('R1', ['X-Duplicate-Request' => 'true'])]);

        $result = $client->create($this->payload(), 1);

        self::assertSame('R1', $result['reference_number']);
        self::assertSame(ShipmentClient::REPLAY_EARLIER, $result['replay']);
    }

    public function testAFreshAnswerIsNoReplay(): void
    {
        $result = $this->client([$this->created('R1')])->create($this->payload(), 1);

        self::assertSame(ShipmentClient::REPLAY_NONE, $result['replay']);
        self::assertSame('shipment_registered', $result['status']);
    }

    public function testACancelAnswered409IsARefusal(): void
    {
        $client = $this->client([new ConflictException('Identical request in progress', 3)]);

        try {
            $client->cancel('R1', 1);
            self::fail('expected a refusal');
        } catch (OutcomeUnknownException $e) {
            self::fail('a 409 on cancel is a refusal');
        } catch (ApiException $e) {
            self::assertCount(1, $this->http->requests);
        }
    }

    // -----------------------------------------------------------------------

    /**
     * @param array<int, \PigeonExpress\HttpClient\Response|\Throwable> $script
     */
    private function client(array $script, int $conflictRetries = ShipmentClient::CONFLICT_RETRIES): ShipmentClient
    {
        $this->http = new FakeHttpClient($script);

        $sdk = new PigeonExpress('k', 's');
        $sdk->setHttpClient($this->http);

        $factory = $this->createMock(ClientFactory::class);
        $factory->method('create')->willReturn($sdk);

        $config = $this->createMock(ConfigInterface::class);
        $this->limits = $this->createMock(CarrierLimits::class);

        return new ShipmentClient($config, new NullLogger(), $this->limits, $factory, $conflictRetries);
    }

    /**
     * @param array<string, string> $headers
     */
    private function created(string $reference, array $headers = []): \PigeonExpress\HttpClient\Response
    {
        return FakeHttpClient::json(201, ['data' => [
            'reference_number' => $reference,
            'status' => 'Регистрирана',
            'status_code' => 'shipment_registered',
            'delivery_price' => 6.5,
        ]], $headers);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'receiver_name' => 'Иван Петров',
            'receiver_phone' => '+359888123456',
            'pickup_type' => 'office',
            'delivery_type' => 'office',
            'service_type' => 'standard',
            'who_pays' => 'receiver',
            'pickup_office_id' => '149',
            'delivery_office_id' => 12,
            'packages' => [['weight' => 1.0, 'length' => 30, 'width' => 20, 'height' => 15]],
            'note' => 'Оставете на портиера',
        ];
    }
}
