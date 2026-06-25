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
     * Returns 0.0 when not configured — methods will be hidden for products without weight.
     *
     * @param int|null $storeId
     * @return float
     */
    public function getDefaultWeight(?int $storeId = null): float;

    /**
     * Get default length (cm) used when a product has no length value.
     * Returns 0.0 when not configured — methods will be hidden for products without dimensions.
     *
     * @param int|null $storeId
     * @return float
     */
    public function getDefaultLength(?int $storeId = null): float;

    /**
     * Get default width (cm) used when a product has no width value.
     * Returns 0.0 when not configured — methods will be hidden for products without dimensions.
     *
     * @param int|null $storeId
     * @return float
     */
    public function getDefaultWidth(?int $storeId = null): float;

    /**
     * Get default height (cm) used when a product has no height value.
     * Returns 0.0 when not configured — methods will be hidden for products without dimensions.
     *
     * @param int|null $storeId
     * @return float
     */
    public function getDefaultHeight(?int $storeId = null): float;

    /**
     * Place of shipment (pickup): 'office' or 'address'.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getPickupType(?int $storeId = null): string;

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
     * Get list of payment method codes that trigger the COD fee (2.5% of subtotal).
     *
     * @param int|null $storeId
     * @return string[]
     */
    public function getCodPaymentMethods(?int $storeId = null): array;

    /**
     * Get declared value amount for the declared_value service code.
     * Returns null if not configured (service disabled).
     *
     * @param int|null $storeId
     * @return float|null
     */
    public function getDeclaredValue(?int $storeId = null): ?float;

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
