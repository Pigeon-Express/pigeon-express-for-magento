<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\AdditionalService;
use PigeonExpress\Shipping\Model\AdditionalServiceRepository;
use PigeonExpress\Shipping\Model\Config\Source\ReviewAndTest;
use PigeonExpress\Shipping\Model\Service\ServiceCodesBuilder;
use Psr\Log\LoggerInterface;

/**
 * The service_codes object is where several money bugs lived: cod_amount sent at
 * the top level so the courier never collected, services requested that a locker
 * physically cannot perform, and value-versus-flag decided by hardcoded guesses.
 */
class ServiceCodesBuilderTest extends TestCase
{
    /** Every code the demo account actually offers. */
    private const CATALOGUE = [
        'cod_amount'                             => 'text',
        'declared_value'                         => 'text',
        'cod_card_fee'                           => 'checkbox',
        'return_receipt'                         => 'checkbox',
        'paper_return_receipt'                   => 'checkbox',
        'service_return_documents'               => 'checkbox',
        'ID_verification_and_document_signature' => 'checkbox',
        'shipment_review'                        => 'checkbox',
        'shipment_test_before_payment'           => 'checkbox',
    ];

    /** @var ConfigInterface&MockObject */
    private $config;

    /** @var AdditionalServiceRepository&MockObject */
    private $repository;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigInterface::class);
        $this->repository = $this->createMock(AdditionalServiceRepository::class);

        $this->config->method('isLoggingEnabled')->willReturn(false);
        $this->config->method('isAdditionalServiceEnabled')->willReturn(false);
        $this->config->method('getReviewAndTest')->willReturn(ReviewAndTest::NO);
        $this->config->method('isDeclaredValueEnabled')->willReturn(false);
    }

    // --- P1-3: payload shape ------------------------------------------------

    public function testCodAmountTravelsInsideServiceCodes(): void
    {
        $codes = $this->build('office', 'office', 250.0);

        $this->assertSame(250.0, $codes['cod_amount']);
    }

    public function testCodAmountIsRoundedToTwoDecimals(): void
    {
        $this->assertSame(100.0, $this->build('office', 'office', 99.999)['cod_amount']);
    }

    public function testNoCodAmountForANonCodOrder(): void
    {
        $this->assertArrayNotHasKey('cod_amount', $this->build('office', 'office', 0.0));
    }

    public function testRatingCanExcludeCodSoTheFeeIsNotChargedTwice(): void
    {
        $codes = $this->build('office', 'office', 250.0, false);

        $this->assertArrayNotHasKey('cod_amount', $codes);
    }

    public function testServiceCodesIsKeyedByCodeNotPositional(): void
    {
        $codes = $this->build('office', 'office', 42.0);

        $this->assertNotSame(array_keys($codes), range(0, count($codes) - 1));
    }

    // --- P2-11: per delivery type applicability -----------------------------

    public function testLockerDeliveryDropsServicesNeedingAPersonPresent(): void
    {
        $this->config = $this->configWithServices(['requires_signature', 'return_at_my_expense', 'return_documents']);

        $codes = $this->build('aps', 'locker', 100.0);

        $this->assertArrayNotHasKey('ID_verification_and_document_signature', $codes);
        $this->assertArrayNotHasKey('paper_return_receipt', $codes);
        $this->assertArrayNotHasKey('service_return_documents', $codes);
    }

    public function testLockerDeliveryKeepsServicesThatDoNotNeedAPerson(): void
    {
        $this->config = $this->configWithServices(['pos_payment']);

        $codes = $this->build('aps', 'locker', 100.0);

        $this->assertTrue($codes['cod_card_fee']);
        $this->assertSame(100.0, $codes['cod_amount']);
    }

    public function testOfficeDeliveryKeepsServicesNeedingAPersonPresent(): void
    {
        $this->config = $this->configWithServices(['requires_signature', 'return_at_my_expense']);

        $codes = $this->build('office', 'office', 0.0);

        $this->assertTrue($codes['ID_verification_and_document_signature']);
        $this->assertTrue($codes['paper_return_receipt']);
    }

    public function testInspectionIsNotOfferedForLockerDelivery(): void
    {
        $this->config = $this->configWithReview(ReviewAndTest::TEST);

        $this->assertArrayNotHasKey('shipment_test_before_payment', $this->build('aps', 'locker', 0.0));
    }

    // --- P2-9: inspection tiers and exclusivity -----------------------------

    public function testInspectionOnlyOrdersTheCheaperService(): void
    {
        $this->config = $this->configWithReview(ReviewAndTest::REVIEW);

        $codes = $this->build('office', 'office', 0.0);

        $this->assertTrue($codes['shipment_review']);
        $this->assertArrayNotHasKey('shipment_test_before_payment', $codes);
    }

    public function testInspectionAndTestingOrdersTheFullerService(): void
    {
        $this->config = $this->configWithReview(ReviewAndTest::TEST);

        $codes = $this->build('office', 'office', 0.0);

        $this->assertTrue($codes['shipment_test_before_payment']);
        $this->assertArrayNotHasKey('shipment_review', $codes);
    }

    public function testNeitherInspectionServiceWhenTurnedOff(): void
    {
        $codes = $this->build('office', 'office', 0.0);

        $this->assertArrayNotHasKey('shipment_review', $codes);
        $this->assertArrayNotHasKey('shipment_test_before_payment', $codes);
    }

    public function testAnApiDeclaredOptionGroupIsHonoured(): void
    {
        // If the API starts grouping services, only one per group may be sent.
        $this->config = $this->configWithServices(['return_receipt', 'return_at_my_expense']);
        $catalogue = $this->catalogue(self::CATALOGUE, [
            'return_receipt'       => 'receipts',
            'paper_return_receipt' => 'receipts',
        ]);

        $codes = $this->buildWith($catalogue, 'office', 'office', 0.0);

        $present = array_intersect_key($codes, array_flip(['return_receipt', 'paper_return_receipt']));
        $this->assertCount(1, $present, 'only one service per option_group may be sent');
    }

    // --- P2-10: value versus flag from input_type ---------------------------

    public function testInsuranceSendsTheGoodsValueAsANumber(): void
    {
        // The sum insured is the value of what is in the parcel. A fixed figure
        // unrelated to the contents would under insure or overcharge.
        $this->config = $this->configWithInsurance();

        $codes = $this->build('office', 'office', 0.0, true, 250.0);

        $this->assertSame(250.0, $codes['declared_value']);
    }

    public function testInsuranceIsSkippedWhenThereIsNoGoodsValue(): void
    {
        $this->config = $this->configWithInsurance();

        $this->assertArrayNotHasKey('declared_value', $this->build('office', 'office', 0.0, true, 0.0));
    }

    public function testNoInsuranceWhenTheServiceIsOff(): void
    {
        $this->assertArrayNotHasKey('declared_value', $this->build('office', 'office', 0.0, true, 250.0));
    }

    public function testACheckboxServiceCarriesTrue(): void
    {
        $this->config = $this->configWithServices(['return_receipt']);

        $this->assertTrue($this->build('office', 'office', 0.0)['return_receipt']);
    }

    public function testTheCatalogueDecidesTheShapeNotAHardcodedList(): void
    {
        // Same code, declared as a checkbox this time. The builder must follow
        // the catalogue rather than its own assumption that it is numeric.
        $catalogue = $this->catalogue(['declared_value' => 'checkbox'] + self::CATALOGUE);
        $this->config = $this->configWithInsurance();

        $this->assertTrue($this->buildWith($catalogue, 'office', 'office', 0.0, true, 250.0)['declared_value']);
    }

    // --- catalogue gating ---------------------------------------------------

    public function testServicesMissingFromTheAccountCatalogueAreDropped(): void
    {
        $this->config = $this->configWithServices(['special_packaging']);

        // requires_special_packaging is absent from the demo catalogue.
        $this->assertArrayNotHasKey('requires_special_packaging', $this->build('office', 'office', 0.0));
    }

    public function testAnUnsyncedCatalogueDoesNotDropEverything(): void
    {
        $this->config = $this->configWithServices(['return_receipt']);
        $this->repository->method('getActiveByCode')->willReturn([]);
        $this->repository->method('getByCode')->willReturn(null);

        $builder = new ServiceCodesBuilder($this->config, $this->repository, $this->createMock(LoggerInterface::class));

        $this->assertTrue($builder->build('office', 'office', 1, 0.0)['return_receipt']);
    }

    // -----------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function build(
        string $deliveryType,
        string $apiType,
        float $cod,
        bool $includeCod = true,
        float $goodsValue = 0.0
    ): array {
        return $this->buildWith($this->catalogue(self::CATALOGUE), $deliveryType, $apiType, $cod, $includeCod, $goodsValue);
    }

    /**
     * @param array<string, AdditionalService> $catalogue
     * @return array<string, mixed>
     */
    private function buildWith(
        array $catalogue,
        string $deliveryType,
        string $apiType,
        float $cod,
        bool $includeCod = true,
        float $goodsValue = 0.0
    ): array {
        $this->repository = $this->createMock(AdditionalServiceRepository::class);
        $this->repository->method('getActiveByCode')->willReturn($catalogue);
        $this->repository->method('getByCode')->willReturnCallback(
            static function (string $code) use ($catalogue) {
                return $catalogue[$code] ?? null;
            }
        );

        $builder = new ServiceCodesBuilder($this->config, $this->repository, $this->createMock(LoggerInterface::class));

        return $builder->build($deliveryType, $apiType, 1, $cod, $includeCod, $goodsValue);
    }

    /**
     * @param array<string, string> $codes code => input_type
     * @param array<string, string> $groups code => option_group
     * @return array<string, AdditionalService>
     */
    private function catalogue(array $codes, array $groups = []): array
    {
        $catalogue = [];
        foreach ($codes as $code => $inputType) {
            $service = $this->createMock(AdditionalService::class);
            $service->method('expectsValue')->willReturn($inputType === 'text');
            $service->method('getInputType')->willReturn($inputType);
            $service->method('getOptionGroup')->willReturn($groups[$code] ?? null);
            $catalogue[$code] = $service;
        }

        return $catalogue;
    }

    /**
     * @param string[] $enabled Admin toggle codes that are switched on
     * @return ConfigInterface&MockObject
     */
    private function configWithServices(array $enabled)
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('isLoggingEnabled')->willReturn(false);
        $config->method('getReviewAndTest')->willReturn(ReviewAndTest::NO);
        $config->method('isDeclaredValueEnabled')->willReturn(false);
        $config->method('isAdditionalServiceEnabled')->willReturnCallback(
            static function (string $code) use ($enabled): bool {
                return in_array($code, $enabled, true);
            }
        );

        return $config;
    }

    /** @return ConfigInterface&MockObject */
    private function configWithInsurance()
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('isLoggingEnabled')->willReturn(false);
        $config->method('isAdditionalServiceEnabled')->willReturn(false);
        $config->method('getReviewAndTest')->willReturn(ReviewAndTest::NO);
        $config->method('isDeclaredValueEnabled')->willReturn(true);

        return $config;
    }

    /** @return ConfigInterface&MockObject */
    private function configWithReview(string $setting)
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('isLoggingEnabled')->willReturn(false);
        $config->method('isAdditionalServiceEnabled')->willReturn(false);
        $config->method('isDeclaredValueEnabled')->willReturn(false);
        $config->method('getReviewAndTest')->willReturn($setting);

        return $config;
    }
}
