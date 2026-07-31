<?php
/**
 * Single source of truth for the service_codes object sent to the API.
 *
 * Rating and shipment creation previously built this twice, and drifted: the
 * shipment sent cod_amount at the top level, neither honoured which services a
 * delivery channel can actually perform, and both hardcoded which codes carry a
 * numeric value. One builder, used by both, is the fix.
 *
 * The API expects an object keyed by service code, with true for a checkbox
 * service and a number for a text service.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Service;

use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\AdditionalService;
use PigeonExpress\Shipping\Model\AdditionalServiceRepository;
use PigeonExpress\Shipping\Model\Config\Source\ReviewAndTest;
use Psr\Log\LoggerInterface;

class ServiceCodesBuilder
{
    /** Cash on delivery amount, applicable to every delivery channel. */
    public const CODE_COD_AMOUNT = 'cod_amount';

    /** Insurance value, applicable to every delivery channel. */
    public const CODE_DECLARED_VALUE = 'declared_value';

    /** Recipient may inspect the parcel before paying. */
    public const CODE_REVIEW = 'shipment_review';

    /** Recipient may inspect and test before paying. Includes the above. */
    public const CODE_TEST = 'shipment_test_before_payment';

    /** Recipient is texted when the parcel arrives. */
    public const CODE_SMS_RECEIVER = 'sms_notification_receiver';

    /**
     * Admin toggle code to API service code.
     *
     * Each key is a `service_<code>` field in system.xml. Public because the
     * admin form uses it to hide toggles for services the account lacks, and
     * the mapping must not exist in two places.
     */
    public const CONFIG_TO_API_CODE = [
        'pos_payment'          => 'cod_card_fee',
        'special_packaging'    => 'requires_special_packaging',
        'return_at_my_expense' => 'paper_return_receipt',
        'requires_signature'   => 'ID_verification_and_document_signature',
        'return_receipt'       => 'return_receipt',
        'return_documents'     => 'service_return_documents',
        'declared_value'       => self::CODE_DECLARED_VALUE,
    ];

    /**
     * The subset of the above that are plain on/off flags.
     *
     * `declared_value` is excluded because it is a text service: switching it on
     * sends the goods value, not `true`.
     */
    private const BOOLEAN_SERVICE_MAP = [
        'pos_payment'          => 'cod_card_fee',
        'special_packaging'    => 'requires_special_packaging',
        'return_at_my_expense' => 'paper_return_receipt',
        'requires_signature'   => 'ID_verification_and_document_signature',
        'return_receipt'       => 'return_receipt',
        'return_documents'     => 'service_return_documents',
    ];

    /**
     * Which services each delivery channel can actually perform.
     *
     * This is a structural fact, not a price, and the API does not publish it.
     * A parcel locker has nobody to check identity documents, hand over a paper
     * receipt or take back signed paperwork, so those services are meaningless
     * there however the merchant has configured them.
     */
    private const APPLICABLE_BY_DELIVERY_TYPE = [
        'address' => [
            self::CODE_COD_AMOUNT,
            self::CODE_DECLARED_VALUE,
            'cod_card_fee',
            'requires_special_packaging',
            'paper_return_receipt',
            'return_receipt',
            'service_return_documents',
            'ID_verification_and_document_signature',
            self::CODE_REVIEW,
            self::CODE_TEST,
        ],
        'office' => [
            self::CODE_COD_AMOUNT,
            self::CODE_DECLARED_VALUE,
            'cod_card_fee',
            'requires_special_packaging',
            'paper_return_receipt',
            'return_receipt',
            'service_return_documents',
            'ID_verification_and_document_signature',
            self::CODE_REVIEW,
            self::CODE_TEST,
        ],
        // Locker: no human at the point of delivery.
        'locker' => [
            self::CODE_COD_AMOUNT,
            self::CODE_DECLARED_VALUE,
            'cod_card_fee',
            self::CODE_SMS_RECEIVER,
        ],
    ];

    /**
     * Services the module treats as mutually exclusive regardless of what the
     * API declares, keyed by an internal group name.
     *
     * The catalogue currently returns a null option_group for every service, so
     * the API does not tell us that inspecting and inspect-plus-test overlap.
     * The later entry wins, because it is the more complete service.
     */
    private const LOCAL_EXCLUSIVE_GROUPS = [
        'shipment_view' => [self::CODE_REVIEW, self::CODE_TEST],
    ];

    /** @var ConfigInterface */
    private $config;

    /** @var AdditionalServiceRepository */
    private $serviceRepository;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        ConfigInterface $config,
        AdditionalServiceRepository $serviceRepository,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->serviceRepository = $serviceRepository;
        $this->logger = $logger;
    }

    /**
     * Build the service_codes object.
     *
     * @param string $deliveryType Module delivery type: address, office or aps
     * @param string $apiDeliveryType API delivery type: address, office or locker
     * @param float $codAmount Zero when the order is not cash on delivery
     * @param bool $includeCod Rating leaves COD out so the fee is charged once
     * @param float $goodsValue Value of the goods, used when insurance is on
     * @return array<string, mixed>
     */
    public function build(
        string $deliveryType,
        string $apiDeliveryType,
        int $storeId,
        float $codAmount = 0.0,
        bool $includeCod = true,
        float $goodsValue = 0.0
    ): array {
        $codes = [];

        foreach (self::BOOLEAN_SERVICE_MAP as $configCode => $apiCode) {
            if ($this->config->isAdditionalServiceEnabled($configCode, $storeId)) {
                $codes[$apiCode] = true;
            }
        }

        $reviewCode = $this->resolveReviewCode($deliveryType, $storeId);
        if ($reviewCode !== null) {
            $codes[$reviewCode] = true;
        }

        // A locker has nobody waiting, so the recipient has to be told it arrived.
        if ($apiDeliveryType === 'locker') {
            $codes[self::CODE_SMS_RECEIVER] = true;
        }

        // Insurance covers the contents, so the sum insured is the goods value.
        // Sending a fixed figure unrelated to what is in the parcel would either
        // under insure the customer or overpay the carrier.
        if ($this->config->isDeclaredValueEnabled($storeId)) {
            if ($goodsValue > 0.0) {
                $codes[self::CODE_DECLARED_VALUE] = round($goodsValue, 2);
            } else {
                $this->log($storeId, 'insurance is enabled but the goods value is zero', self::CODE_DECLARED_VALUE);
            }
        }

        if ($includeCod && $codAmount > 0.0) {
            $codes[self::CODE_COD_AMOUNT] = round($codAmount, 2);
        }

        $codes = $this->removeNotApplicable($codes, $apiDeliveryType, $storeId);
        $codes = $this->removeNotOffered($codes, $storeId);
        $codes = $this->resolveExclusiveGroups($codes, $storeId);

        return $this->castValues($codes, $storeId);
    }

    /**
     * Which of the two inspection tiers the merchant chose, if any.
     */
    private function resolveReviewCode(string $deliveryType, int $storeId): ?string
    {
        $setting = $this->config->getReviewAndTest($deliveryType, $storeId);

        if ($setting === ReviewAndTest::REVIEW) {
            return self::CODE_REVIEW;
        }

        if ($setting === ReviewAndTest::TEST) {
            return self::CODE_TEST;
        }

        return null;
    }

    /**
     * Drop services this delivery channel cannot perform.
     *
     * @param array<string, mixed> $codes
     * @return array<string, mixed>
     */
    private function removeNotApplicable(array $codes, string $apiDeliveryType, int $storeId): array
    {
        $applicable = self::APPLICABLE_BY_DELIVERY_TYPE[$apiDeliveryType] ?? null;
        if ($applicable === null) {
            return $codes;
        }

        foreach (array_keys($codes) as $code) {
            if (!in_array($code, $applicable, true)) {
                unset($codes[$code]);
                $this->log($storeId, 'not available for ' . $apiDeliveryType . ' delivery', $code);
            }
        }

        return $codes;
    }

    /**
     * Drop services the account's catalogue does not contain.
     *
     * When the catalogue has never synced we send everything rather than
     * silently discarding a service the merchant switched on.
     *
     * @param array<string, mixed> $codes
     * @return array<string, mixed>
     */
    private function removeNotOffered(array $codes, int $storeId): array
    {
        $catalogue = $this->serviceRepository->getActiveByCode();
        if (empty($catalogue)) {
            return $codes;
        }

        foreach (array_keys($codes) as $code) {
            if (!isset($catalogue[$code])) {
                unset($codes[$code]);
                $this->log($storeId, 'not offered by the API', $code);
            }
        }

        return $codes;
    }

    /**
     * Keep at most one service per mutually exclusive group.
     *
     * Honours the API's own option_group when it publishes one, and falls back
     * to the groups this module knows about.
     *
     * @param array<string, mixed> $codes
     * @return array<string, mixed>
     */
    private function resolveExclusiveGroups(array $codes, int $storeId): array
    {
        $groups = self::LOCAL_EXCLUSIVE_GROUPS;

        // Fold in anything the API declares as grouped.
        foreach ($this->serviceRepository->getActiveByCode() as $code => $service) {
            $group = $service->getOptionGroup();
            if ($group === null || $group === '') {
                continue;
            }
            $groups[$group][] = $code;
        }

        foreach ($groups as $group => $members) {
            $present = array_values(array_filter(
                $members,
                static function ($code) use ($codes): bool {
                    return array_key_exists($code, $codes);
                }
            ));

            if (count($present) < 2) {
                continue;
            }

            // The last member listed wins: for the inspection tiers that is the
            // more complete service, which already contains the other.
            $keep = end($present);
            foreach ($present as $code) {
                if ($code !== $keep) {
                    unset($codes[$code]);
                }
            }

            $this->logger->info(
                '[PigeonExpress] Additional services "' . implode('", "', $present) . '" are mutually '
                . 'exclusive in group "' . $group . '"; sending "' . $keep . '" only.',
                ['storeId' => $storeId]
            );
        }

        return $codes;
    }

    /**
     * Shape each value according to the catalogue's input_type.
     *
     * A text service carries a number, everything else carries true. Reading
     * this from the synced catalogue means a new service works without the
     * module hardcoding its type.
     *
     * @param array<string, mixed> $codes
     * @return array<string, mixed>
     */
    private function castValues(array $codes, int $storeId): array
    {
        foreach ($codes as $code => $value) {
            $service = $this->serviceRepository->getByCode($code);

            // Unknown to the catalogue: keep whatever the caller supplied.
            if ($service === null) {
                continue;
            }

            if ($service->expectsValue()) {
                if (!is_numeric($value)) {
                    unset($codes[$code]);
                    $this->log($storeId, 'expects a numeric value but none was configured', $code);
                    continue;
                }
                $codes[$code] = round((float) $value, 2);
                continue;
            }

            $codes[$code] = true;
        }

        return $codes;
    }

    private function log(int $storeId, string $reason, string $code): void
    {
        if (!$this->config->isLoggingEnabled($storeId)) {
            return;
        }

        $this->logger->info('[PigeonExpress] Skipping additional service: ' . $reason, [
            'storeId'      => $storeId,
            'service_code' => $code,
        ]);
    }
}
