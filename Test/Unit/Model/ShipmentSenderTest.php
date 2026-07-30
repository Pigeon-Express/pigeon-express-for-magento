<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\AdditionalService;
use PigeonExpress\Shipping\Model\AdditionalServiceRepository;
use PigeonExpress\Shipping\Model\ShipmentSender;
use Psr\Log\LoggerInterface;

/**
 * Covers the shape of the service_codes object sent to POST /shipments.
 *
 * CreateShipmentRequest has no top level cod_amount and no sms_notification
 * field. Sending them there meant the courier was never told to collect money on
 * a real order, so the payload shape is worth asserting directly even though the
 * builder is private.
 */
class ShipmentSenderTest extends TestCase
{
    /** @var ConfigInterface&MockObject */
    private $config;

    /** @var AdditionalServiceRepository&MockObject */
    private $repository;

    /** @var ShipmentSender */
    private $sender;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigInterface::class);
        $this->repository = $this->createMock(AdditionalServiceRepository::class);

        $this->config->method('isLoggingEnabled')->willReturn(false);
        $this->config->method('isAdditionalServiceEnabled')->willReturn(false);
        $this->config->method('getReviewAndTest')->willReturn('');
        $this->config->method('getDeclaredValue')->willReturn(null);

        $this->sender = (new ObjectManager($this))->getObject(ShipmentSender::class, [
            'config' => $this->config,
            'additionalServiceRepository' => $this->repository,
            'logger' => $this->createMock(LoggerInterface::class),
        ]);
    }

    public function testCodAmountTravelsInsideServiceCodes(): void
    {
        $this->offerServices(['cod_amount']);

        $codes = $this->buildServiceCodes('office', 'office', 123.45);

        $this->assertArrayHasKey('cod_amount', $codes);
        $this->assertSame(123.45, $codes['cod_amount']);
    }

    public function testCodAmountIsRoundedToTwoDecimals(): void
    {
        $this->offerServices(['cod_amount']);

        $codes = $this->buildServiceCodes('office', 'office', 99.999);

        $this->assertSame(100.0, $codes['cod_amount']);
    }

    public function testNoCodAmountWhenTheOrderIsNotCashOnDelivery(): void
    {
        $this->offerServices(['cod_amount']);

        $codes = $this->buildServiceCodes('office', 'office', 0.0);

        $this->assertArrayNotHasKey('cod_amount', $codes);
    }

    public function testTheUndocumentedSmsNotificationKeyIsNeverSent(): void
    {
        $this->offerServices(['sms_notification_receiver']);

        $codes = $this->buildServiceCodes('aps', 'locker', 50.0);

        $this->assertArrayNotHasKey('sms_notification', $codes, 'no such field exists in the API');
    }

    public function testLockerDeliveryRequestsRecipientSmsUnderItsDocumentedCode(): void
    {
        $this->offerServices(['sms_notification_receiver', 'cod_amount']);

        $codes = $this->buildServiceCodes('aps', 'locker', 50.0);

        $this->assertTrue($codes['sms_notification_receiver']);
    }

    public function testRecipientSmsIsNotRequestedForOfficeDelivery(): void
    {
        $this->offerServices(['sms_notification_receiver']);

        $codes = $this->buildServiceCodes('office', 'office', 0.0);

        $this->assertArrayNotHasKey('sms_notification_receiver', $codes);
    }

    public function testServicesTheApiDoesNotOfferAreDropped(): void
    {
        // The account catalogue does not include this code, so sending it would
        // risk failing the whole shipment.
        $this->offerServices(['cod_amount']);

        $codes = $this->buildServiceCodes('aps', 'locker', 10.0);

        $this->assertArrayNotHasKey('sms_notification_receiver', $codes);
    }

    public function testAnUnsyncedCatalogueDoesNotSilentlyDropEverything(): void
    {
        // With no catalogue at all we must not swallow services the merchant
        // deliberately switched on.
        $this->repository->method('getActiveByCode')->willReturn([]);

        $codes = $this->buildServiceCodes('aps', 'locker', 10.0);

        $this->assertTrue($codes['sms_notification_receiver']);
    }

    public function testEnabledBooleanServicesAreSentAsTrue(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('isLoggingEnabled')->willReturn(false);
        $config->method('getReviewAndTest')->willReturn('');
        $config->method('getDeclaredValue')->willReturn(null);
        $config->method('isAdditionalServiceEnabled')->willReturnCallback(
            static function (string $code): bool {
                return $code === 'return_receipt';
            }
        );

        $sender = (new ObjectManager($this))->getObject(ShipmentSender::class, [
            'config' => $config,
            'additionalServiceRepository' => $this->repository,
            'logger' => $this->createMock(LoggerInterface::class),
        ]);
        $this->offerServices(['return_receipt']);

        $codes = $this->invokeBuild($sender, 'office', 'office', 0.0);

        $this->assertTrue($codes['return_receipt']);
    }

    public function testDeclaredValueIsSentAsANumberNotAFlag(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('isLoggingEnabled')->willReturn(false);
        $config->method('isAdditionalServiceEnabled')->willReturn(false);
        $config->method('getReviewAndTest')->willReturn('');
        $config->method('getDeclaredValue')->willReturn(250.0);

        $sender = (new ObjectManager($this))->getObject(ShipmentSender::class, [
            'config' => $config,
            'additionalServiceRepository' => $this->repository,
            'logger' => $this->createMock(LoggerInterface::class),
        ]);
        $this->offerServices(['declared_value']);

        $codes = $this->invokeBuild($sender, 'office', 'office', 0.0);

        $this->assertSame(250.0, $codes['declared_value']);
    }

    public function testServiceCodesIsAnObjectKeyedByCodeNotAList(): void
    {
        $this->offerServices(['cod_amount']);

        $codes = $this->buildServiceCodes('office', 'office', 42.0);

        $this->assertArrayHasKey('cod_amount', $codes);
        $this->assertNotSame(
            array_keys($codes),
            range(0, count($codes) - 1),
            'the API rejects a positional array here'
        );
    }

    // -----------------------------------------------------------------------

    /** @param string[] $codes */
    private function offerServices(array $codes): void
    {
        $catalogue = [];
        foreach ($codes as $code) {
            $catalogue[$code] = $this->createMock(AdditionalService::class);
        }

        $this->repository->method('getActiveByCode')->willReturn($catalogue);
    }

    /** @return array<string, mixed> */
    private function buildServiceCodes(string $deliveryType, string $apiDeliveryType, float $codAmount): array
    {
        return $this->invokeBuild($this->sender, $deliveryType, $apiDeliveryType, $codAmount);
    }

    /** @return array<string, mixed> */
    private function invokeBuild(
        ShipmentSender $sender,
        string $deliveryType,
        string $apiDeliveryType,
        float $codAmount
    ): array {
        $method = (new \ReflectionClass(ShipmentSender::class))->getMethod('buildServiceCodes');
        $method->setAccessible(true);

        return $method->invoke($sender, $deliveryType, $apiDeliveryType, 1, $codAmount);
    }
}
