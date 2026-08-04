<?php
/**
 * Pigeon Express configuration interface.
 * Extensible for rate quote, waybills, tracking in future stages.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Api;

interface ConfigInterface
{
    public const CARRIER_CODE = 'pigeonexpress';
    public const DELIVERY_TYPE_ADDRESS = 'address';
    public const DELIVERY_TYPE_OFFICE = 'office';
    public const DELIVERY_TYPE_APS = 'aps';
    public const PICKUP_TYPE_OFFICE = 'office';
    public const PICKUP_TYPE_ADDRESS = 'address';

    /** Pigeon Express bills the merchant for the shipping. */
    public const WHO_PAYS_SENDER = 'sender';

    /** The courier collects the shipping charge from the recipient on delivery. */
    public const WHO_PAYS_RECEIVER = 'receiver';

    /**
     * Check if carrier is enabled.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isActive(?int $storeId = null): bool;

    /**
     * Get API key (decrypted).
     *
     * @param int|null $storeId
     * @return string
     */
    public function getApiKey(?int $storeId = null): string;

    /**
     * Get API secret (decrypted). If not set, same as API key.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getApiSecret(?int $storeId = null): string;

    /**
     * Is test mode enabled.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isTestMode(?int $storeId = null): bool;

    /**
     * Is logging enabled.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isLoggingEnabled(?int $storeId = null): bool;

    /**
     * Check if a delivery type is enabled.
     *
     * @param string $deliveryType One of address, office, aps
     * @param int|null $storeId
     * @return bool
     */
    public function isDeliveryTypeEnabled(string $deliveryType, ?int $storeId = null): bool;

    /**
     * Get title for a delivery type (checkout label).
     *
     * @param string $deliveryType
     * @param int|null $storeId
     * @return string
     */
    public function getDeliveryTypeTitle(string $deliveryType, ?int $storeId = null): string;

    /**
     * Get pricing mode for a delivery type: 'dynamic' or 'fixed'.
     *
     * @param string $deliveryType
     * @param int|null $storeId
     * @return string
     */
    public function getDeliveryTypePricingMode(string $deliveryType, ?int $storeId = null): string;

    /**
     * Get flat rate price for a delivery type (used when pricing mode is fixed).
     *
     * @param string $deliveryType
     * @param int|null $storeId
     * @return float
     */
    public function getDeliveryTypeFlatRate(string $deliveryType, ?int $storeId = null): float;

    /**
     * What the waybill says the parcel contains: product names or one fixed wording.
     *
     * @param int|null $storeId
     * @return string One of PigeonExpress\Shipping\Model\Config\Source\LabelContents
     */
    public function getLabelContentsMode(?int $storeId = null): string;

    /**
     * The wording printed in place of product names.
     *
     * Never empty. An unset field falls back to a generic word rather than to
     * the product name, because falling back to the name would quietly undo the
     * only thing this setting exists to do.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getLabelContentsText(?int $storeId = null): string;

    /**
     * Whether a delivery type becomes free once the cart reaches a threshold.
     *
     * Independent of Magento's own free shipping cart price rules, which apply
     * whatever this returns.
     *
     * @param string $deliveryType
     * @param int|null $storeId
     * @return bool
     */
    public function isFreeShippingThresholdEnabled(string $deliveryType, ?int $storeId = null): bool;

    /**
     * Order subtotal at or above which a delivery type costs nothing.
     *
     * Zero means no threshold, which is also what an unconfigured or cleared
     * field reads as, so callers do not have to tell the two apart.
     *
     * @param string $deliveryType
     * @param int|null $storeId
     * @return float
     */
    public function getFreeShippingThreshold(string $deliveryType, ?int $storeId = null): float;

    /**
     * Position of a delivery type among the carrier's options at checkout.
     *
     * @param string $deliveryType
     * @param int|null $storeId
     * @return int
     */
    public function getDeliveryTypeSortOrder(string $deliveryType, ?int $storeId = null): int;

    /**
     * Get list of enabled delivery type codes.
     *
     * @param int|null $storeId
     * @return string[]
     */
    public function getEnabledDeliveryTypes(?int $storeId = null): array;

    /**
     * Get autocomplete result limit for Office/APS search (e.g. 10–20).
     *
     * @param int|null $storeId
     * @return int
     */
    public function getLocationSearchLimit(?int $storeId = null): int;

    /**
     * Get product attribute code used for package weight (in kg).
     *
     * @param int|null $storeId
     * @return string|null
     */
    public function getWeightAttributeCode(?int $storeId = null): ?string;

    /**
     * Get product attribute code used for package length (in cm).
     *
     * @param int|null $storeId
     * @return string|null
     */
    public function getLengthAttributeCode(?int $storeId = null): ?string;

    /**
     * Get product attribute code used for package width (in cm).
     *
     * @param int|null $storeId
     * @return string|null
     */
    public function getWidthAttributeCode(?int $storeId = null): ?string;

    /**
     * Get product attribute code used for package height (in cm).
     *
     * @param int|null $storeId
     * @return string|null
     */
    public function getHeightAttributeCode(?int $storeId = null): ?string;

    /**
     * Get default weight (kg) used when a product has no weight value.
     * Returns 0.0 when not configured, methods will be hidden for products without weight.
     *
     * @param int|null $storeId
     * @return float
     */
    public function getDefaultWeight(?int $storeId = null): float;

    /**
     * Get default length (cm) used when a product has no length value.
     * Returns 0.0 when not configured, methods will be hidden for products without dimensions.
     *
     * @param int|null $storeId
     * @return float
     */
    public function getDefaultLength(?int $storeId = null): float;

    /**
     * Get default width (cm) used when a product has no width value.
     * Returns 0.0 when not configured, methods will be hidden for products without dimensions.
     *
     * @param int|null $storeId
     * @return float
     */
    public function getDefaultWidth(?int $storeId = null): float;

    /**
     * Get default height (cm) used when a product has no height value.
     * Returns 0.0 when not configured, methods will be hidden for products without dimensions.
     *
     * @param int|null $storeId
     * @return float
     */
    public function getDefaultHeight(?int $storeId = null): float;

    /**
     * Pickup location type: 'office' or 'address'.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getPickupType(?int $storeId = null): string;

    /**
     * Who Pigeon Express bills for the shipping, sender or receiver.
     *
     * This decides who the carrier invoices. It does not change what Magento
     * charges the customer at checkout, and Pigeon Express quotes the same price
     * either way, so a store set to receiver has to decide separately what its
     * own shipping rate should be.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getWhoPays(?int $storeId = null): string;

    /**
     * The locker compartment a parcel has to fit, in cm.
     *
     * Everything else about a parcel is the carrier's to judge, and it refuses
     * with a reason. This one it never checks: an oversized parcel is booked
     * happily and only the recipient discovers it will not go in. Configurable
     * because lockers get replaced with different ones.
     *
     * @param int|null $storeId
     * @return array{length:float, width:float, height:float}
     */
    public function getLockerCompartment(?int $storeId = null): array;

    /**
     * Which logo to show beside the methods at checkout, empty for none.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getCheckoutLogo(?int $storeId = null): string;

    /**
     * Whether checkout replaces Magento's address fields with the carrier's own.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isOverrideAddressFields(?int $storeId = null): bool;

    /**
     * PE API office ID for pickup when pickup type is Office.
     *
     * @param int|null $storeId
     * @return int|null
     */
    public function getPickupOfficeId(?int $storeId = null): ?int;

    /**
     * Pickup address: PE city ID (when pickup type is Address).
     *
     * @param int|null $storeId
     * @return string|null
     */
    public function getPickupAddressCityId(?int $storeId = null): ?string;

    /**
     * Pickup address: city display label.
     *
     * @param int|null $storeId
     * @return string|null
     */
    public function getPickupAddressCityLabel(?int $storeId = null): ?string;

    /**
     * Pickup address: PE street ID (optional).
     *
     * @param int|null $storeId
     * @return string|null
     */
    public function getPickupAddressStreetId(?int $storeId = null): ?string;

    /**
     * Pickup address: street display label (or street_name for API if street_id empty).
     *
     * @param int|null $storeId
     * @return string|null
     */
    public function getPickupAddressStreetLabel(?int $storeId = null): ?string;

    /**
     * Pickup address: street number.
     *
     * @param int|null $storeId
     * @return string|null
     */
    public function getPickupAddressStreetNumber(?int $storeId = null): ?string;

    /**
     * Pickup address: postal code.
     *
     * @param int|null $storeId
     * @return string|null
     */
    public function getPickupAddressPostalCode(?int $storeId = null): ?string;

    /**
     * Get list of payment method codes that trigger the COD fee.
     *
     * The fee amount itself comes from the Pigeon Express API, never from config.
     *
     * @param int|null $storeId
     * @return string[]
     */
    public function getCodPaymentMethods(?int $storeId = null): array;

    /**
     * Whether the declared value (insurance) service is switched on.
     *
     * The amount itself is not configured. When enabled, the goods value of the
     * cart or order is sent as `declared_value`, since insuring a parcel for a
     * fixed figure unrelated to its contents is never what a merchant wants.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isDeclaredValueEnabled(?int $storeId = null): bool;

    /**
     * Check if a global additional service is enabled.
     *
     * Supported service codes:
     *   - pos_payment          (COD Card Fee)
     *   - special_packaging    (Requires Special Packaging)
     *   - return_at_my_expense (Paper Return Receipt)
     *   - requires_signature   (ID Verification)
     *   - return_receipt
     *   - return_documents
     *
     * @param string   $serviceCode
     * @param int|null $storeId
     * @return bool
     */
    public function isAdditionalServiceEnabled(string $serviceCode, ?int $storeId = null): bool;

    /**
     * Get "Review and test" setting for a delivery type.
     *
     * Returns 'test' if enabled, 'no' otherwise.
     * Not supported for APS (locker) delivery.
     *
     * @param string   $deliveryType One of address, office, aps
     * @param int|null $storeId
     * @return string
     */
    public function getReviewAndTest(string $deliveryType, ?int $storeId = null): string;
}
