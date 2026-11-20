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

    /** Hand shipments in at a parcel locker; what does not fit goes to a fallback office. */
    public const PICKUP_TYPE_LOCKER = 'locker';

    /** The courier collects from an address saved in the Pigeon Express account. */
    public const PICKUP_TYPE_SAVED_ADDRESS = 'saved_address';

    /** Fragile packages: none, all, or as ticked on each package of the shipment form. */
    public const FRAGILE_NEVER = 'never';
    public const FRAGILE_ALWAYS = 'always';
    public const FRAGILE_PER_PACKAGE = 'per_package';

    /** Return receipt: one choice of four, each receipt still sent as its own service. */
    public const RECEIPT_NONE = 'none';
    public const RECEIPT_ELECTRONIC = 'electronic';
    public const RECEIPT_PAPER = 'paper';
    public const RECEIPT_BOTH = 'both';

    /** Inspection before payment, as the shipment form names it. */
    public const REVIEW_NONE = 'none';
    public const REVIEW_VIEW = 'view';
    public const REVIEW_VIEW_TEST = 'view_test';

    /**
     * Where undelivered shipments (and return documents) go: as the Pigeon
     * Express account says, a chosen office, or an address saved in the
     * account. Only the id of the mode in force is ever sent: Pigeon Express
     * refuses an office and an address together.
     */
    public const RETURN_TO_PROFILE = 'profile';
    public const RETURN_TO_OFFICE = 'office';
    public const RETURN_TO_ADDRESS = 'address';

    /** Every return destination mode, in the order the settings offer them. */
    public const RETURN_TO_MODES = [self::RETURN_TO_PROFILE, self::RETURN_TO_OFFICE, self::RETURN_TO_ADDRESS];

    /**
     * Whether the shop can choose where return documents go. Off: the three
     * return documents settings are not shown, getReturnDocumentsTo() is
     * RETURN_TO_PROFILE and neither return_documents_office_id nor
     * return_documents_address_id is ever sent. The test environment refuses
     * both fields (422) with each of the three services that bring documents
     * back (service_return_documents, paper_return_receipt,
     * ID_verification_and_document_signature), on office and address delivery
     * alike (WooCommerce, last checked 2026-11-19). Turn on once Pigeon
     * Express confirms how the fields work; the saved values are kept.
     */
    public const RETURN_DOCUMENTS_CHOICE = false;

    /** Pigeon Express bills the merchant for the shipping. */
    public const WHO_PAYS_SENDER = 'sender';

    /** The courier collects the shipping charge from the recipient on delivery. */
    public const WHO_PAYS_RECEIVER = 'receiver';

    /** Product values, with the default weight and size added for every item that has none. */
    public const PARCEL_DEFAULTS_PER_ITEM = 'per_item';

    /** Product values, with one default weight and size for all the items that have none. */
    public const PARCEL_DEFAULTS_PER_SHIPMENT = 'per_shipment';

    /** Product values ignored: every shipment is one default parcel. */
    public const PARCEL_DEFAULTS_FIXED = 'fixed';

    /**
     * Check if carrier is enabled.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isActive(?int $storeId = null): bool;

    /** Config paths of the key pair kept for each environment (default scope only). */
    public const XML_PATH_API_KEY_TEST = 'carriers/pigeonexpress/api_key_test';
    public const XML_PATH_API_SECRET_TEST = 'carriers/pigeonexpress/api_secret_test';
    public const XML_PATH_API_KEY_LIVE = 'carriers/pigeonexpress/api_key_live';
    public const XML_PATH_API_SECRET_LIVE = 'carriers/pigeonexpress/api_secret_live';

    /** The single pair used before keys were kept per environment. Read as a fallback only. */
    public const XML_PATH_API_KEY_LEGACY = 'carriers/pigeonexpress/api_key';
    public const XML_PATH_API_SECRET_LEGACY = 'carriers/pigeonexpress/api_secret';

    public const XML_PATH_TEST_MODE = 'carriers/pigeonexpress/test_mode';

    /** Yes/No: the label is printed without the contents line. */
    public const XML_PATH_HIDE_CONTENT_ON_LABEL = 'carriers/pigeonexpress/hide_content_on_label';

    /** One select for both return receipts; replaces service_return_receipt and service_return_at_my_expense. */
    public const XML_PATH_RETURN_RECEIPT_MODE = 'carriers/pigeonexpress/return_receipt_mode';

    /** Yes/No: the top level return_at_my_expense flag. Not the paper receipt of old. */
    public const XML_PATH_RETURN_AT_MY_EXPENSE = 'carriers/pigeonexpress/return_at_my_expense';

    /** Yes/No: create the shipment in the background when the order reaches Processing. */
    public const XML_PATH_AUTO_CREATE = 'carriers/pigeonexpress/auto_create';

    /** Yes/No: ask the shopper's location at checkout and list the nearest offices or lockers first. */
    public const XML_PATH_NEAREST_OFFICE = 'carriers/pigeonexpress/nearest_office';

    /** Yes/No: offer a map of the offices and lockers at checkout (OpenStreetMap tiles). */
    public const XML_PATH_LOCATION_MAP = 'carriers/pigeonexpress/location_map';

    /** Where undelivered shipments return: one of RETURN_TO_MODES. */
    public const XML_PATH_RETURN_TO = 'carriers/pigeonexpress/return_to';

    /** The chosen return office (Pigeon Express office id), used with RETURN_TO_OFFICE. */
    public const XML_PATH_RETURN_OFFICE_ID = 'carriers/pigeonexpress/return_office_id';

    /** The chosen return address (saved address id in the account), used with RETURN_TO_ADDRESS. */
    public const XML_PATH_RETURN_ADDRESS_ID = 'carriers/pigeonexpress/return_address_id';

    /** Where return documents go: one of RETURN_TO_MODES. Read only while RETURN_DOCUMENTS_CHOICE. */
    public const XML_PATH_RETURN_DOCUMENTS_TO = 'carriers/pigeonexpress/return_documents_to';

    /** The office return documents go to, with RETURN_TO_OFFICE. Read only while RETURN_DOCUMENTS_CHOICE. */
    public const XML_PATH_RETURN_DOCUMENTS_OFFICE_ID = 'carriers/pigeonexpress/return_documents_office_id';

    /** The saved address return documents go to, with RETURN_TO_ADDRESS. Read only while RETURN_DOCUMENTS_CHOICE. */
    public const XML_PATH_RETURN_DOCUMENTS_ADDRESS_ID = 'carriers/pigeonexpress/return_documents_address_id';

    /**
     * API key of the active environment (test or live), decrypted.
     *
     * The pair of the environment chosen with Test Mode. The single key of
     * earlier versions is used only while neither environment has a key of its
     * own, so a store upgraded without running the data patch keeps working.
     *
     * @param int|null $storeId Ignored: the account is global
     * @return string Empty when not configured
     */
    public function getApiKey(?int $storeId = null): string;

    /**
     * API secret of the active environment, decrypted. If not set, same as the API key.
     *
     * @param int|null $storeId Ignored: the account is global
     * @return string
     */
    public function getApiSecret(?int $storeId = null): string;

    /**
     * Whether new shipments ask Pigeon Express to leave the contents line off the label.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isHideContentOnLabel(?int $storeId = null): bool;

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
     * Whether the customer is charged the COD fee on top of shipping for a
     * delivery type. Always with dynamic pricing; with a flat rate only when
     * the merchant says so, the flat rate often being meant as all inclusive.
     *
     * @param string $deliveryType
     * @param int|null $storeId
     * @return bool
     */
    public function isCodFeeCharged(string $deliveryType, ?int $storeId = null): bool;

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
     * How the default weight and size are applied: one of the PARCEL_DEFAULTS_*
     * constants. Adding a full default box for every unit makes a two bottle
     * order a 2 kg, 40 x 40 x 40 cm parcel, which is rarely what was meant.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getParcelDefaultsMode(?int $storeId = null): string;

    /**
     * Pickup location type: 'office', 'address', 'locker' or 'saved_address'.
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
     * Whether the COD fee is shown next to COD payment methods at checkout.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isShowCodFee(?int $storeId = null): bool;

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

    /**
     * Fragile mark on packages: never, always, or per package as ticked on the
     * shipment form.
     *
     * @param int|null $storeId
     * @return string One of the FRAGILE_* constants
     */
    public function getFragileMode(?int $storeId = null): string;

    /**
     * Return receipt for new shipments: none, electronic, paper or both.
     *
     * @param int|null $storeId
     * @return string One of the RECEIPT_* constants
     */
    public function getReturnReceiptMode(?int $storeId = null): string;

    /**
     * Inspection before payment for a delivery type, as the shipment form names
     * it: none, view or view_test. Read from the per method setting.
     *
     * @param string $deliveryType One of address, office, aps
     * @param int|null $storeId
     * @return string One of the REVIEW_* constants
     */
    public function getReviewMode(string $deliveryType, ?int $storeId = null): string;

    /**
     * Whether every new shipment carries the declared value service.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isDeclaredValueAlways(?int $storeId = null): bool;

    /**
     * Whether an undeliverable shipment comes back at the sender's cost
     * (top level return_at_my_expense).
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isReturnAtMyExpense(?int $storeId = null): bool;

    /**
     * The office refused or uncollected shipments go back to, or null when the
     * Pigeon Express account decides. A chosen office with no office given
     * counts as the account setting.
     *
     * @param int|null $storeId
     * @return int|null
     */
    public function getReturnOfficeId(?int $storeId = null): ?int;

    /**
     * Where refused or uncollected shipments go back to: one of
     * RETURN_TO_MODES, RETURN_TO_PROFILE for anything unknown.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getReturnTo(?int $storeId = null): string;

    /**
     * The saved address (its id in the Pigeon Express account) refused or
     * uncollected shipments go back to, or null unless return_to is
     * RETURN_TO_ADDRESS and an address is chosen. Whether the account still
     * has it is for the caller to check (SavedAddresses::find()).
     *
     * @param int|null $storeId
     * @return int|null
     */
    public function getReturnAddressId(?int $storeId = null): ?int;

    /**
     * Where return documents go: one of RETURN_TO_MODES, always
     * RETURN_TO_PROFILE while RETURN_DOCUMENTS_CHOICE is off.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getReturnDocumentsTo(?int $storeId = null): string;

    /**
     * The office return documents go to, or null unless
     * getReturnDocumentsTo() is RETURN_TO_OFFICE and an office is chosen.
     *
     * @param int|null $storeId
     * @return int|null
     */
    public function getReturnDocumentsOfficeId(?int $storeId = null): ?int;

    /**
     * The saved address return documents go to, or null unless
     * getReturnDocumentsTo() is RETURN_TO_ADDRESS and an address is chosen.
     *
     * @param int|null $storeId
     * @return int|null
     */
    public function getReturnDocumentsAddressId(?int $storeId = null): ?int;

    /**
     * Whether checkout asks the shopper's location when office or locker
     * delivery is chosen and lists the nearest locations first. Off by
     * default: the browser asks the shopper's permission.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isNearestOfficeEnabled(?int $storeId = null): bool;

    /**
     * Whether checkout offers a map of the offices and lockers to choose
     * from. Off by default: it loads map tiles from openstreetmap.org.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isLocationMapEnabled(?int $storeId = null): bool;

    /**
     * The parcel locker shipments are handed in at (pickup type locker).
     *
     * @param int|null $storeId
     * @return int|null
     */
    public function getPickupLockerId(?int $storeId = null): ?int;

    /**
     * The office a shipment goes to instead when it does not fit the locker.
     *
     * @param int|null $storeId
     * @return int|null
     */
    public function getPickupFallbackOfficeId(?int $storeId = null): ?int;

    /**
     * Id of the address saved in the Pigeon Express account (pickup type saved_address).
     *
     * @param int|null $storeId
     * @return int|null
     */
    public function getPickupSavedAddressId(?int $storeId = null): ?int;

    /**
     * Whether checkout shows the working hours of the chosen office or locker.
     *
     * @param string $deliveryType One of office, aps
     * @param int|null $storeId
     * @return bool
     */
    public function isShowWorkingHours(string $deliveryType, ?int $storeId = null): bool;

    /**
     * Price charged when Pigeon Express cannot price the delivery, or null to
     * hide the method then. Zero is a real price.
     *
     * @param string $deliveryType One of address, office, aps
     * @param int|null $storeId
     * @return float|null
     */
    public function getFallbackPrice(string $deliveryType, ?int $storeId = null): ?float;

    /**
     * Whether shipments are created in the background when the order reaches Processing.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isAutoCreateEnabled(?int $storeId = null): bool;
}
