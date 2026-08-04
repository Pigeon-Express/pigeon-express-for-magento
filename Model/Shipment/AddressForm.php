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
use PigeonExpress\Shipping\Model\Rate\LocationToApiIdResolver;
use PigeonExpress\Shipping\Model\ShipmentSender;

class AddressForm
{
    /** The shipment cannot be created until this is fixed. */
    public const SEVERITY_ERROR = 'error';

    /** The shipment can be created, but is likely to be delivered incorrectly. */
    public const SEVERITY_WARNING = 'warning';

    /**
     * Shortest thing that can still be a phone number anywhere.
     *
     * Deliberately low. This is here to catch a field somebody typed "n/a" or a
     * house number into, not to validate dialling plans.
     */
    private const MIN_PHONE_DIGITS = 8;

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
    ];

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

    public function __construct(
        AddressResolver $resolver,
        LocationToApiIdResolver $locations,
        ConfigInterface $config
    ) {
        $this->resolver = $resolver;
        $this->locations = $locations;
        $this->config = $config;
    }

    /**
     * Who the carrier bills for this shipment.
     *
     * Falls back to the store setting rather than to a hardcoded side, so an
     * order sent without the form still bills whoever the merchant configured.
     *
     * A delivery the customer was given free is the one exception: billing the
     * recipient there would have the courier collect at the door for something
     * the shop already said cost nothing. It changes the fallback only. An admin
     * who picks a side on the shipment form still gets the side they picked,
     * because a screen that says one thing while the carrier is told another is
     * worse than the charge it was trying to prevent.
     *
     * @param array<string, string> $fields
     */
    public function whoPays(array $fields, ?int $storeId = null, bool $freeShipping = false): string
    {
        $value = (string) ($fields['who_pays'] ?? '');

        if ($value === ConfigInterface::WHO_PAYS_SENDER || $value === ConfigInterface::WHO_PAYS_RECEIVER) {
            return $value;
        }

        if ($freeShipping) {
            return ConfigInterface::WHO_PAYS_SENDER;
        }

        return $this->config->getWhoPays($storeId);
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

        return $fields;
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

        return $fields;
    }

    /**
     * Check the form and resolve whatever the admin left for us to resolve.
     *
     * Errors stop the shipment. Warnings do not, because a merchant who knows
     * the address is fine should not be prevented from shipping by a street the
     * carrier has never heard of.
     *
     * @param array<string, string> $fields
     * @param bool $freeShipping The customer was not charged for this delivery
     * @return array{fields: array<string, string>, issues: array<string, array{severity:string, message:string}>}
     */
    public function validate(array $fields, int $storeId, bool $freeShipping = false): array
    {
        $fields = array_merge($this->blank(), $fields);
        $fields['delivery_type'] = $this->deliveryType($fields);
        $fields['who_pays'] = $this->whoPays($fields, $storeId, $freeShipping);
        $issues = [];

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
            if (strlen((string) preg_replace('/\D/', '', $fields['contact_phone'])) < self::MIN_PHONE_DIGITS) {
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

        $cityId = $this->resolveCity($fields, $issues);
        $this->resolveStreet($fields, $issues, $cityId, $storeId);

        if ($fields['street_number'] === '' && !isset($issues['street'])) {
            $issues['street_number'] = $this->issue(
                self::SEVERITY_WARNING,
                (string) __('No street number. The courier may not be able to find the address.')
            );
        }

        return ['fields' => $fields, 'issues' => $issues];
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
     */
    private function resolveStreet(array &$fields, array &$issues, ?int $cityId, int $storeId): void
    {
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

        $match = $this->resolver->matchStreet($cityId, $fields['street'], $storeId, $preferredType);
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
