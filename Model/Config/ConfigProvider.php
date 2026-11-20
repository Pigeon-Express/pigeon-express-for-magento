<?php
/**
 * Pigeon Express configuration provider.
 * Reads from carriers/pigeonexpress/*; store-scoped where appropriate.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\Config\Source\LabelContents;

class ConfigProvider implements ConfigInterface
{
    private const XML_PATH_CARRIER = 'carriers/' . self::CARRIER_CODE . '/';

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var EncryptorInterface
     */
    private $encryptor;

    /**
     * @var string[]
     */
    private static $deliveryTypes = [
        self::DELIVERY_TYPE_ADDRESS,
        self::DELIVERY_TYPE_OFFICE,
        self::DELIVERY_TYPE_APS,
    ];

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        EncryptorInterface $encryptor
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->encryptor = $encryptor;
    }

    /**
     * @inheritdoc
     */
    public function isActive(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_CARRIER . 'active',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * The account and its environment are global. The location tables hold one
     * dataset per environment, so a store on its own key or test mode would
     * check out against offices from the wrong one. $storeId is accepted and
     * ignored, so callers need not know.
     *
     * The key is the one kept for the environment chosen with Test Mode. The
     * single key of earlier versions is read only while neither environment
     * has one: once a merchant has entered keys per environment, a missing
     * live key must not quietly become the test key, which the live API would
     * reject with a confusing error.
     *
     * @inheritdoc
     */
    public function getApiKey(?int $storeId = null): string
    {
        $key = $this->readSecret(
            $this->isTestMode() ? self::XML_PATH_API_KEY_TEST : self::XML_PATH_API_KEY_LIVE
        );
        if ($key !== '') {
            return $key;
        }

        if ($this->readSecret(self::XML_PATH_API_KEY_TEST) === ''
            && $this->readSecret(self::XML_PATH_API_KEY_LIVE) === ''
        ) {
            return $this->readSecret(self::XML_PATH_API_KEY_LEGACY);
        }

        return '';
    }

    /**
     * @inheritdoc
     */
    public function getApiSecret(?int $storeId = null): string
    {
        $key = $this->readSecret(
            $this->isTestMode() ? self::XML_PATH_API_KEY_TEST : self::XML_PATH_API_KEY_LIVE
        );

        if ($key !== '') {
            $secret = $this->readSecret(
                $this->isTestMode() ? self::XML_PATH_API_SECRET_TEST : self::XML_PATH_API_SECRET_LIVE
            );

            return $secret !== '' ? $secret : $key;
        }

        // The legacy pair, under the same rule as getApiKey().
        $legacyKey = $this->getApiKey();
        if ($legacyKey === '') {
            return '';
        }
        $secret = $this->readSecret(self::XML_PATH_API_SECRET_LEGACY);

        return $secret !== '' ? $secret : $legacyKey;
    }

    /**
     * A default scope, encrypted value, decrypted; empty when unset.
     */
    private function readSecret(string $path): string
    {
        $value = $this->scopeConfig->getValue($path, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
        if ($value === null || $value === '') {
            return '';
        }

        return trim($this->decrypt((string) $value));
    }

    /**
     * @inheritdoc
     */
    public function isHideContentOnLabel(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_HIDE_CONTENT_ON_LABEL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Decrypt value (API key/secret use Encrypted backend and are stored encrypted).
     *
     * @param string $value
     * @return string
     */
    private function decrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }
        try {
            $decrypted = $this->encryptor->decrypt($value);
            return $decrypted !== null ? $decrypted : $value;
        } catch (\Exception $e) {
            return $value;
        }
    }

    /**
     * Global, see getApiKey().
     *
     * @inheritdoc
     */
    public function isTestMode(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_TEST_MODE,
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT
        );
    }

    /**
     * @inheritdoc
     */
    public function isLoggingEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_CARRIER . 'enable_logging',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * @inheritdoc
     */
    public function isDeliveryTypeEnabled(string $deliveryType, ?int $storeId = null): bool
    {
        $this->validateDeliveryType($deliveryType);
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_CARRIER . $deliveryType . '_enabled',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * @inheritdoc
     */
    public function getDeliveryTypeTitle(string $deliveryType, ?int $storeId = null): string
    {
        $this->validateDeliveryType($deliveryType);
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_CARRIER . $deliveryType . '_title',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return $value !== null ? (string) $value : ucfirst($deliveryType);
    }

    /**
     * @inheritdoc
     */
    public function getDeliveryTypePricingMode(string $deliveryType, ?int $storeId = null): string
    {
        $this->validateDeliveryType($deliveryType);
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_CARRIER . $deliveryType . '_pricing_mode',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return $value === 'dynamic' ? 'dynamic' : 'fixed';
    }

    /**
     * @inheritdoc
     */
    public function getDeliveryTypeFlatRate(string $deliveryType, ?int $storeId = null): float
    {
        $this->validateDeliveryType($deliveryType);
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_CARRIER . $deliveryType . '_flat_rate',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return $value !== null && $value !== '' ? (float) $value : 0.0;
    }

    /**
     * @inheritdoc
     */
    public function isCodFeeCharged(string $deliveryType, ?int $storeId = null): bool
    {
        if ($this->getDeliveryTypePricingMode($deliveryType, $storeId) === 'dynamic') {
            return true;
        }

        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_CARRIER . $deliveryType . '_flat_rate_cod_fee',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * @inheritdoc
     */
    public function getLabelContentsMode(?int $storeId = null): string
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_CARRIER . 'label_contents',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        // Anything unrecognised means product names, which is what the module
        // sent before this setting existed.
        return $value === LabelContents::FIXED_TEXT
            ? LabelContents::FIXED_TEXT
            : LabelContents::PRODUCT_NAMES;
    }

    /**
     * @inheritdoc
     */
    public function getLabelContentsText(?int $storeId = null): string
    {
        $value = trim((string) $this->scopeConfig->getValue(
            self::XML_PATH_CARRIER . 'label_contents_text',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));

        return $value !== '' ? $value : (string) __('Goods');
    }

    /**
     * @inheritdoc
     */
    public function isFreeShippingThresholdEnabled(string $deliveryType, ?int $storeId = null): bool
    {
        $this->validateDeliveryType($deliveryType);
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_CARRIER . $deliveryType . '_free_shipping_enabled',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * @inheritdoc
     */
    public function getFreeShippingThreshold(string $deliveryType, ?int $storeId = null): float
    {
        $this->validateDeliveryType($deliveryType);
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_CARRIER . $deliveryType . '_free_shipping_threshold',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        // A negative threshold would make every cart qualify, including an empty
        // one, so it is read as no threshold rather than as free for everyone.
        $threshold = $value !== null && $value !== '' ? (float) $value : 0.0;

        return $threshold > 0.0 ? $threshold : 0.0;
    }

    /**
     * @inheritdoc
     */
    public function getDeliveryTypeSortOrder(string $deliveryType, ?int $storeId = null): int
    {
        $this->validateDeliveryType($deliveryType);
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_CARRIER . $deliveryType . '_sort_order',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return $value !== null && $value !== '' ? (int) $value : 0;
    }

    /**
     * @inheritdoc
     *
     * Returned in the order the merchant configured. Ties keep the declaration
     * order of $deliveryTypes, PHP sorts being stable, so two types left on the
     * same number do not swap places between requests.
     */
    public function getEnabledDeliveryTypes(?int $storeId = null): array
    {
        $enabled = [];
        foreach (self::$deliveryTypes as $type) {
            if ($this->isDeliveryTypeEnabled($type, $storeId)) {
                $enabled[] = $type;
            }
        }

        usort(
            $enabled,
            function (string $a, string $b) use ($storeId): int {
                return $this->getDeliveryTypeSortOrder($a, $storeId)
                    <=> $this->getDeliveryTypeSortOrder($b, $storeId);
            }
        );

        return $enabled;
    }

    /**
     * @inheritdoc
     */
    public function getLocationSearchLimit(?int $storeId = null): int
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_CARRIER . 'location_search_limit',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return $value !== null && $value !== '' ? (int) $value : 20;
    }

    /**
     * @inheritdoc
     */
    public function getWeightAttributeCode(?int $storeId = null): ?string
    {
        return $this->getDimensionAttributeCode('weight_attribute', $storeId);
    }

    public function getLengthAttributeCode(?int $storeId = null): ?string
    {
        return $this->getDimensionAttributeCode('length_attribute', $storeId);
    }

    /**
     * @inheritdoc
     */
    public function getWidthAttributeCode(?int $storeId = null): ?string
    {
        return $this->getDimensionAttributeCode('width_attribute', $storeId);
    }

    /**
     * @inheritdoc
     */
    public function getHeightAttributeCode(?int $storeId = null): ?string
    {
        return $this->getDimensionAttributeCode('height_attribute', $storeId);
    }

    /**
     * @inheritdoc
     */
    public function getDefaultWeight(?int $storeId = null): float
    {
        return $this->getPositiveFloat('default_weight', $storeId);
    }

    /**
     * @inheritdoc
     */
    public function getDefaultLength(?int $storeId = null): float
    {
        return $this->getPositiveFloat('default_length', $storeId);
    }

    /**
     * @inheritdoc
     */
    public function getDefaultWidth(?int $storeId = null): float
    {
        return $this->getPositiveFloat('default_width', $storeId);
    }

    /**
     * @inheritdoc
     */
    public function getDefaultHeight(?int $storeId = null): float
    {
        return $this->getPositiveFloat('default_height', $storeId);
    }

    /**
     * @inheritdoc
     */
    public function getParcelDefaultsMode(?int $storeId = null): string
    {
        $value = (string) $this->scopeConfig->getValue(
            self::XML_PATH_CARRIER . 'parcel_defaults_mode',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return in_array($value, [self::PARCEL_DEFAULTS_PER_ITEM, self::PARCEL_DEFAULTS_FIXED], true)
            ? $value
            : self::PARCEL_DEFAULTS_PER_SHIPMENT;
    }

    /**
     * Read a float config value; returns 0.0 when empty or negative.
     */
    private function getPositiveFloat(string $field, ?int $storeId = null): float
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_CARRIER . $field,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        if ($value === null || $value === '') {
            return 0.0;
        }
        $float = (float) $value;
        return $float > 0 ? $float : 0.0;
    }

    /**
     * @inheritdoc
     */
    public function getPickupType(?int $storeId = null): string
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_CARRIER . 'pickup_type',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $known = [
            self::PICKUP_TYPE_OFFICE,
            self::PICKUP_TYPE_ADDRESS,
            self::PICKUP_TYPE_LOCKER,
            self::PICKUP_TYPE_SAVED_ADDRESS,
        ];

        return in_array($value, $known, true) ? (string) $value : self::PICKUP_TYPE_OFFICE;
    }

    /**
     * @inheritDoc
     */
    public function isOverrideAddressFields(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_CARRIER . 'override_address_fields',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * @inheritDoc
     */
    public function getCheckoutLogo(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_CARRIER . 'checkout_logo',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * @inheritDoc
     */
    public function getLockerCompartment(?int $storeId = null): array
    {
        $read = function (string $field, float $fallback) use ($storeId): float {
            $value = $this->scopeConfig->getValue(
                self::XML_PATH_CARRIER . $field,
                ScopeInterface::SCOPE_STORE,
                $storeId
            );

            return $value !== null && $value !== '' ? (float) $value : $fallback;
        };

        // The compartment of the lockers currently deployed, 61 wide by 37 high
        // by 44 deep. Zero on any side turns the check off.
        return [
            'length' => $read('locker_max_depth', 44.0),
            'width'  => $read('locker_max_width', 61.0),
            'height' => $read('locker_max_height', 37.0),
        ];
    }

    /**
     * @inheritDoc
     */
    public function getWhoPays(?int $storeId = null): string
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_CARRIER . 'who_pays',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        // Receiver is the default, so anything unrecognised lands there rather
        // than silently billing the merchant for a delivery they expected the
        // recipient to pay for.
        return $value === self::WHO_PAYS_SENDER ? self::WHO_PAYS_SENDER : self::WHO_PAYS_RECEIVER;
    }

    /**
     * @inheritdoc
     */
    public function getPickupOfficeId(?int $storeId = null): ?int
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_CARRIER . 'pickup_office_id',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        if ($value === null || $value === '') {
            return null;
        }
        return (int) $value;
    }

    /**
     * @inheritdoc
     */
    public function getPickupAddressCityId(?int $storeId = null): ?string
    {
        return $this->getTrimmedConfig(self::XML_PATH_CARRIER . 'pickup_address_city_id', $storeId);
    }

    /**
     * @inheritdoc
     */
    public function getPickupAddressCityLabel(?int $storeId = null): ?string
    {
        return $this->getTrimmedConfig(self::XML_PATH_CARRIER . 'pickup_address_city_label', $storeId);
    }

    /**
     * @inheritdoc
     */
    public function getPickupAddressStreetId(?int $storeId = null): ?string
    {
        return $this->getTrimmedConfig(self::XML_PATH_CARRIER . 'pickup_address_street_id', $storeId);
    }

    /**
     * @inheritdoc
     */
    public function getPickupAddressStreetLabel(?int $storeId = null): ?string
    {
        return $this->getTrimmedConfig(self::XML_PATH_CARRIER . 'pickup_address_street_label', $storeId);
    }

    /**
     * @inheritdoc
     */
    public function getPickupAddressStreetNumber(?int $storeId = null): ?string
    {
        return $this->getTrimmedConfig(self::XML_PATH_CARRIER . 'pickup_address_street_number', $storeId);
    }

    /**
     * @inheritdoc
     */
    public function getPickupAddressPostalCode(?int $storeId = null): ?string
    {
        return $this->getTrimmedConfig(self::XML_PATH_CARRIER . 'pickup_address_postal_code', $storeId);
    }

    /**
     * Read optional string config and return trimmed value or null.
     *
     * @param string $path
     * @param int|null $storeId
     * @return string|null
     */
    private function getTrimmedConfig(string $path, ?int $storeId = null): ?string
    {
        $value = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }

    /**
     * Read a product dimension attribute code from config.
     *
     * @param string $field
     * @param int|null $storeId
     * @return string|null
     */
    private function getDimensionAttributeCode(string $field, ?int $storeId = null): ?string
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_CARRIER . $field,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }

    /**
     * @inheritdoc
     */
    public function getCodPaymentMethods(?int $storeId = null): array
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_CARRIER . 'cod_payment_methods',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        if ($value === null || $value === '') {
            return [];
        }
        return explode(',', (string) $value);
    }

    /**
     * @inheritdoc
     */
    public function isShowCodFee(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_CARRIER . 'show_cod_fee',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * @inheritdoc
     */
    public function isDeclaredValueEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_CARRIER . 'service_declared_value',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * @inheritdoc
     */
    public function isAdditionalServiceEnabled(string $serviceCode, ?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_CARRIER . 'service_' . $serviceCode,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * @inheritdoc
     */
    public function getReviewAndTest(string $deliveryType, ?int $storeId = null): string
    {
        $this->validateDeliveryType($deliveryType);
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_CARRIER . $deliveryType . '_review_and_test',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        return in_array($value, ['review', 'test'], true) ? $value : 'no';
    }

    /**
     * @param string $deliveryType
     * @return void
     */
    private function validateDeliveryType(string $deliveryType): void
    {
        if (!in_array($deliveryType, self::$deliveryTypes, true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid delivery type: %s. Allowed: %s', $deliveryType, implode(', ', self::$deliveryTypes))
            );
        }
    }

    /**
     * @inheritdoc
     */
    public function getFragileMode(?int $storeId = null): string
    {
        $value = (string) $this->scopeConfig->getValue(
            self::XML_PATH_CARRIER . 'fragile_mode',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return in_array($value, [self::FRAGILE_ALWAYS, self::FRAGILE_PER_PACKAGE], true)
            ? $value
            : self::FRAGILE_NEVER;
    }

    /**
     * @inheritdoc
     */
    public function getReturnReceiptMode(?int $storeId = null): string
    {
        $value = (string) $this->scopeConfig->getValue(
            self::XML_PATH_RETURN_RECEIPT_MODE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return in_array($value, [self::RECEIPT_ELECTRONIC, self::RECEIPT_PAPER, self::RECEIPT_BOTH], true)
            ? $value
            : self::RECEIPT_NONE;
    }

    /**
     * @inheritdoc
     */
    public function getReviewMode(string $deliveryType, ?int $storeId = null): string
    {
        // A locker has nobody to show the parcel to.
        if ($deliveryType === self::DELIVERY_TYPE_APS) {
            return self::REVIEW_NONE;
        }

        switch ($this->getReviewAndTest($deliveryType, $storeId)) {
            case 'review':
                return self::REVIEW_VIEW;
            case 'test':
                return self::REVIEW_VIEW_TEST;
            default:
                return self::REVIEW_NONE;
        }
    }

    /**
     * @inheritdoc
     */
    public function isDeclaredValueAlways(?int $storeId = null): bool
    {
        return $this->isDeclaredValueEnabled($storeId);
    }

    /**
     * @inheritdoc
     */
    public function isReturnAtMyExpense(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_RETURN_AT_MY_EXPENSE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * @inheritdoc
     */
    public function getReturnOfficeId(?int $storeId = null): ?int
    {
        if ($this->getReturnTo($storeId) !== self::RETURN_TO_OFFICE) {
            return null;
        }

        // An office chosen with none given falls back to the account, as in
        // WooCommerce, rather than sending an empty destination.
        return $this->getPositiveInt('return_office_id', $storeId);
    }

    /**
     * @inheritdoc
     */
    public function getReturnTo(?int $storeId = null): string
    {
        return $this->getReturnMode(self::XML_PATH_RETURN_TO, $storeId);
    }

    /**
     * @inheritdoc
     */
    public function getReturnAddressId(?int $storeId = null): ?int
    {
        if ($this->getReturnTo($storeId) !== self::RETURN_TO_ADDRESS) {
            return null;
        }

        return $this->getPositiveInt('return_address_id', $storeId);
    }

    /**
     * @inheritdoc
     */
    public function getReturnDocumentsTo(?int $storeId = null): string
    {
        if (!self::RETURN_DOCUMENTS_CHOICE) {
            return self::RETURN_TO_PROFILE;
        }

        return $this->getReturnMode(self::XML_PATH_RETURN_DOCUMENTS_TO, $storeId);
    }

    /**
     * @inheritdoc
     */
    public function getReturnDocumentsOfficeId(?int $storeId = null): ?int
    {
        if ($this->getReturnDocumentsTo($storeId) !== self::RETURN_TO_OFFICE) {
            return null;
        }

        return $this->getPositiveInt('return_documents_office_id', $storeId);
    }

    /**
     * @inheritdoc
     */
    public function getReturnDocumentsAddressId(?int $storeId = null): ?int
    {
        if ($this->getReturnDocumentsTo($storeId) !== self::RETURN_TO_ADDRESS) {
            return null;
        }

        return $this->getPositiveInt('return_documents_address_id', $storeId);
    }

    /**
     * @inheritdoc
     */
    public function isNearestOfficeEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_NEAREST_OFFICE, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * @inheritdoc
     */
    public function isLocationMapEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_LOCATION_MAP, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * A return destination mode stored at a path: one of RETURN_TO_MODES,
     * RETURN_TO_PROFILE for anything else.
     */
    private function getReturnMode(string $path, ?int $storeId): string
    {
        $value = trim((string) $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId));

        return in_array($value, self::RETURN_TO_MODES, true) ? $value : self::RETURN_TO_PROFILE;
    }

    /**
     * @inheritdoc
     */
    public function getPickupLockerId(?int $storeId = null): ?int
    {
        return $this->getPositiveInt('pickup_locker_id', $storeId);
    }

    /**
     * @inheritdoc
     */
    public function getPickupFallbackOfficeId(?int $storeId = null): ?int
    {
        return $this->getPositiveInt('pickup_fallback_office_id', $storeId);
    }

    /**
     * @inheritdoc
     */
    public function getPickupSavedAddressId(?int $storeId = null): ?int
    {
        return $this->getPositiveInt('pickup_saved_address_id', $storeId);
    }

    /**
     * @inheritdoc
     */
    public function isShowWorkingHours(string $deliveryType, ?int $storeId = null): bool
    {
        if ($deliveryType !== self::DELIVERY_TYPE_OFFICE && $deliveryType !== self::DELIVERY_TYPE_APS) {
            return false;
        }

        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_CARRIER . $deliveryType . '_show_working_hours',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * @inheritdoc
     */
    public function getFallbackPrice(string $deliveryType, ?int $storeId = null): ?float
    {
        $this->validateDeliveryType($deliveryType);
        $value = trim((string) $this->scopeConfig->getValue(
            self::XML_PATH_CARRIER . $deliveryType . '_fallback_price',
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));

        if ($value === '' || !is_numeric(str_replace(',', '.', $value))) {
            return null;
        }

        $price = (float) str_replace(',', '.', $value);

        return is_finite($price) && $price >= 0.0 ? $price : null;
    }

    /**
     * @inheritdoc
     */
    public function isAutoCreateEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_AUTO_CREATE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * A config value as a positive integer, or null.
     */
    private function getPositiveInt(string $field, ?int $storeId = null): ?int
    {
        $value = trim((string) $this->scopeConfig->getValue(
            self::XML_PATH_CARRIER . $field,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));

        if (!ctype_digit($value) || (int) $value <= 0) {
            return null;
        }

        return (int) $value;
    }
}
