<?php
/**
 * The shipment form the admin fills in before a waybill is created.
 *
 * It exists because Pigeon Express identifies a recipient by phone number. When
 * the address block it receives cannot be resolved, it quietly substitutes an
 * address it already holds for that number, so the parcel goes somewhere else
 * while the label looks perfectly correct. A customer who types a street name
 * loosely at checkout is enough to trigger it.
 *
 * So the admin gets ordinary form fields, filled in from the order, with the
 * ones that will not do marked. Nothing here mentions ids: the city and street
 * pickers resolve those, and the admin only ever sees names.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Shipment;

use Magento\Sales\Api\Data\OrderAddressInterface;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\Api\AddressResolver;
use PigeonExpress\Shipping\Model\Phone\BulgarianPhone;
use PigeonExpress\Shipping\Model\Rate\LocationToApiIdResolver;
use PigeonExpress\Shipping\Model\Service\ServiceCodesBuilder;
use PigeonExpress\Shipping\Model\ShipmentSender;

class AddressForm
{
    /** The shipment cannot be created until this is fixed. */
    public const SEVERITY_ERROR = 'error';

    /** The shipment can be created, but is likely to be delivered incorrectly. */
    public const SEVERITY_WARNING = 'warning';

    /**
     * Longest courier note Pigeon Express accepts, in characters. The checkout
     * field and the order form are limited to the same, so nothing is cut off
     * silently on the way.
     */
    public const NOTE_MAX_LENGTH = 500;

    /**
     * Every field the form posts. Anything outside this list is ignored, so a
     * stray request parameter can never reach the API payload.
     */
    public const FIELDS = [
        'delivery_type',
        'who_pays',
        'location',
        'location_id',
        'contact_name',
        'contact_phone',
        'city',
        'city_id',
        'postal_code',
        'street',
        'street_id',
        'street_number',
        'building_number',
        'entrance',
        'floor',
        'apartment',
        'note',
        'cod_amount',
        'weight',
        'length',
        'width',
        'height',
        'parcels',
    ];

    /**
     * The parcel figures the admin may correct. Left empty they are worked out
     * from the order, exactly as they would be without the form.
     */
    public const PARCEL_FIELDS = ['weight', 'length', 'width', 'height', 'parcels'];

    /**
     * Inspection before payment, one per shipment: none, look only, or look
     * and try. Pigeon Express offers it only where somebody hands the parcel
     * over, so never at a locker.
     */
    public const REVIEW_NONE = 'none';
    public const REVIEW_VIEW = 'view';
    public const REVIEW_VIEW_TEST = 'view_test';
    public const REVIEW_VALUES = [self::REVIEW_NONE, self::REVIEW_VIEW, self::REVIEW_VIEW_TEST];

    /** Return receipt, one per shipment. Electronic and paper are charged separately. */
    public const RECEIPT_NONE = 'none';
    public const RECEIPT_ELECTRONIC = 'electronic';
    public const RECEIPT_PAPER = 'paper';
    public const RECEIPT_BOTH = 'both';
    public const RECEIPT_VALUES = [
        self::RECEIPT_NONE,
        self::RECEIPT_ELECTRONIC,
        self::RECEIPT_PAPER,
        self::RECEIPT_BOTH,
    ];

    /** Which packages may carry the fragile mark, as the store setting says. */
    public const FRAGILE_NEVER = 'never';
    public const FRAGILE_ALWAYS = 'always';
    public const FRAGILE_PER_PACKAGE = 'per_package';

    /** Longest description one package row may carry. */
    public const PACKAGE_DESCRIPTION_MAX_LENGTH = 100;

    /**
     * Why the form suggests the side it does, when the admin has not picked one.
     * The order panel names the rule, so a merchant who expected the other side
     * knows which setting, or which fact about the order, decided it.
     */
    public const WHO_PAYS_RULE_FREE_SHIPPING = 'free_shipping';
    public const WHO_PAYS_RULE_FIXED_PRICE = 'fixed_price';
    public const WHO_PAYS_RULE_COD = 'cod';
    public const WHO_PAYS_RULE_PREPAID = 'prepaid';

    /** Fields that only make sense when the parcel goes to a street address. */
    private const ADDRESS_ONLY_FIELDS = [
        'city',
        'city_id',
        'postal_code',
        'street',
        'street_id',
        'street_number',
        'building_number',
        'entrance',
        'floor',
        'apartment',
    ];

    /** Fields that only make sense when the parcel goes to a pickup point. */
    private const LOCATION_ONLY_FIELDS = [
        'location',
        'location_id',
    ];

    /** @var AddressResolver */
    private $resolver;

    /** @var LocationToApiIdResolver */
    private $locations;

    /** @var ConfigInterface */
    private $config;

    /** @var \PigeonExpress\Shipping\Model\OrderAddressLocationPersistor|null */
    private $locationPersistor;

    public function __construct(
        AddressResolver $resolver,
        LocationToApiIdResolver $locations,
        ConfigInterface $config,
        ?\PigeonExpress\Shipping\Model\OrderAddressLocationPersistor $locationPersistor = null
    ) {
        $this->resolver = $resolver;
        $this->locations = $locations;
        $this->config = $config;
        // Configured in di.xml: an optional argument is otherwise left null.
        $this->locationPersistor = $locationPersistor;
    }

    /**
     * Who the carrier bills for this shipment.
     *
     * Falls back to the store setting rather than to a hardcoded side, so an
     * order sent without the form still bills whoever the merchant configured.
     *
     * A delivery the customer was given free, or an order already paid online,
     * is the exception: billing the recipient there would have the courier
     * collect at the door for something the customer has already settled with
     * the shop. It changes the fallback only. An admin
     * who picks a side on the shipment form still gets the side they picked,
     * because a screen that says one thing while the carrier is told another is
     * worse than the charge it was trying to prevent.
     *
     * @param array<string, string> $fields
     */
    public function whoPays(
        array $fields,
        ?int $storeId = null,
        bool $freeShipping = false,
        bool $fixedPrice = false
    ): string {
        $value = (string) ($fields['who_pays'] ?? '');

        if ($value === ConfigInterface::WHO_PAYS_SENDER || $value === ConfigInterface::WHO_PAYS_RECEIVER) {
            return $value;
        }

        // A fixed price was what the customer agreed to pay the shop. Billing
        // the recipient as well would have the courier charge the carrier's own
        // price at the door on top of it.
        if ($freeShipping || $fixedPrice) {
            return ConfigInterface::WHO_PAYS_SENDER;
        }

        return $this->config->getWhoPays($storeId);
    }

    /**
     * Which rule sets the suggested side for an order. Free shipping first,
     * then a fixed price, then cash on delivery or not.
     */
    public function whoPaysRule(bool $isCod, bool $freeShipping, bool $fixedPrice = false): string
    {
        if ($freeShipping) {
            return self::WHO_PAYS_RULE_FREE_SHIPPING;
        }

        if ($fixedPrice) {
            return self::WHO_PAYS_RULE_FIXED_PRICE;
        }

        return $isCod ? self::WHO_PAYS_RULE_COD : self::WHO_PAYS_RULE_PREPAID;
    }

    /**
     * The side a rule suggests: the store setting for a cash on delivery
     * order, the sender for everything else.
     */
    public function defaultWhoPays(string $rule, ?int $storeId = null): string
    {
        return $rule === self::WHO_PAYS_RULE_COD
            ? $this->config->getWhoPays($storeId)
            : ConfigInterface::WHO_PAYS_SENDER;
    }

    /**
     * The sentence under the Who pays control that names the rule behind the
     * suggestion, worded as in the WooCommerce plugin.
     */
    public function whoPaysReason(string $rule, ?int $storeId = null): string
    {
        switch ($rule) {
            case self::WHO_PAYS_RULE_FREE_SHIPPING:
                return (string) __(
                    'Pigeon Express still invoices somebody for it. Leave this on Sender unless you mean '
                    . 'the courier to collect from the recipient.'
                );
            case self::WHO_PAYS_RULE_FIXED_PRICE:
                return (string) __('Default: Sender, because the customer was charged your fixed delivery price.');
            case self::WHO_PAYS_RULE_COD:
                return $this->defaultWhoPays($rule, $storeId) === ConfigInterface::WHO_PAYS_RECEIVER
                    ? (string) __('Default: Receiver (the setting for cash on delivery orders).')
                    : (string) __('Default: Sender (the setting for cash on delivery orders).');
            default:
                return (string) __(
                    'Default: Sender, because the order is not cash on delivery: the customer pays the shop '
                    . 'for delivery.'
                );
        }
    }

    /**
     * Whether a set of form values describes a delivery to a street address.
     *
     * @param array<string, string> $fields
     */
    public function isAddressDelivery(array $fields): bool
    {
        return $this->deliveryType($fields) === ConfigInterface::DELIVERY_TYPE_ADDRESS;
    }

    /**
     * The delivery type a set of form values asks for, always one of the three.
     *
     * @param array<string, string> $fields
     */
    public function deliveryType(array $fields): string
    {
        $type = (string) ($fields['delivery_type'] ?? '');

        return in_array($type, [
            ConfigInterface::DELIVERY_TYPE_ADDRESS,
            ConfigInterface::DELIVERY_TYPE_OFFICE,
            ConfigInterface::DELIVERY_TYPE_APS,
        ], true) ? $type : ConfigInterface::DELIVERY_TYPE_ADDRESS;
    }

    /**
     * An empty set of fields, so callers never have to check for missing keys.
     *
     * @return array<string, string>
     */
    public function blank(): array
    {
        return array_fill_keys(self::FIELDS, '');
    }

    /**
     * Fill the form from the order.
     *
     * This only takes the address apart. Matching it against the carrier is
     * validate()'s job, so an address arriving from the order and one typed by
     * the admin are held to exactly the same standard.
     *
     * @return array<string, string>
     */
    public function fromOrder(
        OrderAddressInterface $address,
        string $note = '',
        string $deliveryType = '',
        string $locationId = '',
        string $locationName = ''
    ): array {
        $fields = $this->blank();

        $fields['delivery_type'] = $this->deliveryType(['delivery_type' => $deliveryType]);
        $fields['location_id'] = $locationId;
        $fields['location'] = $locationName;

        $fields['contact_name'] = trim(($address->getFirstname() ?? '') . ' ' . ($address->getLastname() ?? ''));
        $phone = trim((string) $address->getTelephone());
        $fields['contact_phone'] = $phone !== '' ? ShipmentSender::normalizePhone($phone) : '';
        $fields['note'] = $note;

        $fields['city'] = trim((string) $address->getCity());
        $fields['postal_code'] = trim((string) $address->getPostcode());

        $street = $address->getStreet();
        $lines = is_array($street) ? $street : [(string) $street];
        $lines = array_values(array_filter(array_map('trim', $lines), static function ($line) {
            return $line !== '';
        }));

        // The block, floor and the rest are read from the whole address, but they
        // have to come off the street line first or the street name swallows them.
        // The type prefix stays on: validate() needs it to tell two streets of
        // the same name apart.
        $streetLine = $this->resolver->cutAtAddressComponents($lines[0] ?? '');
        [$fields['street'], $fields['street_number']] = $this->resolver->extractStreetNameAndNumber($streetLine);

        foreach ($this->resolver->extractAddressComponents(implode(', ', $lines)) as $key => $value) {
            $fields[$key] = $value;
        }

        // The city and street the customer picked from the carrier's lists at
        // checkout, when they did: no guessing a street by its name, which is
        // ambiguous where two streets share one (Sofia has two called ВИТОША).
        foreach ($this->checkoutIds($address) as $key => $id) {
            $id = trim((string) $id);
            if (ctype_digit($id) && (int) $id > 0) {
                $fields[$key] = $id;
            }
        }

        return $fields;
    }

    /**
     * The city and street ids stored with the order address at checkout.
     * Read from the address itself when it carries them, else from the table,
     * because an address loaded with its order skips the load plugins.
     *
     * @return array{city_id?: mixed, street_id?: mixed}
     */
    private function checkoutIds(OrderAddressInterface $address): array
    {
        $ext = $address->getExtensionAttributes();
        if ($ext !== null && method_exists($ext, 'getPigeonexpressStreetId')
            && ((string) $ext->getPigeonexpressStreetId() !== '' || (string) $ext->getPigeonexpressCityId() !== '')
        ) {
            return ['city_id' => $ext->getPigeonexpressCityId(), 'street_id' => $ext->getPigeonexpressStreetId()];
        }

        if ($this->locationPersistor === null || !$address->getEntityId()) {
            return [];
        }

        $row = $this->locationPersistor->getByAddressId((int) $address->getEntityId());

        return $row === null ? [] : ['city_id' => $row['city_id'] ?? '', 'street_id' => $row['street_id'] ?? ''];
    }

    /**
     * Take the submitted values, ignoring anything not part of the form.
     *
     * @param array<string, mixed> $submitted
     * @return array<string, string>
     */
    public function fromRequest(array $submitted): array
    {
        $fields = $this->blank();

        foreach (self::FIELDS as $field) {
            if (!isset($submitted[$field]) || is_array($submitted[$field])) {
                continue;
            }
            $fields[$field] = trim((string) $submitted[$field]);
        }

        // Ids only ever come from our own pickers, so anything else is discarded
        // rather than passed to the API and rejected there.
        foreach (['city_id', 'street_id', 'location_id'] as $idField) {
            if (!ctype_digit($fields[$idField]) || (int) $fields[$idField] <= 0) {
                $fields[$idField] = '';
            }
        }

        $fields['delivery_type'] = $this->deliveryType($fields);

        // The package rows and the services are not plain strings, so they are
        // taken one by one and only when they were sent. A key that is missing
        // means "as the settings say", which is what a form stored before
        // these existed must come back as.
        if (isset($submitted['packages']) && is_array($submitted['packages'])) {
            $fields['packages'] = $this->packageRowsFromRequest($submitted['packages']);
        }

        if (array_key_exists('declared_value', $submitted) && !is_array($submitted['declared_value'])) {
            $fields['declared_value'] = $this->flag($submitted['declared_value']);
        }

        if (array_key_exists('declared_value_amount', $submitted) && !is_array($submitted['declared_value_amount'])) {
            $fields['declared_value_amount'] = $submitted['declared_value_amount'] === null
                ? ''
                : trim((string) $submitted['declared_value_amount']);
        }

        foreach (['review' => self::REVIEW_VALUES, 'return_receipt' => self::RECEIPT_VALUES] as $key => $allowed) {
            if (isset($submitted[$key]) && is_string($submitted[$key]) && in_array($submitted[$key], $allowed, true)) {
                $fields[$key] = $submitted[$key];
            }
        }

        return $fields;
    }

    /**
     * Package rows as posted, or as stored by an earlier shipment, reduced to
     * the keys a row has. Values stay as typed; validate() checks them.
     *
     * @param array<mixed> $rows
     * @return array<int, array{weight:string, length:string, width:string, height:string, description:string, fragile:bool}>
     */
    private function packageRowsFromRequest(array $rows): array
    {
        $clean = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $item = [];
            foreach (['weight', 'length', 'width', 'height', 'description'] as $key) {
                $value = $row[$key] ?? '';
                $item[$key] = is_scalar($value) ? trim((string) $value) : '';
            }
            $item['fragile'] = $this->flag($row['fragile'] ?? false);

            $clean[] = $item;
        }

        return $clean;
    }

    /**
     * A checkbox or a stored flag as a boolean: "1", "on", "true", true and 1.
     *
     * @param mixed $value
     */
    private function flag($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return is_scalar($value) && in_array(strtolower(trim((string) $value)), ['1', 'on', 'true', 'yes'], true);
    }

    /**
     * Check the form and resolve whatever the admin left for us to resolve.
     *
     * Errors stop the shipment. Warnings do not, because a merchant who knows
     * the address is fine should not be prevented from shipping by a street the
     * carrier has never heard of.
     *
     * Besides the address it settles the package rows (`packages`) and the
     * per-shipment services (`declared_value`, `declared_value_amount`,
     * `review`, `return_receipt`), filling from the settings whatever was not
     * sent, so the result always carries every service key.
     *
     * @param array<string, mixed> $fields
     * @param bool $freeShipping The customer has nothing left to pay for this delivery:
     *                           it was free, or the order was paid online
     * @param bool $fixedPrice   The customer was charged the shop's fixed delivery price
     * @return array{fields: array<string, mixed>, issues: array<string, array{severity:string, message:string}>}
     */
    public function validate(array $fields, int $storeId, bool $freeShipping = false, bool $fixedPrice = false): array
    {
        $fields = array_merge($this->blank(), $fields);
        $fields['delivery_type'] = $this->deliveryType($fields);
        $fields['who_pays'] = $this->whoPays($fields, $storeId, $freeShipping, $fixedPrice);
        $fields['note'] = mb_substr(trim((string) $fields['note']), 0, self::NOTE_MAX_LENGTH);
        $issues = [];

        $this->checkCodAmount($fields, $issues);
        $this->checkPackages($fields, $issues, $storeId);
        $this->checkServices($fields, $issues, $storeId);

        if ($fields['contact_name'] === '') {
            $issues['contact_name'] = $this->issue(
                self::SEVERITY_ERROR,
                (string) __('Please enter the recipient\'s name.')
            );
        }

        if ($fields['contact_phone'] === '') {
            $issues['contact_phone'] = $this->issue(
                self::SEVERITY_ERROR,
                (string) __('Please enter a phone number.')
            );
        } else {
            $fields['contact_phone'] = ShipmentSender::normalizePhone($fields['contact_phone']);

            // A number too short to dial is the worst case of all: Pigeon Express
            // looks the recipient up by phone, so a wrong one can attach the
            // parcel to somebody else's stored address.
            if (!BulgarianPhone::isValid($fields['contact_phone'])) {
                $issues['contact_phone'] = $this->issue(
                    self::SEVERITY_ERROR,
                    (string) __('"%1" is not a usable phone number. Check it with the customer.', $fields['contact_phone'])
                );
            }
        }

        if (!$this->isAddressDelivery($fields)) {
            // The parcel goes to a counter or a locker, so a street on the
            // payload could only ever contradict where it is actually going.
            foreach (self::ADDRESS_ONLY_FIELDS as $field) {
                $fields[$field] = '';
            }

            $this->resolveLocation($fields, $issues);

            return ['fields' => $fields, 'issues' => $issues];
        }

        foreach (self::LOCATION_ONLY_FIELDS as $field) {
            $fields[$field] = '';
        }

        // The customer's own postcode, taken before resolveCity() fills a blank
        // one from the city: only theirs can tell apart two streets of one name.
        $customerPostcode = trim((string) $fields['postal_code']);
        $cityId = $this->resolveCity($fields, $issues);
        $this->resolveStreet($fields, $issues, $cityId, $storeId, $customerPostcode);

        if ($fields['street_number'] === '' && !isset($issues['street'])) {
            $issues['street_number'] = $this->issue(
                self::SEVERITY_WARNING,
                (string) __('No street number. The courier may not be able to find the address.')
            );
        }

        return ['fields' => $fields, 'issues' => $issues];
    }

    /**
     * The admin's own parcel figures, or null when the form leaves them to us.
     *
     * All or nothing: a weight without a size, or the other way round, cannot
     * describe a parcel, so a partly filled form is treated as not filled.
     *
     * @param array<string, string> $fields Checked form values
     * @return array{weight:float, length:float, width:float, height:float, parcels:int}|null
     */
    public function parcel(array $fields): ?array
    {
        foreach (self::PARCEL_FIELDS as $field) {
            if ($this->number((string) ($fields[$field] ?? '')) === null) {
                return null;
            }
        }

        return [
            'weight'  => (float) $this->number($fields['weight']),
            'length'  => (float) $this->number($fields['length']),
            'width'   => (float) $this->number($fields['width']),
            'height'  => (float) $this->number($fields['height']),
            'parcels' => (int) $this->number($fields['parcels']),
        ];
    }

    /**
     * The cash on delivery amount the admin typed, or null to work it out.
     *
     * @param array<string, string> $fields Checked form values
     */
    public function codAmount(array $fields): ?float
    {
        $value = $this->number((string) ($fields['cod_amount'] ?? ''));

        return $value === null ? null : round($value, 2);
    }

    /**
     * Check the cash on delivery amount and write it back in one notation.
     *
     * Commas are accepted, because that is how a Bulgarian keyboard types a
     * decimal. Empty is fine: it means "as worked out from the order".
     *
     * @param array<string, mixed> $fields
     * @param array<string, array{severity:string, message:string}> $issues
     */
    private function checkCodAmount(array &$fields, array &$issues): void
    {
        $raw = trim((string) ($fields['cod_amount'] ?? ''));
        if ($raw === '') {
            $fields['cod_amount'] = '';

            return;
        }

        $value = $this->number($raw);
        if ($value === null || $value < 0) {
            $issues['cod_amount'] = $this->issue(
                self::SEVERITY_ERROR,
                (string) __('Please enter an amount, or 0 for no cash on delivery.')
            );

            return;
        }

        $fields['cod_amount'] = (string) $value;
    }

    /**
     * Check the package rows and write them back in one notation.
     *
     * A form stored before rows existed carries a total weight, a parcel count
     * and one size instead. Those become that many equal rows here, so the
     * admin sees them as rows, and the old fields are emptied: two ways of
     * saying the same thing would sooner or later say different things.
     *
     * No rows at all leaves the parcels to be worked out from the order, as
     * before. A row left completely empty is dropped; a row that is partly
     * wrong is kept as typed, so the admin can correct it.
     *
     * @param array<string, mixed> $fields
     * @param array<string, array{severity:string, message:string}> $issues
     */
    private function checkPackages(array &$fields, array &$issues, int $storeId): void
    {
        $rows = isset($fields['packages']) && is_array($fields['packages'])
            ? $fields['packages']
            : $this->rowsFromOldTotals($fields, $storeId);

        foreach (self::PARCEL_FIELDS as $field) {
            $fields[$field] = '';
        }

        if ($rows === null) {
            unset($fields['packages']);

            return;
        }

        $mode = $this->fragileMode($storeId);
        $defaults = [
            'length' => $this->config->getDefaultLength($storeId),
            'width'  => $this->config->getDefaultWidth($storeId),
            'height' => $this->config->getDefaultHeight($storeId),
        ];

        $clean = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $raw = [];
            foreach (['weight', 'length', 'width', 'height', 'description'] as $key) {
                $value = $row[$key] ?? '';
                $raw[$key] = is_scalar($value) ? trim((string) $value) : '';
            }

            if (implode('', $raw) === '') {
                continue;
            }

            $number = count($clean) + 1;
            $item = [
                'weight'      => $raw['weight'],
                'length'      => $raw['length'],
                'width'       => $raw['width'],
                'height'      => $raw['height'],
                'description' => mb_substr($raw['description'], 0, self::PACKAGE_DESCRIPTION_MAX_LENGTH),
                'fragile'     => $this->fragileFor($mode, $row['fragile'] ?? false),
            ];

            $weight = $this->number($raw['weight']);
            if ($weight === null || $weight <= 0) {
                $issues['packages'] = $issues['packages'] ?? $this->issue(
                    self::SEVERITY_ERROR,
                    (string) __('Package %1: please enter a weight greater than zero.', $number)
                );
            } else {
                $item['weight'] = round($weight, 3);
            }

            foreach (['length', 'width', 'height'] as $dimension) {
                if ($raw[$dimension] === '') {
                    // Left empty: the store's default box, as the order itself would get.
                    $item[$dimension] = max(1, (int) ceil((float) $defaults[$dimension]));
                    continue;
                }

                $size = $this->number($raw[$dimension]);
                if ($size === null || $size <= 0) {
                    $issues['packages'] = $issues['packages'] ?? $this->issue(
                        self::SEVERITY_ERROR,
                        (string) __('Package %1: please enter the size in centimetres, greater than zero.', $number)
                    );
                    continue;
                }

                $item[$dimension] = (int) ceil($size);
            }

            $clean[] = $item;
        }

        if ($clean === []) {
            $issues['packages'] = $this->issue(
                self::SEVERITY_ERROR,
                (string) __('Add at least one package.')
            );
        }

        $fields['packages'] = $clean;
    }

    /**
     * Only the package part of validate(), for a caller that needs the rows
     * and nothing else (the hand-in point), without looking up the address.
     *
     * @param array<string, mixed> $fields
     * @return array{fields: array<string, mixed>, issues: array<string, array{severity:string, message:string}>}
     */
    public function validatePackages(array $fields, int $storeId): array
    {
        $issues = [];
        $this->checkPackages($fields, $issues, $storeId);

        return ['fields' => $fields, 'issues' => $issues];
    }

    /**
     * The old total weight, parcel count and size as equal package rows, or
     * null when the form did not carry them.
     *
     * @param array<string, mixed> $fields
     * @return array<int, array<string, mixed>>|null
     */
    private function rowsFromOldTotals(array $fields, int $storeId): ?array
    {
        $parcel = $this->parcel($fields);
        if ($parcel === null || $parcel['parcels'] < 1) {
            return null;
        }

        $fragile = $this->fragileMode($storeId) === self::FRAGILE_ALWAYS;
        $rows = [];
        for ($i = 0; $i < $parcel['parcels']; $i++) {
            $rows[] = [
                'weight'      => (string) round($parcel['weight'] / $parcel['parcels'], 3),
                'length'      => (string) $parcel['length'],
                'width'       => (string) $parcel['width'],
                'height'      => (string) $parcel['height'],
                'description' => '',
                'fragile'     => $fragile,
            ];
        }

        return $rows;
    }

    /**
     * The checked package rows of a form, or null when the parcels are left to
     * be worked out from the order.
     *
     * @param array<string, mixed> $fields Checked form values
     * @return array<int, array{weight:float, length:int, width:int, height:int, description:string, fragile:bool}>|null
     */
    public function packages(array $fields): ?array
    {
        if (empty($fields['packages']) || !is_array($fields['packages'])) {
            return null;
        }

        $rows = [];
        foreach ($fields['packages'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = [
                'weight'      => (float) ($row['weight'] ?? 0),
                'length'      => (int) ($row['length'] ?? 0),
                'width'       => (int) ($row['width'] ?? 0),
                'height'      => (int) ($row['height'] ?? 0),
                'description' => (string) ($row['description'] ?? ''),
                'fragile'     => !empty($row['fragile']),
            ];
        }

        return $rows === [] ? null : $rows;
    }

    /**
     * Which packages may be marked fragile, as the store setting says.
     */
    public function fragileMode(?int $storeId = null): string
    {
        $mode = method_exists($this->config, 'getFragileMode')
            ? (string) $this->config->getFragileMode($storeId)
            : self::FRAGILE_NEVER;

        return in_array($mode, [self::FRAGILE_NEVER, self::FRAGILE_ALWAYS, self::FRAGILE_PER_PACKAGE], true)
            ? $mode
            : self::FRAGILE_NEVER;
    }

    /**
     * The fragile mark of one row: forced by the setting unless it is per package.
     *
     * @param mixed $submitted
     */
    private function fragileFor(string $mode, $submitted): bool
    {
        if ($mode === self::FRAGILE_ALWAYS) {
            return true;
        }

        return $mode === self::FRAGILE_PER_PACKAGE && $this->flag($submitted);
    }

    /**
     * Check the per-shipment services and fill what was not sent from the settings.
     *
     * A locker has nobody to show the parcel to or to sign a receipt, so
     * inspection and return receipts are always off there, whatever was sent.
     *
     * @param array<string, mixed> $fields
     * @param array<string, array{severity:string, message:string}> $issues
     */
    private function checkServices(array &$fields, array &$issues, int $storeId): void
    {
        $type = $this->deliveryType($fields);
        $isLocker = $type === ConfigInterface::DELIVERY_TYPE_APS;

        $fields['declared_value'] = array_key_exists('declared_value', $fields)
            ? $this->flag($fields['declared_value'])
            : $this->declaredValueDefault($storeId);

        $amount = $fields['declared_value_amount'] ?? null;
        $amount = is_scalar($amount) ? trim((string) $amount) : '';
        if (!$fields['declared_value'] || $amount === '') {
            $fields['declared_value_amount'] = null;
        } else {
            $value = $this->number($amount);
            if ($value === null || $value <= 0) {
                $fields['declared_value_amount'] = $amount;
                $issues['declared_value_amount'] = $this->issue(
                    self::SEVERITY_ERROR,
                    (string) __(
                        'Enter the declared value as an amount greater than zero, '
                        . 'or leave it empty to use the value of the goods.'
                    )
                );
            } elseif (!$isLocker && (
                $value < ServiceCodesBuilder::DECLARED_VALUE_MIN || $value > ServiceCodesBuilder::DECLARED_VALUE_MAX
            )) {
                // Checked, never moved, as in WooCommerce: Pigeon Express
                // refuses the whole shipment outside its limits.
                $fields['declared_value_amount'] = $amount;
                $issues['declared_value_amount'] = $this->issue(
                    self::SEVERITY_ERROR,
                    (string) __(
                        'Declared value: enter an amount from %1 to %2 €.',
                        (int) ServiceCodesBuilder::DECLARED_VALUE_MIN,
                        (int) ServiceCodesBuilder::DECLARED_VALUE_MAX
                    )
                );
            } else {
                $fields['declared_value_amount'] = round($value, 2);
            }
        }

        $review = (string) ($fields['review'] ?? '');
        if (!in_array($review, self::REVIEW_VALUES, true)) {
            $review = $this->reviewDefault($type, $storeId);
        }
        $fields['review'] = $isLocker ? self::REVIEW_NONE : $review;

        $receipt = (string) ($fields['return_receipt'] ?? '');
        if (!in_array($receipt, self::RECEIPT_VALUES, true)) {
            $receipt = $this->receiptDefault($storeId);
        }
        $fields['return_receipt'] = $isLocker ? self::RECEIPT_NONE : $receipt;
    }

    /**
     * Whether a new shipment starts with declared value ticked.
     */
    public function declaredValueDefault(?int $storeId = null): bool
    {
        if (method_exists($this->config, 'isDeclaredValueAlways')) {
            return (bool) $this->config->isDeclaredValueAlways($storeId);
        }

        return $this->config->isDeclaredValueEnabled($storeId);
    }

    /**
     * The inspection a new shipment starts with, from the delivery type's setting.
     */
    public function reviewDefault(string $deliveryType, ?int $storeId = null): string
    {
        if ($deliveryType === ConfigInterface::DELIVERY_TYPE_APS) {
            return self::REVIEW_NONE;
        }

        try {
            if (method_exists($this->config, 'getReviewMode')) {
                $mode = (string) $this->config->getReviewMode($deliveryType, $storeId);

                return in_array($mode, self::REVIEW_VALUES, true) ? $mode : self::REVIEW_NONE;
            }

            $legacy = $this->config->getReviewAndTest($deliveryType, $storeId);
        } catch (\Throwable $e) {
            return self::REVIEW_NONE;
        }

        if ($legacy === 'test') {
            return self::REVIEW_VIEW_TEST;
        }

        return $legacy === 'review' ? self::REVIEW_VIEW : self::REVIEW_NONE;
    }

    /**
     * The return receipt a new shipment starts with, from the settings.
     */
    public function receiptDefault(?int $storeId = null): string
    {
        if (method_exists($this->config, 'getReturnReceiptMode')) {
            $mode = (string) $this->config->getReturnReceiptMode($storeId);

            return in_array($mode, self::RECEIPT_VALUES, true) ? $mode : self::RECEIPT_NONE;
        }

        $electronic = $this->config->isAdditionalServiceEnabled('return_receipt', $storeId);
        $paper = $this->config->isAdditionalServiceEnabled('return_at_my_expense', $storeId);

        if ($electronic && $paper) {
            return self::RECEIPT_BOTH;
        }
        if ($electronic) {
            return self::RECEIPT_ELECTRONIC;
        }

        return $paper ? self::RECEIPT_PAPER : self::RECEIPT_NONE;
    }

    /**
     * A decimal typed with either a point or a comma, or null if it is not one.
     */
    private function number(string $raw): ?float
    {
        $raw = str_replace([' ', ','], ['', '.'], trim($raw));

        return $raw !== '' && is_numeric($raw) ? (float) $raw : null;
    }

    /**
     * Check the chosen office or locker against our own synced list.
     *
     * The name is taken from that list rather than from what was submitted, so
     * the panel shows the counter the parcel will actually go to instead of
     * whatever text happened to be left in the box.
     *
     * @param array<string, string> $fields
     * @param array<string, array{severity:string, message:string}> $issues
     */
    private function resolveLocation(array &$fields, array &$issues): void
    {
        $type = $this->deliveryType($fields);
        $isLocker = $type === ConfigInterface::DELIVERY_TYPE_APS;

        if ($fields['location_id'] === '') {
            $issues['location'] = $this->issue(
                self::SEVERITY_ERROR,
                $isLocker
                    ? (string) __('Choose a parcel locker from the list.')
                    : (string) __('Choose a Pigeon Express office from the list.')
            );

            return;
        }

        $location = $this->locations->resolveLocation($type, $fields['location_id']);
        if ($location === null) {
            // Offices close and lockers are moved, and the nightly sync retires
            // them. An id that no longer resolves must not reach the API.
            $fields['location_id'] = '';
            $issues['location'] = $this->issue(
                self::SEVERITY_ERROR,
                $isLocker
                    ? (string) __('That parcel locker is no longer available. Choose another from the list.')
                    : (string) __('That Pigeon Express office is no longer available. Choose another from the list.')
            );

            return;
        }

        $fields['location'] = trim($location['name'] . ', ' . $location['city'], ' ,');
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, array{severity:string, message:string}> $issues
     */
    private function resolveCity(array &$fields, array &$issues): ?int
    {
        if ($fields['city'] === '' && $fields['city_id'] === '') {
            $issues['city'] = $this->issue(
                self::SEVERITY_ERROR,
                (string) __('Please choose a city from the list.')
            );

            return null;
        }

        // An id from the picker is authoritative, but only if it is still real:
        // a location sync can retire a settlement between one order and the next.
        if ($fields['city_id'] !== '') {
            $city = $this->resolver->getCityById((int) $fields['city_id']);
            if ($city !== null) {
                $fields['city'] = $city['name'];
                if ($fields['postal_code'] === '') {
                    $fields['postal_code'] = $city['postcode'];
                }

                return $city['id'];
            }
            $fields['city_id'] = '';
        }

        $cityId = $this->resolver->matchCityId($fields['city'], $fields['postal_code']);
        if ($cityId === null) {
            $issues['city'] = $this->issue(
                self::SEVERITY_ERROR,
                (string) __(
                    'Pigeon Express does not deliver to a city called "%1". Start typing and choose one from the list.',
                    $fields['city']
                )
            );

            return null;
        }

        $fields['city_id'] = (string) $cityId;
        $city = $this->resolver->getCityById($cityId);
        if ($city !== null) {
            $fields['city'] = $city['name'];
            if ($fields['postal_code'] === '') {
                $fields['postal_code'] = $city['postcode'];
            }
        }

        return $cityId;
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, array{severity:string, message:string}> $issues
     * @param string $postalCode The postcode as given, before the city filled a blank one
     */
    private function resolveStreet(
        array &$fields,
        array &$issues,
        ?int $cityId,
        int $storeId,
        string $postalCode = ''
    ): void {
        $preferredType = '';

        if ($fields['street_id'] === '') {
            // Nothing was picked from the list, so the admin may have typed the
            // whole thing into one box. Take the number and the type off it, or
            // the name will never match anything the carrier holds.
            $line = $this->resolver->cutAtAddressComponents($fields['street']);
            [$name, $number] = $this->resolver->extractStreetNameAndNumber($line);

            if ($number !== '' && $fields['street_number'] === '') {
                $fields['street_number'] = $number;
            }

            $fields['street'] = $name !== '' ? $name : $fields['street'];
            $preferredType = $this->resolver->extractStreetType($fields['street']);
        }

        $fields['street'] = $this->resolver->stripStreetTypePrefix($fields['street']);

        if ($fields['street'] === '') {
            $issues['street'] = $this->issue(
                self::SEVERITY_ERROR,
                (string) __('Please enter a street.')
            );
            $fields['street_id'] = '';

            return;
        }

        if ($cityId === null) {
            // Nothing to look the street up against yet. The city error already
            // tells the admin what to do, so this stays quiet.
            $fields['street_id'] = '';

            return;
        }

        if ($fields['street_id'] !== '') {
            return;
        }

        $match = $this->resolver->matchStreet($cityId, $fields['street'], $storeId, $preferredType, $postalCode);
        if ($match !== null) {
            $fields['street'] = $match['name'];
            $fields['street_id'] = (string) $match['id'];

            if (!empty($match['ambiguous'])) {
                // Sofia has a квартал and a булевард both called ВИТОША, in
                // different parts of the city. Guessing here would be a coin toss
                // with somebody's parcel.
                $issues['street'] = $this->issue(
                    self::SEVERITY_WARNING,
                    (string) __(
                        'More than one street in %1 is called %2. Pick the right one from the list, '
                        . 'otherwise %3 is used.',
                        $fields['city'] !== '' ? $fields['city'] : (string) __('this city'),
                        $match['name'],
                        $match['label']
                    )
                );
            }

            return;
        }

        $issues['street'] = $this->issue(
            self::SEVERITY_WARNING,
            (string) __(
                'Pigeon Express has no street by this name in %1. '
                . 'It may deliver to another address it already holds for this phone number. '
                . 'Choose a street from the list, or check it with the customer.',
                $fields['city'] !== '' ? $fields['city'] : (string) __('this city')
            )
        );
    }

    /**
     * The delivery_address block exactly as the API will receive it.
     *
     * @param array<string, string> $fields
     * @return array<string, mixed>
     */
    public function toApiAddress(array $fields): array
    {
        $fields = array_merge($this->blank(), $fields);
        $address = [];

        if ($fields['city_id'] !== '') {
            $address['city_id'] = (int) $fields['city_id'];
        }
        if ($fields['street_id'] !== '') {
            $address['street_id'] = (int) $fields['street_id'];
        }

        foreach ([
            'street'          => 'street_name',
            'street_number'   => 'street_number',
            'building_number' => 'building_number',
            'entrance'        => 'entrance',
            'floor'           => 'floor',
            'apartment'       => 'apartment',
            'postal_code'     => 'postal_code',
            'contact_name'    => 'contact_name',
            'contact_phone'   => 'contact_phone',
        ] as $field => $apiField) {
            if ($fields[$field] !== '') {
                $address[$apiField] = mb_substr($fields[$field], 0, 255);
            }
        }

        $address['additional_info'] = $this->resolver->buildAdditionalInfo(
            $this->describeAddress($fields),
            $fields['street']
        );

        return $address;
    }

    /**
     * The address written out the way a person would write it, for the courier
     * to read when the structured fields are not enough.
     *
     * @param array<string, string> $fields
     */
    private function describeAddress(array $fields): string
    {
        $parts = [];

        $street = trim($fields['street'] . ' ' . $fields['street_number']);
        if ($street !== '') {
            $parts[] = $street;
        }

        foreach ([
            'building_number' => 'бл.',
            'entrance'        => 'вх.',
            'floor'           => 'ет.',
            'apartment'       => 'ап.',
        ] as $field => $prefix) {
            if ($fields[$field] !== '') {
                $parts[] = $prefix . ' ' . $fields[$field];
            }
        }

        return implode(', ', $parts);
    }

    /**
     * @return array{severity:string, message:string}
     */
    private function issue(string $severity, string $message): array
    {
        return ['severity' => $severity, 'message' => $message];
    }

    /**
     * Whether anything in the report stops the shipment being created.
     *
     * @param array<string, array{severity:string, message:string}> $issues
     */
    public function hasErrors(array $issues): bool
    {
        foreach ($issues as $issue) {
            if (($issue['severity'] ?? '') === self::SEVERITY_ERROR) {
                return true;
            }
        }

        return false;
    }

    /**
     * The first blocking message, for a caller that can only report one.
     *
     * @param array<string, array{severity:string, message:string}> $issues
     */
    public function firstError(array $issues): string
    {
        foreach ($issues as $issue) {
            if (($issue['severity'] ?? '') === self::SEVERITY_ERROR) {
                return (string) $issue['message'];
            }
        }

        return '';
    }
}
