<?php
/**
 * Courier requests: asking Pigeon Express to send a courier to the shop's
 * address, with the shipments the courier should collect.
 *
 * Only for a shop the courier collects from (pickup type address or saved
 * address). One request takes up to 100 registered shipments: one makes a
 * single request, two or more a groupage; none is allowed too. Pigeon Express
 * decides whether a date and time can be served and answers 422 when not.
 * The carrier deduplicates an identical request within 30 seconds. As
 * WooCommerce's CourierRequestService.
 *
 * Every call that reaches Pigeon Express goes through CourierRequestSubmitter.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Courier;

use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PigeonExpress\Exceptions\AuthenticationException as SdkAuthenticationException;
use PigeonExpress\Exceptions\ConflictException as SdkConflictException;
use PigeonExpress\Exceptions\ConnectionException as SdkConnectionException;
use PigeonExpress\Exceptions\ForbiddenException as SdkForbiddenException;
use PigeonExpress\Exceptions\NotFoundException as SdkNotFoundException;
use PigeonExpress\Exceptions\PigeonExpressException;
use PigeonExpress\Exceptions\RateLimitException as SdkRateLimitException;
use PigeonExpress\Exceptions\ServerException as SdkServerException;
use PigeonExpress\Exceptions\ValidationException as SdkValidationException;
use PigeonExpress\Resources\AbstractResource;
use PigeonExpress\Shipping\Model\Account\SavedAddresses;
use PigeonExpress\Shipping\Model\Magento\OrderComment;
use PigeonExpress\Shipping\Model\Phone\BulgarianPhone;
use PigeonExpress\Shipping\Model\ShipmentCanceller;
use PigeonExpress\Shipping\Model\Status\StatusCodes;
use Psr\Log\LoggerInterface;

class CourierRequestService
{
    public const MAX_SHIPMENTS = 100;

    public const ADDRESS_SAVED = 'saved';
    public const ADDRESS_OTHER = 'other';

    public const TIME_INTERVAL = 'interval';
    public const TIME_SPECIFIC = 'specific_time';

    public const STATUS_CANCELLED = 'courier_request_cancelled';

    /** Carrier limit of the short address parts. */
    private const SHORT_FIELD = 20;

    /** @var CourierRequestSubmitter */
    private $submitter;

    /** @var CourierRequestRepository */
    private $repository;

    /** @var CourierSettings */
    private $settings;

    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var ShipmentCanceller */
    private $shipments;

    /** @var SavedAddresses */
    private $savedAddresses;

    /** @var OrderComment */
    private $comments;

    /** @var TimezoneInterface */
    private $timezone;

    /** @var AuthSession */
    private $authSession;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        CourierRequestSubmitter $submitter,
        CourierRequestRepository $repository,
        CourierSettings $settings,
        OrderRepositoryInterface $orderRepository,
        ShipmentCanceller $shipments,
        SavedAddresses $savedAddresses,
        OrderComment $comments,
        TimezoneInterface $timezone,
        AuthSession $authSession,
        LoggerInterface $logger
    ) {
        $this->submitter = $submitter;
        $this->repository = $repository;
        $this->settings = $settings;
        $this->orderRepository = $orderRepository;
        $this->shipments = $shipments;
        $this->savedAddresses = $savedAddresses;
        $this->comments = $comments;
        $this->timezone = $timezone;
        $this->authSession = $authSession;
        $this->logger = $logger;
    }

    public function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromMutable($this->timezone->date());
    }

    /**
     * The selected orders' shipments a courier can collect, and the others
     * with the reason each is left out.
     *
     * @param int[] $orderIds
     * @return array{shipments: array<int, array{order_id:int, number:string, reference:string, weight:float}>, skipped: array<int, array{order_id:int, number:string, reason:string}>, weight:float}
     */
    public function collect(array $orderIds): array
    {
        $shipments = [];
        $skipped = [];
        $weight = 0.0;
        $seen = [];

        foreach (array_unique(array_map('intval', $orderIds)) as $orderId) {
            try {
                $order = $orderId > 0 ? $this->orderRepository->get($orderId) : null;
            } catch (\Throwable $e) {
                $order = null;
            }
            if ($order === null) {
                $skipped[] = ['order_id' => $orderId, 'number' => (string) $orderId, 'reason' => (string) __('Order not found.')];
                continue;
            }

            $shipment = $this->shipments->findShipment($orderId);
            $live = $shipment !== null && $shipment->getStatus() !== ShipmentCanceller::STATUS_CANCELLED
                && (string) $shipment->getReferenceNumber() !== '';
            $reference = $live ? (string) $shipment->getReferenceNumber() : '';
            $reason = $live ? $this->skipReason($reference, $shipment->getStatusCode()) : $this->noShipmentReason($order);

            if ($reason === '' && isset($seen[$reference])) {
                $reason = (string) __('The same shipment is already in this request.');
            }
            if ($reason === '' && count($shipments) >= self::MAX_SHIPMENTS) {
                $reason = (string) __('One request takes at most %1 shipments.', self::MAX_SHIPMENTS);
            }

            $entry = ['order_id' => $orderId, 'number' => (string) $order->getIncrementId()];
            if ($reason !== '') {
                $skipped[] = $entry + ['reason' => $reason];
                continue;
            }

            $seen[$reference] = true;
            $shipmentWeight = $this->shipmentWeight((string) $shipment->getFormFields());
            $weight += $shipmentWeight;
            $shipments[] = $entry + ['reference' => $reference, 'weight' => $shipmentWeight];
        }

        return ['shipments' => $shipments, 'skipped' => $skipped, 'weight' => round($weight, 3)];
    }

    /**
     * Why a live shipment cannot go in a new request, or '' when it can.
     */
    public function skipReason(string $reference, string $statusCode): string
    {
        $request = $this->repository->forReference($reference);
        if ($request !== null && !CourierRequestRepository::isFinal((string) $request['status_code'])) {
            return (string) __('Already in courier request %1.', (string) $request['request_number']);
        }

        // Only a shipment still waiting to be handed over; an unknown status
        // (never checked) is left to Pigeon Express, which checks it anyway.
        if ($statusCode !== '' && $statusCode !== StatusCodes::REGISTERED) {
            return (string) __(
                'The shipment is no longer waiting to be handed over (status: %1).',
                StatusCodes::knownLabel($statusCode) !== '' ? StatusCodes::knownLabel($statusCode) : $statusCode
            );
        }

        return '';
    }

    private function noShipmentReason(OrderInterface $order): string
    {
        $method = (string) $order->getShippingMethod();

        return strpos($method, 'pigeonexpress_') === 0
            ? (string) __('No Pigeon Express shipment has been created yet.')
            : (string) __('Not shipped with Pigeon Express.');
    }

    private function shipmentWeight(string $formFields): float
    {
        $form = json_decode($formFields, true);
        $weight = 0.0;
        foreach ((is_array($form) ? (array) ($form['packages'] ?? []) : []) as $package) {
            if (is_array($package) && is_numeric($package['weight'] ?? null)) {
                $weight += max(0.0, (float) $package['weight']);
            }
        }
        if ($weight <= 0 && is_array($form) && is_numeric($form['weight'] ?? null)) {
            $weight = (float) $form['weight'];
        }

        return $weight;
    }

    /**
     * Send the request and keep it.
     *
     * @param array<string, mixed> $input Cleaned form values
     * @return array{request: array<string, mixed>, message: string}
     * @throws LocalizedException
     */
    public function create(array $input): array
    {
        if (!$this->settings->isAvailable()) {
            throw new LocalizedException(self::unavailableText());
        }

        $collected = $this->collect((array) ($input['orders'] ?? []));
        if ($collected['skipped'] !== []) {
            $lines = [(string) __('These shipments can no longer be added. Close the form and open it again.')];
            foreach ($collected['skipped'] as $skip) {
                $lines[] = (string) __('Order #%1: %2', $skip['number'], $skip['reason']);
            }
            throw new LocalizedException(__(implode("\n", $lines)));
        }

        $body = $this->body($input, $collected['shipments']);
        $references = array_column($collected['shipments'], 'reference');

        try {
            $answer = $this->submitter->submit($body['fields']);
        } catch (LocalizedException $e) {
            // Ours (the dev block among them): already in the merchant's words.
            throw $e;
        } catch (\Throwable $e) {
            throw new LocalizedException(__($this->explain($e, $references, true)), $e);
        }

        $number = $answer['number'];
        if ($number === '') {
            $this->logger->warning('[PigeonExpress] Courier request create answered without a number');
            throw new LocalizedException(__(
                'Pigeon Express answered without a request number, so it is not known whether the request was made. '
                . 'Check with Pigeon Express before sending it again.'
            ));
        }

        $status = ['code' => $answer['status_code'], 'name' => $answer['status_name']];

        // The answer of an identical request sent less than 30 seconds ago,
        // which may have been cancelled since: read it before keeping it.
        if ($answer['replay'] === AbstractResource::REPLAY_EARLIER) {
            try {
                $current = $this->submitter->get($number);
                $status = ['code' => $current['status_code'], 'name' => $current['status_name']];
            } catch (\Throwable $e) {
                // Kept as answered.
            }
            if ($status['code'] === self::STATUS_CANCELLED) {
                throw new LocalizedException(__(
                    'The same request was cancelled less than half a minute ago. Wait 30 seconds and send it again.'
                ));
            }
        }

        $user = $this->authSession->getUser();
        $request = [
            'number'        => $number,
            'status_code'   => $status['code'],
            'status_name'   => $status['name'],
            'checked_at'    => gmdate('Y-m-d H:i:s'),
            'pickup_date'   => (string) $body['fields']['requested_pickup_date'],
            'time_type'     => (string) $body['fields']['requested_pickup_time_type'],
            'time_from'     => (string) $body['fields']['requested_pickup_time_from'],
            'time_to'       => (string) ($body['fields']['requested_pickup_time_to'] ?? ''),
            'address'       => $body['address'],
            'request_type'  => count($references) > 1 ? 'groupage' : 'single',
            'admin_user_id' => $user ? (int) $user->getId() : null,
            'shipments'     => array_combine($references, array_column($collected['shipments'], 'order_id')) ?: [],
        ];

        try {
            $isNew = $this->repository->save($request);
        } catch (\Throwable $e) {
            // The courier is coming: sending it again would book a second one.
            $this->logger->critical('[PigeonExpress] Courier request ' . $number . ' made but not stored: '
                . $e->getMessage());
            throw new LocalizedException(__(
                'The courier request was created at Pigeon Express as %1 but could not be saved here. '
                . 'Do not send it again; note the number to cancel or follow it in your Pigeon Express account.',
                $number
            ), $e);
        }
        $when = self::when($request);

        if ($isNew) {
            $note = (string) __('Pigeon Express courier requested: %1, pickup %2.', $number, $when);
            foreach ($collected['shipments'] as $shipment) {
                $this->commentOn((int) $shipment['order_id'], $note);
            }
        }

        return [
            'request' => $this->repository->find($number) ?? $request,
            'message' => $isNew
                ? (string) __('Courier requested: %1, pickup %2.', $number, $when)
                : (string) __('This request had already been made: %1.', $number),
        ];
    }

    /**
     * The carrier's body from the form, or what is wrong with the form.
     *
     * @param array<string, mixed> $input
     * @param array<int, array<string, mixed>> $shipments
     * @return array{fields: array<string, mixed>, address: string}
     * @throws LocalizedException
     */
    public function body(array $input, array $shipments): array
    {
        $date = (string) ($input['pickup_date'] ?? '');
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($date === '' || !$day instanceof \DateTimeImmutable || $day->format('Y-m-d') !== $date) {
            throw new LocalizedException(__('Choose the pickup date.'));
        }
        if ($date < $this->now()->format('Y-m-d')) {
            throw new LocalizedException(__('The pickup date cannot be in the past.'));
        }

        $type = (string) ($input['time_type'] ?? '') === self::TIME_SPECIFIC ? self::TIME_SPECIFIC : self::TIME_INTERVAL;
        $from = (string) ($input['time_from'] ?? '');
        $to = (string) ($input['time_to'] ?? '');
        if (!CourierSettings::isClock($from) || ($type === self::TIME_INTERVAL && !CourierSettings::isClock($to))) {
            throw new LocalizedException(__('Enter the pickup time as hours and minutes, for example 10:00.'));
        }
        if ($type === self::TIME_INTERVAL && CourierSettings::minutes($to) <= CourierSettings::minutes($from)) {
            throw new LocalizedException(__('The end of the pickup time must be later than its start.'));
        }

        $address = $this->addressFields($input);
        $fields = $address['fields'];
        $fields['requested_pickup_date'] = $date;
        $fields['requested_pickup_time_type'] = $type;
        $fields['requested_pickup_time_from'] = $from;
        if ($type === self::TIME_INTERVAL) {
            $fields['requested_pickup_time_to'] = $to;
        }

        $phone = trim((string) ($input['contact_phone'] ?? ''));
        $email = trim((string) ($input['contact_email'] ?? ''));
        if ($phone !== '' && !BulgarianPhone::isValid($phone)) {
            throw new LocalizedException(__('Phone "%1" is not a valid Bulgarian number.', $phone));
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new LocalizedException(__('Email "%1" is not a valid email address.', $email));
        }

        // Left out when empty: the carrier then uses the account's details.
        $fields += array_filter([
            'contact_name'  => mb_substr(trim((string) ($input['contact_name'] ?? '')), 0, 255),
            'contact_phone' => mb_substr($phone, 0, 50),
            'contact_email' => $email,
            'company_name'  => mb_substr(trim((string) ($input['company_name'] ?? '')), 0, 255),
        ], 'strlen');

        if ($shipments !== []) {
            $fields['shipment_references'] = array_values(array_column($shipments, 'reference'));
        }

        $packageType = (string) ($input['package_type'] ?? '');
        $fields['package_type'] = in_array($packageType, CourierSettings::PACKAGE_TYPES, true)
            ? $packageType : $this->settings->packageType();

        $weight = (float) ($input['weight'] ?? 0);
        if ($weight < 0) {
            throw new LocalizedException(__('The estimated weight cannot be negative.'));
        }
        if ($weight > 0) {
            $fields['estimated_weight'] = round($weight, 3);
        }

        $dimensions = [
            'length' => (float) ($input['length'] ?? 0),
            'width'  => (float) ($input['width'] ?? 0),
            'height' => (float) ($input['height'] ?? 0),
        ];
        if (min($dimensions) > 0) {
            $fields['estimated_dimensions'] = $dimensions;
        } elseif (max($dimensions) > 0) {
            throw new LocalizedException(__('Enter all three dimensions, or none.'));
        }

        $instructions = trim((string) ($input['instructions'] ?? ''));
        if ($instructions !== '') {
            $fields['special_instructions'] = $instructions;
        }

        return ['fields' => $fields, 'address' => $address['text']];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{fields: array<string, mixed>, text: string}
     * @throws LocalizedException
     */
    private function addressFields(array $input): array
    {
        if ((string) ($input['address_mode'] ?? '') === self::ADDRESS_SAVED) {
            $id = (int) ($input['saved_address_id'] ?? 0);
            if ($id <= 0) {
                throw new LocalizedException(__('Choose a saved address.'));
            }
            $entry = $this->savedAddresses->find($id);
            if ($entry === null) {
                throw new LocalizedException(__(
                    'This saved address is no longer in your Pigeon Express account. Choose another one or enter the address.'
                ));
            }
            $address = (array) $entry['pickup_address'];
            $fields = ['pickup_city_id' => (int) ($address['city_id'] ?? 0)];
            if ((int) ($address['street_id'] ?? 0) > 0) {
                $fields['pickup_street_id'] = (int) $address['street_id'];
            }
            foreach ([
                'pickup_street_number'   => 'street_number',
                'pickup_building_number' => 'building_number',
                'pickup_entrance'        => 'entrance',
                'pickup_floor'           => 'floor',
                'pickup_apartment'       => 'apartment',
                'pickup_neighborhood'    => 'neighborhood',
                'pickup_additional_info' => 'additional_info',
            ] as $field => $key) {
                $value = trim((string) ($address[$key] ?? ''));
                if ($value !== '') {
                    $short = in_array($field, ['pickup_street_number', 'pickup_entrance', 'pickup_floor', 'pickup_apartment'], true);
                    $fields[$field] = $short ? mb_substr($value, 0, self::SHORT_FIELD) : $value;
                }
            }
            if (!isset($fields['pickup_street_id']) && !isset($fields['pickup_additional_info'])) {
                $fields['pickup_additional_info'] = (string) $entry['label'];
            }

            return ['fields' => $fields, 'text' => (string) $entry['label']];
        }

        $cityId = (int) ($input['city_id'] ?? 0);
        $streetId = (int) ($input['street_id'] ?? 0);
        $number = trim((string) ($input['street_number'] ?? ''));
        $info = trim((string) ($input['address_info'] ?? ''));

        if ($cityId <= 0) {
            throw new LocalizedException(__('Choose the pickup city.'));
        }
        if ($streetId <= 0 && $info === '') {
            throw new LocalizedException(__('Choose the pickup street, or describe the address under Address details.'));
        }
        if (mb_strlen($number) > self::SHORT_FIELD) {
            throw new LocalizedException(__(
                'The street number takes at most %1 characters. Put the rest under Address details.',
                self::SHORT_FIELD
            ));
        }

        $fields = ['pickup_city_id' => $cityId];
        if ($streetId > 0) {
            $fields['pickup_street_id'] = $streetId;
        }
        if ($number !== '') {
            $fields['pickup_street_number'] = $number;
        }
        if ($info !== '') {
            $fields['pickup_additional_info'] = $info;
        }

        $text = implode(', ', array_filter([
            trim((string) ($input['city_label'] ?? '')),
            trim(($streetId > 0 ? trim((string) ($input['street_label'] ?? '')) : '') . ' ' . $number),
            $info,
        ], 'strlen'));

        return ['fields' => $fields, 'text' => $text];
    }

    /**
     * Cancel a request kept here.
     *
     * @return array{request: array<string, mixed>, message: string}
     * @throws LocalizedException
     */
    public function cancel(string $number): array
    {
        $request = $this->repository->find($number);
        if ($request === null) {
            throw new LocalizedException(__('This courier request is not in the list.'));
        }

        try {
            $answer = $this->submitter->cancel($number);
        } catch (LocalizedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Refused because it is over already: say what it became.
            if ($e instanceof SdkValidationException) {
                try {
                    $read = $this->refresh($number);
                    if (CourierRequestRepository::isFinal((string) $read['request']['status_code'])) {
                        return [
                            'request' => $read['request'],
                            'message' => (string) __(
                                'Request %1 cannot be cancelled any more: %2.',
                                $number,
                                self::statusLabel($read['request'])
                            ),
                        ];
                    }
                } catch (\Throwable $ignored) {
                    // Fall through to the refusal.
                }
            }
            throw new LocalizedException(__($this->explain($e, [], false)), $e);
        }

        $this->repository->updateStatus($number, [
            'status_code' => $answer['status_code'] !== '' ? $answer['status_code'] : self::STATUS_CANCELLED,
            'status_name' => $answer['status_name'],
            'checked_at'  => gmdate('Y-m-d H:i:s'),
        ]);

        $note = (string) __('Pigeon Express courier request %1 cancelled.', $number);
        foreach (array_unique($request['shipments']) as $orderId) {
            $this->commentOn((int) $orderId, $note);
        }

        return [
            'request' => $this->repository->find($number) ?? $request,
            'message' => (string) __('Courier request %1 cancelled.', $number),
        ];
    }

    /**
     * Read a request's status from Pigeon Express.
     *
     * @return array{request: array<string, mixed>, message: string}
     * @throws LocalizedException
     */
    public function refresh(string $number): array
    {
        if ($this->repository->find($number) === null) {
            throw new LocalizedException(__('This courier request is not in the list.'));
        }

        try {
            $answer = $this->submitter->get($number);
        } catch (LocalizedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new LocalizedException(__($this->explain($e, [], false)), $e);
        }

        $data = ['checked_at' => gmdate('Y-m-d H:i:s')];
        if ($answer['status_code'] !== '') {
            $data['status_code'] = $answer['status_code'];
            $data['status_name'] = $answer['status_name'];
        }
        $this->repository->updateStatus($number, $data);
        $request = $this->repository->find($number) ?? [];

        return [
            'request' => $request,
            'message' => (string) __('Request %1: %2.', $number, self::statusLabel($request)),
        ];
    }

    private function commentOn(int $orderId, string $note): void
    {
        try {
            $this->comments->add($this->orderRepository->get($orderId), $note);
        } catch (\Throwable $e) {
            $this->logger->warning('[PigeonExpress] No courier comment on order ' . $orderId . ': ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $request
     */
    public static function statusLabel(array $request): string
    {
        $labels = [
            'courier_request_pending'         => __('New request'),
            'courier_request_assigned'        => __('Courier assigned'),
            'courier_request_in_progress'     => __('In progress'),
            'courier_request_completed'       => __('Completed'),
            'courier_request_cancelled'       => __('Cancelled'),
            'courier_request_failed'          => __('Request failed'),
            'courier_request_no_zone_courier' => __('No courier for this area'),
        ];
        $code = (string) ($request['status_code'] ?? '');
        if (isset($labels[$code])) {
            return (string) $labels[$code];
        }
        $name = (string) ($request['status_name'] ?? '');

        return $name !== '' ? $name : (string) __('Unknown status');
    }

    /**
     * A tone of the panel's status colours.
     *
     * @param array<string, mixed> $request
     */
    public static function statusTone(array $request): string
    {
        switch ((string) ($request['status_code'] ?? '')) {
            case 'courier_request_completed':
                return StatusCodes::TONE_DELIVERED;
            case 'courier_request_cancelled':
            case 'courier_request_failed':
                return StatusCodes::TONE_FAILED;
            case 'courier_request_no_zone_courier':
                return StatusCodes::TONE_ATTENTION;
        }

        return StatusCodes::TONE_TRANSIT;
    }

    /**
     * Pickup date and time: "13.11.2026, 10:00 - 17:00".
     *
     * @param array<string, mixed> $request
     */
    public static function when(array $request): string
    {
        $date = (string) ($request['pickup_date'] ?? '');
        $day = $date !== '' ? \DateTimeImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10)) : false;
        $from = substr((string) ($request['time_from'] ?? ''), 0, 5);
        $to = substr((string) ($request['time_to'] ?? ''), 0, 5);
        $time = ((string) ($request['time_type'] ?? '') === self::TIME_SPECIFIC || $to === '')
            ? (string) __('at %1', $from)
            : $from . ' - ' . $to;

        return trim(($day ? $day->format('d.m.Y') : '') . ', ' . $time, ', ');
    }

    public static function unavailableText(): \Magento\Framework\Phrase
    {
        return __('Courier requests are for shops the courier collects from. Set "Pickup Type" under Send From in the Pigeon Express settings to an address.');
    }

    /**
     * A refusal or failure, said the way a merchant reads it.
     *
     * @param string[] $references The shipments sent, to name the one an error is about
     * @param bool $isCreate An unanswered create may still have been made
     */
    public function explain(\Throwable $error, array $references, bool $isCreate): string
    {
        $this->logger->warning('[PigeonExpress] Courier request failed: ' . get_class($error) . ': ' . $error->getMessage());

        if ($error instanceof SdkConflictException) {
            return (string) __('Pigeon Express is still processing the same request. Wait a few seconds and send it again: the same request is not made twice.');
        }
        if ($error instanceof SdkRateLimitException) {
            return (string) __('Too many requests to Pigeon Express. Wait a moment and try again.');
        }
        if ($error instanceof SdkAuthenticationException || $error instanceof SdkForbiddenException) {
            return (string) __('Pigeon Express rejected the credentials. Check them in the Pigeon Express settings.');
        }
        if ($error instanceof SdkNotFoundException) {
            return (string) __('Pigeon Express does not know this courier request. It may have been made with other credentials or in the other environment.');
        }
        if ($error instanceof SdkValidationException) {
            $lines = [];
            foreach ((array) $error->getErrors() as $key => $messages) {
                $text = trim(strip_tags(is_array($messages) ? implode(' ', array_map('strval', $messages)) : (string) $messages));
                if ($text === '') {
                    continue;
                }
                $label = is_string($key) ? $this->fieldLabel($key, $references) : '';
                $lines[] = $label !== '' ? $label . ': ' . $text : $text;
            }
            if ($lines !== []) {
                return implode("\n", $lines);
            }
            $message = trim(strip_tags((string) $error->getMessage()));

            return $message !== '' ? $message : (string) __('Pigeon Express refused the request.');
        }
        if ($error instanceof SdkConnectionException || $error instanceof SdkServerException
            || !$error instanceof PigeonExpressException
        ) {
            return $isCreate
                ? (string) __('Pigeon Express did not confirm the request. Send it again within half a minute: no second request is made.')
                : (string) __('Could not reach Pigeon Express. Try again.');
        }

        $message = trim(strip_tags((string) $error->getMessage()));

        return $message !== '' ? $message : (string) __('Could not reach Pigeon Express. Try again.');
    }

    /**
     * @param string[] $references
     */
    private function fieldLabel(string $key, array $references): string
    {
        if (preg_match('/^shipment_references\.(\d+)/', $key, $match)) {
            return (string) __('Shipment %1', $references[(int) $match[1]] ?? (string) ((int) $match[1] + 1));
        }
        if (in_array($key, $references, true)) {
            return (string) __('Shipment %1', $key);
        }
        $field = explode('.', $key)[0];
        if (strpos($field, 'requested_pickup_time') === 0) {
            return (string) __('Pickup time');
        }
        if (strpos($field, 'pickup_') === 0) {
            return (string) __('Pickup address');
        }
        if (strpos($field, 'estimated_dimensions') === 0) {
            return (string) __('Dimensions');
        }
        $labels = [
            'requested_pickup_date'      => __('Pickup date'),
            'contact_name'               => __('Contact person'),
            'contact_phone'              => __('Phone'),
            'contact_phone_country_code' => __('Phone'),
            'contact_email'              => __('Email'),
            'company_name'               => __('Company'),
            'shipment_references'        => __('Shipments to collect'),
            'package_type'               => __('Package type'),
            'estimated_weight'           => __('Estimated weight'),
            'special_instructions'       => __('Instructions for the courier'),
        ];

        return isset($labels[$field]) ? (string) $labels[$field] : '';
    }
}
