<?php
/**
 * The create body of a return shipment, from the order and the return form.
 *
 * A return (reverse) shipment is created by the shop's account as the
 * RECEIVER: the customer is the sender, given with sender_*, and the receiver
 * defaults to the account. As WooCommerce's ReverseShipmentService::build_body().
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\ReturnShipment;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\Phone\BulgarianPhone;
use PigeonExpress\Shipping\Model\Pickup\PickupPayload;
use PigeonExpress\Shipping\Model\Shipment\AddressForm;

class ReturnBodyBuilder
{
    public const PICKUP_ADDRESS = 'address';
    public const PICKUP_OFFICE = 'office';
    public const PICKUP_LOCKER = 'locker';

    public const DELIVERY_OFFICE = 'office';
    public const DELIVERY_ADDRESS = 'address';

    /** @var ConfigInterface */
    private $config;

    /** @var PickupPayload */
    private $pickupPayload;

    public function __construct(ConfigInterface $config, PickupPayload $pickupPayload)
    {
        $this->config = $config;
        $this->pickupPayload = $pickupPayload;
    }

    /**
     * The return form from a request, cleaned. Unknown keys are dropped.
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public function fromRequest(array $raw): array
    {
        $text = static function (string $key) use ($raw): string {
            return isset($raw[$key]) && is_scalar($raw[$key]) ? trim(strip_tags((string) $raw[$key])) : '';
        };
        $id = static function (string $key) use ($text): string {
            $value = (int) $text($key);

            return $value > 0 ? (string) $value : '';
        };

        $pickupType = $text('pickup_type');
        $form = [
            'sender_name'           => $text('sender_name'),
            'sender_phone'          => $text('sender_phone'),
            'sender_email'          => $text('sender_email'),
            'pickup_type'           => in_array($pickupType, [self::PICKUP_ADDRESS, self::PICKUP_OFFICE, self::PICKUP_LOCKER], true)
                ? $pickupType : '',
            'city_id'               => $id('city_id'),
            'city_label'            => $text('city_label'),
            'street_id'             => $id('street_id'),
            'street_label'          => $text('street_label'),
            'additional_info'       => $text('additional_info'),
            'pickup_office_id'      => $id('pickup_office_id'),
            'pickup_office_label'   => $text('pickup_office_label'),
            'pickup_locker_id'      => $id('pickup_locker_id'),
            'pickup_locker_label'   => $text('pickup_locker_label'),
            'delivery_type'         => $text('delivery_type') === self::DELIVERY_ADDRESS ? self::DELIVERY_ADDRESS : self::DELIVERY_OFFICE,
            'delivery_office_id'    => $id('delivery_office_id'),
            'delivery_office_label' => $text('delivery_office_label'),
            'who_pays'              => $text('who_pays') === ConfigInterface::WHO_PAYS_SENDER
                ? ConfigInterface::WHO_PAYS_SENDER : ConfigInterface::WHO_PAYS_RECEIVER,
            'note'                  => mb_substr($text('note'), 0, AddressForm::NOTE_MAX_LENGTH),
            'notify_customer'       => !empty($raw['notify_customer']),
            'packages'              => [],
        ];

        foreach ((isset($raw['packages']) && is_array($raw['packages']) ? $raw['packages'] : []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $number = static function ($value): float {
                $value = str_replace(',', '.', trim((string) (is_scalar($value) ? $value : '')));

                return is_numeric($value) ? max(0.0, (float) $value) : 0.0;
            };
            $form['packages'][] = [
                'weight' => $number($row['weight'] ?? ''),
                'length' => (int) round($number($row['length'] ?? '')),
                'width'  => (int) round($number($row['width'] ?? '')),
                'height' => (int) round($number($row['height'] ?? '')),
            ];
        }

        return $form;
    }

    /**
     * @param array<string, mixed> $form As fromRequest() returns it
     * @return array<string, mixed>
     * @throws LocalizedException With every problem of the form, one per line
     */
    public function build(OrderInterface $order, array $form, int $storeId): array
    {
        $errors = [];
        $name = trim((string) ($form['sender_name'] ?? ''));
        $phone = trim((string) ($form['sender_phone'] ?? ''));
        $email = trim((string) ($form['sender_email'] ?? ''));

        if ($name === '') {
            $errors[] = (string) __('Sender name is required.');
        }
        if ($phone === '') {
            $errors[] = (string) __('Sender phone is required.');
        } elseif (!BulgarianPhone::isValid($phone)) {
            $errors[] = (string) __(
                'Sender phone "%1" is not a valid Bulgarian number. Use a 10-digit mobile (e.g. 0881234567) or include the +359 country code.',
                $phone
            );
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = (string) __('Sender email "%1" is not a valid email address.', $email);
        }

        $isLocker = (string) ($form['pickup_type'] ?? '') === self::PICKUP_LOCKER;
        $pickup = $this->pickupBlock($form, $errors);
        $delivery = $this->deliveryBlock($form, $errors, $storeId);
        $packages = $this->packages((array) ($form['packages'] ?? []), $isLocker, $errors, $storeId);

        if ($errors !== []) {
            throw new LocalizedException(__(implode("\n", $errors)));
        }

        $body = [
            'is_reverse'   => true,
            'sender_name'  => mb_substr($name, 0, 255),
            'sender_phone' => '+' . (string) BulgarianPhone::normalize($phone),
        ];
        if ($email !== '') {
            $body['sender_email'] = $email;
        }

        $body = array_merge($body, $pickup, $delivery, [
            'packages'        => $packages,
            'inventory_items' => $this->inventoryItems($order),
            'service_type'    => 'standard',
            'who_pays'        => (string) ($form['who_pays'] ?? '') === ConfigInterface::WHO_PAYS_SENDER
                ? ConfigInterface::WHO_PAYS_SENDER : ConfigInterface::WHO_PAYS_RECEIVER,
        ]);

        $note = trim((string) ($form['note'] ?? ''));
        if ($note !== '') {
            $body['note'] = mb_substr($note, 0, AddressForm::NOTE_MAX_LENGTH);
        }

        // The order the return belongs to, as on the order's own shipment.
        $increment = trim((string) $order->getIncrementId());
        if ($increment !== '') {
            $body['external_reference'] = mb_substr($increment, 0, 100);
        }

        if ($this->config->isHideContentOnLabel($storeId)) {
            $body['hide_content_on_label'] = true;
        }

        return $body;
    }

    /**
     * Where the return goes when the merchant does not choose: the shop's
     * office from Send From (or the locker's fallback office), else the
     * shop's address.
     *
     * @return array{type:string, office_id:string}
     */
    public function defaultDelivery(int $storeId): array
    {
        $type = $this->config->getPickupType($storeId);
        $office = null;
        if ($type === ConfigInterface::PICKUP_TYPE_OFFICE) {
            $office = $this->config->getPickupOfficeId($storeId);
        } elseif ($type === ConfigInterface::PICKUP_TYPE_LOCKER) {
            $office = $this->config->getPickupFallbackOfficeId($storeId);
        }

        return $office !== null && $office > 0
            ? ['type' => self::DELIVERY_OFFICE, 'office_id' => (string) $office]
            : ['type' => self::DELIVERY_ADDRESS, 'office_id' => ''];
    }

    /**
     * The shop's address from Send From, in the fields the delivery end takes:
     * a saved address by its id, or the typed address. Empty when Send From is
     * an office or a locker or the address is incomplete: Pigeon Express then
     * delivers to the default address of the account.
     *
     * @return array<string, mixed>
     */
    public function shopAddressFields(int $storeId): array
    {
        $type = $this->config->getPickupType($storeId);
        if ($type === ConfigInterface::PICKUP_TYPE_SAVED_ADDRESS) {
            $id = $this->config->getPickupSavedAddressId($storeId);

            return $id !== null && $id > 0 ? ['delivery_address_id' => $id] : [];
        }
        if ($type !== ConfigInterface::PICKUP_TYPE_ADDRESS) {
            return [];
        }

        try {
            return ['delivery_address' => $this->pickupPayload->typedAddress($storeId)];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $form
     * @param string[] $errors
     * @return array<string, mixed>
     */
    private function pickupBlock(array $form, array &$errors): array
    {
        switch ((string) ($form['pickup_type'] ?? '')) {
            case self::PICKUP_ADDRESS:
                $city = (int) ($form['city_id'] ?? 0);
                $street = (int) ($form['street_id'] ?? 0);
                $info = trim((string) ($form['additional_info'] ?? ''));

                if ($city <= 0) {
                    $errors[] = (string) __('Pick the city of the address the courier collects the shipment from.');
                }
                if ($info === '') {
                    $errors[] = (string) __('Enter the house number (and building / entrance / floor if any).');
                } elseif ($street <= 0 && mb_strlen($info) < 3) {
                    $errors[] = (string) __('With no street picked from the list, enter the street and the house number in the address line.');
                }

                $address = ['city_id' => $city, 'additional_info' => mb_substr($info, 0, 255)];
                if ($street > 0) {
                    $address['street_id'] = $street;
                }

                return ['pickup_type' => 'address', 'pickup_address' => $address];

            case self::PICKUP_OFFICE:
                $office = (int) ($form['pickup_office_id'] ?? 0);
                if ($office <= 0) {
                    $errors[] = (string) __('Pick the office where the customer hands the shipment in.');
                }

                return ['pickup_type' => 'office', 'pickup_office_id' => $office];

            case self::PICKUP_LOCKER:
                $locker = (int) ($form['pickup_locker_id'] ?? 0);
                if ($locker <= 0) {
                    $errors[] = (string) __('Pick the parcel locker where the customer hands the shipment in.');
                }

                return ['pickup_type' => 'locker', 'pickup_office_id' => $locker];
        }

        $errors[] = (string) __('Choose where the courier collects the shipment.');

        return [];
    }

    /**
     * @param array<string, mixed> $form
     * @param string[] $errors
     * @return array<string, mixed>
     */
    private function deliveryBlock(array $form, array &$errors, int $storeId): array
    {
        if ((string) ($form['delivery_type'] ?? '') === self::DELIVERY_ADDRESS) {
            return array_merge(['delivery_type' => 'address'], $this->shopAddressFields($storeId));
        }

        $office = (int) ($form['delivery_office_id'] ?? 0);
        if ($office <= 0) {
            $errors[] = (string) __('Pick the office the return shipment is delivered to.');
        }

        return ['delivery_type' => 'office', 'delivery_office_id' => $office];
    }

    /**
     * Package rows as the carrier takes them: blank rows dropped, a missing
     * size from the default parcel in the settings.
     *
     * @param array<int, mixed> $rows
     * @param string[] $errors
     * @return array<int, array{weight: float, length: int, width: int, height: int}>
     */
    private function packages(array $rows, bool $isLocker, array &$errors, int $storeId): array
    {
        $box = [
            'length' => (int) round($this->config->getDefaultLength($storeId)),
            'width'  => (int) round($this->config->getDefaultWidth($storeId)),
            'height' => (int) round($this->config->getDefaultHeight($storeId)),
        ];
        $out = [];
        $number = 0;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $weight = (float) ($row['weight'] ?? 0);
            $size = [
                'length' => (int) ($row['length'] ?? 0),
                'width'  => (int) ($row['width'] ?? 0),
                'height' => (int) ($row['height'] ?? 0),
            ];
            if ($weight <= 0 && max($size) === 0) {
                continue;
            }
            $number++;

            if ($weight <= 0) {
                $errors[] = (string) __('Package %1: enter the weight.', $number);
            }
            if ($isLocker && min($size) <= 0) {
                $errors[] = (string) __('APS lockers require weight + all three dimensions.');
            }
            foreach ($size as $side => $value) {
                if ($value <= 0) {
                    $size[$side] = $box[$side];
                }
            }
            if (min($size) <= 0) {
                $errors[] = (string) __('Package %1: enter the length, width and height.', $number);
            }

            $out[] = [
                'weight' => $weight > 0 && $weight < 0.1 ? 0.1 : round($weight, 3),
                'length' => $size['length'],
                'width'  => $size['width'],
                'height' => $size['height'],
            ];
        }

        if ($out === []) {
            $errors[] = (string) __('Enter at least one package with its weight.');
        }

        return $out;
    }

    /**
     * What comes back: the order's products.
     *
     * @return array<int, array{description: string, quantity: int}>
     */
    private function inventoryItems(OrderInterface $order): array
    {
        $items = [];
        foreach ($order->getItems() as $item) {
            if ($item->getParentItemId()) {
                continue;
            }
            $items[] = [
                'description' => mb_substr((string) $item->getName(), 0, 200),
                'quantity'    => max(1, (int) $item->getQtyOrdered()),
            ];
        }
        if ($items === []) {
            $items[] = ['description' => '#' . $order->getIncrementId(), 'quantity' => 1];
        }

        return array_slice($items, 0, 100);
    }
}
