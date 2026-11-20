<?php
/**
 * Quick changes to a shipment the courier already has: another delivery place,
 * services added or removed, the cash on delivery, the declared value. Each
 * changes only its own fields, unlike Edit, which sends the whole shipment
 * again and works only while it is newly registered.
 *
 * Copied from the WooCommerce plugin (Labels/QuickActions.php). What is
 * allowed is Pigeon Express's call, asked when the panel opens the changes
 * (GET /actions); every change is priced first (a dry run) and made only on
 * the merchant's confirmation, under the order's lock (scope "send", the one
 * of create, edit and cancel).
 *
 * A change of delivery place after registration is a REDIRECT: the carrier
 * makes a new shipment with a new number. The order then follows the new
 * shipment (a new pigeonexpress_shipment row, see
 * Model\Shipment\RedirectFollower), and the customer is always emailed the new
 * number, once.
 *
 * Offices and lockers are picked in the panel by their row in the synced list
 * (entity_id); the carrier's id (api_id) is what is sent, looked up in the
 * current environment's list.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model;

use Magento\Framework\Phrase;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Sales\Api\Data\OrderInterface;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Api\LocationSearchInterface;
use PigeonExpress\Shipping\Api\ShipmentClientInterface;
use PigeonExpress\Shipping\Exception\ApiException;
use PigeonExpress\Shipping\Exception\OutcomeUnknownException;
use PigeonExpress\Shipping\Exception\RateLimitedException;
use PigeonExpress\Shipping\Model\Api\AddressResolver;
use PigeonExpress\Shipping\Model\Api\ShipmentClient;
use PigeonExpress\Shipping\Model\Magento\OrderComment;
use PigeonExpress\Shipping\Model\QuickActions\QuickActionException;
use PigeonExpress\Shipping\Model\Rate\LocationToApiIdResolver;
use PigeonExpress\Shipping\Model\ResourceModel\Shipment as ShipmentResource;
use PigeonExpress\Shipping\Model\Service\ServiceCodesBuilder;
use PigeonExpress\Shipping\Model\Shipment\OrderLock;
use PigeonExpress\Shipping\Model\Shipment\RedirectFollower;
use PigeonExpress\Shipping\Model\Status\ShipmentDetailsStore;
use PigeonExpress\Shipping\Model\Status\StatusCodes;
use Psr\Log\LoggerInterface;

class QuickActions
{
    public const CHANGE_DELIVERY = 'change_delivery';
    public const SERVICES = 'services';
    public const COD = 'cod';
    public const DECLARED_VALUE = 'declared_value';

    /** The four, in the carrier's spelling. */
    public const ACTIONS = [self::CHANGE_DELIVERY, self::SERVICES, self::COD, self::DECLARED_VALUE];

    /** The carrier's delivery types for a change of place. */
    public const DELIVERY_TYPES = ['office', 'locker', 'address'];

    /** Cash on delivery the carrier takes, in euro. */
    public const COD_MIN = 0.01;
    public const COD_MAX = 5000.0;

    /** The lock of create, edit and cancel. */
    private const LOCK_SCOPE = 'send';

    /** @var ShipmentClientInterface */
    private $client;

    /** @var ShipmentCanceller */
    private $finder;

    /** @var ShipmentDetailsStore */
    private $details;

    /** @var OrderLock */
    private $lock;

    /** @var RedirectFollower */
    private $follower;

    /** @var LocationToApiIdResolver */
    private $locations;

    /** @var LocationSearchInterface */
    private $locationSearch;

    /** @var AddressResolver */
    private $addressResolver;

    /** @var AdditionalServiceRepository */
    private $services;

    /** @var OrderComment */
    private $comments;

    /** @var ShipmentResource */
    private $resource;

    /** @var PriceCurrencyInterface */
    private $priceCurrency;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        ShipmentClientInterface $client,
        ShipmentCanceller $finder,
        ShipmentDetailsStore $details,
        OrderLock $lock,
        RedirectFollower $follower,
        LocationToApiIdResolver $locations,
        LocationSearchInterface $locationSearch,
        AddressResolver $addressResolver,
        AdditionalServiceRepository $services,
        OrderComment $comments,
        ShipmentResource $resource,
        PriceCurrencyInterface $priceCurrency,
        LoggerInterface $logger
    ) {
        $this->client = $client;
        $this->finder = $finder;
        $this->details = $details;
        $this->lock = $lock;
        $this->follower = $follower;
        $this->locations = $locations;
        $this->locationSearch = $locationSearch;
        $this->addressResolver = $addressResolver;
        $this->services = $services;
        $this->comments = $comments;
        $this->resource = $resource;
        $this->priceCurrency = $priceCurrency;
        $this->logger = $logger;
    }

    /**
     * Whether the order panel offers quick changes: a shipment Edit can no
     * longer change (the courier has it) that has not ended and was not
     * redirected. Which of the changes are possible is the carrier's answer
     * when they are opened.
     *
     * @param string $editBlockReason ShipmentEditor::editBlockReason() for the row
     */
    public static function isOffered(?Shipment $row, string $editBlockReason): bool
    {
        if ($row === null || !$row->getId() || trim((string) $row->getReferenceNumber()) === ''
            || $editBlockReason === ''
        ) {
            return false;
        }

        if (in_array($row->getStatus(), [ShipmentCanceller::STATUS_CANCELLED, Shipment::STATUS_REDIRECTED], true)) {
            return false;
        }

        $code = $row->getStatusCode();

        return !StatusCodes::isFinal($code) && $code !== StatusCodes::REDIRECTED;
    }

    /**
     * The four changes, by the panel's names for them.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::CHANGE_DELIVERY => (string) __('Change the delivery place'),
            self::SERVICES        => (string) __('Add or remove services'),
            self::COD             => (string) __('Change the cash on delivery'),
            self::DECLARED_VALUE  => (string) __('Change the declared value'),
        ];
    }

    /**
     * What Pigeon Express allows on the order's shipment now, shaped for the
     * panel's forms (see view()).
     *
     * @return array<string, mixed>
     * @throws QuickActionException
     */
    public function options(OrderInterface $order, Shipment $row): array
    {
        $reference = (string) $row->getReferenceNumber();

        try {
            $data = $this->client->actions($reference, (int) $order->getStoreId());
        } catch (ApiException $e) {
            throw new QuickActionException(new Phrase('%1', [$this->errorMessage($e, true)]), false, $e);
        }

        if ($data === []) {
            throw new QuickActionException(__('Pigeon Express did not say what can be changed on this shipment.'));
        }

        return $this->view($order, $row, $data);
    }

    /**
     * The carrier's GET /actions answer as the panel draws it: per action
     * whether it is allowed and why not, and each form's starting values
     * from `current`.
     *
     * @param array<string, mixed> $data The carrier's `data`
     * @return array{reference: string, active_reference: string, currency: string, actions: array<string, array{label: string, allowed: bool, reason: string}>, prefill: array<string, mixed>, add: array<int, array{code: string, name: string}>, remove: array<int, array{code: string, name: string}>}
     */
    public function view(OrderInterface $order, Shipment $row, array $data): array
    {
        $labels = self::labels();
        $actions = [];

        foreach (self::ACTIONS as $key) {
            $state = isset($data['actions'][$key]) && is_array($data['actions'][$key]) ? $data['actions'][$key] : [];

            $actions[$key] = [
                'label'   => $labels[$key],
                'allowed' => self::flag($state['allowed'] ?? false),
                'reason'  => is_scalar($state['reason'] ?? null) ? trim(strip_tags((string) $state['reason'])) : '',
            ];
        }

        $current = isset($data['current']) && is_array($data['current']) ? $data['current'] : [];
        $type = (string) ($current['delivery_type'] ?? '');
        $type = in_array($type, self::DELIVERY_TYPES, true) ? $type : 'office';
        $apiId = is_numeric($current['delivery_office_id'] ?? null) ? (int) $current['delivery_office_id'] : 0;
        $address = isset($current['delivery_address']) && is_array($current['delivery_address'])
            ? $current['delivery_address'] : [];
        $cityId = (int) ($address['city_id'] ?? 0);
        $streetId = (int) ($address['street_id'] ?? 0);
        $sent = json_decode((string) $row->getFormFields(), true);
        $sent = is_array($sent) ? $sent : [];

        $location = $type !== 'address' && $apiId > 0 ? $this->locationByApiId($type, $apiId, (int) $order->getStoreId()) : null;
        $city = $cityId > 0 ? $this->addressResolver->getCityById($cityId) : null;

        $prefill = [
            'delivery_type'   => $type,
            'location_id'     => $location !== null ? (string) $location['id'] : '',
            'location_label'  => $location !== null ? $location['label'] : '',
            'city_id'         => $cityId,
            'city_label'      => $city !== null ? (string) $city['name'] : '',
            'street_id'       => $streetId,
            // The name the panel showed when the shipment was sent to this
            // street; the carrier gives ids only.
            'street_label'    => $streetId > 0 && (int) ($sent['street_id'] ?? 0) === $streetId
                ? trim((string) ($sent['street'] ?? '')) : '',
            'additional_info' => self::addressLine($address),
            'cod_amount'      => is_numeric($current['cod_amount'] ?? null) ? (float) $current['cod_amount'] : null,
            'cod_card'        => self::flag($current['allow_cod_card_payment'] ?? false),
            'declared_value'  => is_numeric($current['declared_value'] ?? null) ? (float) $current['declared_value'] : null,
        ];

        $currency = is_scalar($data['pricing']['currency'] ?? null) ? (string) $data['pricing']['currency'] : '';

        return [
            'reference'        => (string) $row->getReferenceNumber(),
            'active_reference' => RedirectFollower::cleanReference($data['active_reference_number'] ?? ''),
            'currency'         => self::currency($currency, (string) $order->getOrderCurrencyCode()),
            'actions'          => $actions,
            'prefill'          => $prefill,
            'add'              => $this->serviceList($data['addable_services'] ?? []),
            'remove'           => $this->serviceList($data['removable_services'] ?? []),
        ];
    }

    /**
     * The body of one action from the panel's fields, checked first for what
     * the panel can tell without asking the carrier.
     *
     * @param array<string, mixed> $fields delivery_type, location_id (entity_id), location_label,
     *                                     city_id, city_label, street_id, street_label,
     *                                     additional_info, add, remove, cod_amount,
     *                                     allow_cod_card_payment, declared_value
     * @return array<string, mixed>
     * @throws QuickActionException
     */
    public function bodyFor(string $action, array $fields): array
    {
        switch ($action) {
            case self::CHANGE_DELIVERY:
                return $this->deliveryBody($fields);

            case self::SERVICES:
                $add = self::codes($fields['add'] ?? []);
                $remove = self::codes($fields['remove'] ?? []);

                if ($add === [] && $remove === []) {
                    throw new QuickActionException(__('Tick at least one service to add or remove.'));
                }

                $body = [];
                if ($add !== []) {
                    $body['add'] = $add;
                }
                if ($remove !== []) {
                    $body['remove'] = $remove;
                }

                return $body;

            case self::COD:
                $amount = self::amount($fields['cod_amount'] ?? '');

                // Empty removes the cash on delivery; its card payment goes with it.
                if ($amount === null) {
                    return ['cod_amount' => null];
                }

                if ($amount < self::COD_MIN || $amount > self::COD_MAX) {
                    throw new QuickActionException(__(
                        'Cash on delivery: enter an amount from %1 to %2 €.',
                        number_format(self::COD_MIN, 2, ',', ''),
                        number_format(self::COD_MAX, 0, ',', ' ')
                    ));
                }

                return [
                    'cod_amount'             => round($amount, 2),
                    'allow_cod_card_payment' => !empty($fields['allow_cod_card_payment']),
                ];

            case self::DECLARED_VALUE:
                $amount = self::amount($fields['declared_value'] ?? '');

                if ($amount === null) {
                    return ['declared_value' => null];
                }

                if ($amount < ServiceCodesBuilder::DECLARED_VALUE_MIN || $amount > ServiceCodesBuilder::DECLARED_VALUE_MAX) {
                    throw new QuickActionException(__(
                        'Declared value: enter an amount from %1 to %2 €.',
                        number_format(ServiceCodesBuilder::DECLARED_VALUE_MIN, 0, ',', ' '),
                        number_format(ServiceCodesBuilder::DECLARED_VALUE_MAX, 0, ',', ' ')
                    ));
                }

                return ['declared_value' => round($amount, 2)];
        }

        throw new QuickActionException(__('Pigeon Express does not offer this change.'));
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     * @throws QuickActionException
     */
    private function deliveryBody(array $fields): array
    {
        $type = (string) ($fields['delivery_type'] ?? '');

        if ($type === 'office' || $type === 'locker') {
            $id = (string) preg_replace('/\D/', '', (string) ($fields['location_id'] ?? ''));
            $row = $id !== '' ? $this->locations->resolveLocation(self::moduleType($type), $id) : null;

            if ($row === null || (int) $row['api_id'] <= 0) {
                throw new QuickActionException($type === 'locker'
                    ? __('Pick the parcel locker to deliver to.')
                    : __('Pick the office to deliver to.'));
            }

            return [
                'delivery_type'      => $type,
                'delivery_office_id' => (int) $row['api_id'],
            ];
        }

        if ($type !== 'address') {
            throw new QuickActionException(__('Choose where the shipment should go.'));
        }

        $cityId = (int) ($fields['city_id'] ?? 0);
        $streetId = (int) ($fields['street_id'] ?? 0);
        $info = trim((string) ($fields['additional_info'] ?? ''));

        if ($cityId <= 0) {
            throw new QuickActionException(__('Pick the city of the new address.'));
        }

        if ($info === '') {
            throw new QuickActionException(__('Enter the house number (and building / entrance / floor if any).'));
        }

        // Without a street from the list the line has to name it, as at create.
        if ($streetId <= 0 && mb_strlen($info) < 3) {
            throw new QuickActionException(__(
                'With no street picked from the list, enter the street and the house number in the address line.'
            ));
        }

        $address = ['city_id' => $cityId, 'additional_info' => $info];
        if ($streetId > 0) {
            $address['street_id'] = $streetId;
        }

        return ['delivery_type' => 'address', 'delivery_address' => $address];
    }

    /**
     * Price one change (a dry run, nothing changes) or make it.
     *
     * @param array<string, mixed> $fields See bodyFor()
     * @param string $reference The shipment the form was opened for: a change is never sent for another one
     * @return array{price: array<string, mixed>, removed: string[], message: string, reference: string}
     * @throws QuickActionException
     */
    public function run(OrderInterface $order, string $action, array $fields, bool $dryRun, string $reference): array
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new QuickActionException(__('Pigeon Express does not offer this change.'));
        }

        $body = $this->bodyFor($action, $fields);
        $orderId = (int) $order->getEntityId();
        $storeId = (int) $order->getStoreId();
        $reference = RedirectFollower::cleanReference($reference);

        if ($dryRun) {
            if ($reference === '' || $this->heldReference($orderId) !== $reference) {
                throw self::changedMeanwhile();
            }

            try {
                $answer = $this->client->runAction($reference, $action, $body, true, $storeId);
            } catch (ApiException $e) {
                throw new QuickActionException(
                    new Phrase('%1', [$this->errorMessage($e, true, self::currency('', (string) $order->getOrderCurrencyCode()))]),
                    false,
                    $e
                );
            }

            $data = is_array($answer['data'] ?? null) ? $answer['data'] : [];

            return [
                'price'     => isset($data['price']) && is_array($data['price']) ? $data['price'] : [],
                'removed'   => self::codes($data['result']['removed_services'] ?? []),
                'message'   => '',
                'reference' => $reference,
            ];
        }

        // The lock of create, edit and cancel: a change racing any of them,
        // or a second click, would send two requests about one shipment.
        if (!$this->lock->acquire($orderId, self::LOCK_SCOPE)) {
            throw new QuickActionException(__(
                'The Pigeon Express shipment of this order is being created or changed right now. '
                . 'Reload the order in a moment.'
            ));
        }

        try {
            return $this->runLocked($order, $action, $body, $fields, $reference);
        } finally {
            $this->lock->release($orderId, self::LOCK_SCOPE);
        }
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $fields
     * @return array{price: array<string, mixed>, removed: string[], message: string, reference: string}
     * @throws QuickActionException
     */
    private function runLocked(OrderInterface $order, string $action, array $body, array $fields, string $reference): array
    {
        $orderId = (int) $order->getEntityId();
        $storeId = (int) $order->getStoreId();
        $row = $this->finder->findShipment($orderId);

        // Above all after a redirect: the order holds the new shipment, and the
        // same change sent again for the old one must not move anything.
        if ($reference === '' || $row === null || (string) $row->getReferenceNumber() !== $reference
            || $row->getStatus() === ShipmentCanceller::STATUS_CANCELLED
        ) {
            throw self::changedMeanwhile();
        }

        // The values before the change, for the comment. Not needed for the
        // change itself, so a failure here only leaves the comment shorter.
        try {
            $before = $this->client->actions($reference, $storeId);
        } catch (\Throwable $e) {
            $before = [];
        }

        try {
            $answer = $this->client->runAction($reference, $action, $body, false, $storeId);
        } catch (ApiException $e) {
            if ($action === self::CHANGE_DELIVERY && $e->getApiErrorCode() === 'already_redirected') {
                $adopted = $this->adoptRedirect($order, $row, $e, $before, $body, $fields);
                if ($adopted !== null) {
                    return $adopted;
                }
            }

            throw new QuickActionException(
                new Phrase('%1', [$this->errorMessage($e, false, self::currency('', (string) $order->getOrderCurrencyCode()))]),
                $e instanceof OutcomeUnknownException,
                $e
            );
        }

        $data = is_array($answer['data'] ?? null) ? $answer['data'] : [];
        $replay = (string) ($answer['replay'] ?? ShipmentClient::REPLAY_NONE);
        $newReference = RedirectFollower::cleanReference(
            (string) ($answer['new_reference_number'] ?? '') !== ''
                ? $answer['new_reference_number']
                : ($data['result']['new_reference_number'] ?? '')
        );
        $lines = $this->describe($action, $body, $fields, $before, $data, (string) $order->getOrderCurrencyCode());

        if ($newReference !== '' && $newReference !== $reference) {
            return $this->afterRedirect($order, $row, $newReference, $action, $body, $fields, $lines, $replay, $data);
        }

        return $this->afterChange($order, $row, $action, $body, $fields, $lines, $replay, $data);
    }

    /**
     * A change of place refused as `already_redirected` while the order still
     * holds the old shipment: an earlier attempt made the redirect, but its
     * answer never arrived, so the order was not moved. It moves now to the
     * active shipment as after a confirmed redirect, with the same once-only
     * email.
     *
     * The active number is the carrier's: the refusal's
     * `active_reference_number`, else the one GET /actions gave just before,
     * else the `redirected` links from the old shipment.
     *
     * @param array<string, mixed> $before GET /actions `data` read just before, or []
     * @param array<string, mixed> $body
     * @param array<string, mixed> $fields
     * @return array{price: array<string, mixed>, removed: string[], message: string, reference: string}|null
     *         Null when the active shipment is not known; the refusal is shown then
     * @throws QuickActionException
     */
    private function adoptRedirect(
        OrderInterface $order,
        Shipment $row,
        ApiException $error,
        array $before,
        array $body,
        array $fields
    ): ?array {
        $storeId = (int) $order->getStoreId();
        $reference = (string) $row->getReferenceNumber();
        $active = RedirectFollower::cleanReference($error->getApiErrorData()['active_reference_number'] ?? '');

        if ($active === '') {
            $active = RedirectFollower::cleanReference($before['active_reference_number'] ?? '');
        }
        if ($active === '') {
            $active = $this->follower->activeRedirect($reference, [], $storeId)['reference'];
        }
        if ($active === '' || $active === $reference) {
            return null;
        }

        $this->logger->info('[PigeonExpress] Change of place for ' . $reference . ': already redirected to '
            . $active . ' by an earlier attempt; the order follows it');

        // Where the active shipment really goes. Any change of place gets this
        // refusal once a shipment is redirected, not only an identical one, so
        // it may go somewhere else than what was just asked for.
        $actual = $this->actualDestination($active, $storeId);

        if ($actual !== null && self::sameDestination($body, $actual)) {
            $lines = $this->describe(self::CHANGE_DELIVERY, $body, $fields, $before, [], (string) $order->getOrderCurrencyCode());
            $lines[] = (string) __('Pigeon Express had made this change on an earlier attempt whose answer did not arrive.');

            return $this->afterRedirect(
                $order,
                $row,
                $active,
                self::CHANGE_DELIVERY,
                $body,
                $fields,
                $lines,
                ShipmentClient::REPLAY_NONE,
                []
            );
        }

        if ($actual === null) {
            // Not readable now: the order follows the shipment, and what it
            // was sent with is left as it was until it is read.
            $lines = [
                (string) __('Pigeon Express had already redirected this shipment on an earlier attempt whose answer did not arrive.'),
                (string) __('The destination of the new shipment could not be read now, so the destination shown for the order may be out of date until the shipment is read again.'),
            ];

            return $this->afterRedirect($order, $row, $active, '', [], [], $lines, ShipmentClient::REPLAY_NONE, []);
        }

        $where = $this->destinationText($actual, $storeId);
        $lines = [
            (string) __('Pigeon Express had already redirected this shipment on an earlier attempt whose answer did not arrive, to another place than the one chosen now. The change chosen now was not made.'),
            (string) __('The shipment goes to: %1', $where),
        ];
        $result = $this->afterRedirect(
            $order,
            $row,
            $active,
            self::CHANGE_DELIVERY,
            $actual,
            [],
            $lines,
            ShipmentClient::REPLAY_NONE,
            []
        );
        $result['message'] = (string) __(
            'The shipment had already been redirected to another place than the one you chose (%1), so the change you chose was not made. %2',
            $where,
            $result['message']
        );

        return $result;
    }

    /**
     * Where a shipment goes now, from GET /actions `current`, as a change of
     * place body (see bodyFor()), or null when it cannot be read.
     *
     * @return array<string, mixed>|null
     */
    private function actualDestination(string $reference, int $storeId): ?array
    {
        try {
            $data = $this->client->actions($reference, $storeId);
        } catch (\Throwable $e) {
            return null;
        }

        $current = isset($data['current']) && is_array($data['current']) ? $data['current'] : [];
        $type = (string) ($current['delivery_type'] ?? '');

        if (!in_array($type, self::DELIVERY_TYPES, true)) {
            return null;
        }

        if ($type === 'address') {
            $address = isset($current['delivery_address']) && is_array($current['delivery_address'])
                ? $current['delivery_address'] : [];
            $cityId = (int) ($address['city_id'] ?? 0);

            if ($cityId <= 0) {
                return null;
            }

            $out = ['city_id' => $cityId, 'additional_info' => self::addressLine($address)];
            if ((int) ($address['street_id'] ?? 0) > 0) {
                $out['street_id'] = (int) $address['street_id'];
            }

            return ['delivery_type' => 'address', 'delivery_address' => $out];
        }

        if (!is_numeric($current['delivery_office_id'] ?? null) || (int) $current['delivery_office_id'] <= 0) {
            return null;
        }

        return ['delivery_type' => $type, 'delivery_office_id' => (int) $current['delivery_office_id']];
    }

    /**
     * Whether two change-of-place bodies name the same place.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public static function sameDestination(array $a, array $b): bool
    {
        if ((string) ($a['delivery_type'] ?? '') !== (string) ($b['delivery_type'] ?? '')) {
            return false;
        }

        if (($a['delivery_type'] ?? '') === 'address') {
            $x = (array) ($a['delivery_address'] ?? []);
            $y = (array) ($b['delivery_address'] ?? []);

            return (int) ($x['city_id'] ?? 0) === (int) ($y['city_id'] ?? 0)
                && (int) ($x['street_id'] ?? 0) === (int) ($y['street_id'] ?? 0);
        }

        return (int) ($a['delivery_office_id'] ?? 0) === (int) ($b['delivery_office_id'] ?? 0);
    }

    /**
     * A change-of-place body as the merchant reads it.
     *
     * @param array<string, mixed> $body
     */
    private function destinationText(array $body, int $storeId): string
    {
        if (($body['delivery_type'] ?? '') === 'address') {
            $address = (array) ($body['delivery_address'] ?? []);
            $line = trim((string) ($address['additional_info'] ?? ''));

            return $line !== '' ? $line : (string) __('a delivery address');
        }

        return $this->apiLocationLabel((string) ($body['delivery_type'] ?? ''), (int) ($body['delivery_office_id'] ?? 0), $storeId);
    }

    /**
     * A change made on the same shipment: its details read again, the record
     * of what it was sent with brought up to date, and a private comment.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $fields
     * @param string[] $lines
     * @param array<string, mixed> $data The carrier's answer
     * @return array{price: array<string, mixed>, removed: string[], message: string, reference: string}
     * @throws QuickActionException
     */
    private function afterChange(
        OrderInterface $order,
        Shipment $row,
        string $action,
        array $body,
        array $fields,
        array $lines,
        string $replay,
        array $data
    ): array {
        $reference = (string) $row->getReferenceNumber();
        $details = $this->shipmentData($reference, (int) $order->getStoreId());
        $fresh = $this->finder->findShipment((int) $order->getEntityId());

        if ($fresh === null || (string) $fresh->getReferenceNumber() !== $reference) {
            throw self::changedMeanwhile();
        }

        // The carrier answered with the result of an identical change sent
        // less than 30 seconds ago, without acting. The shipment may have
        // changed in between, so only what it is now is kept.
        if ($replay === ShipmentClient::REPLAY_EARLIER) {
            $this->logger->info('[PigeonExpress] Quick change ' . $action . ' of ' . $reference
                . ' was a replay of an earlier identical change; not recorded');
            $this->remember($fresh, $details);

            throw new QuickActionException(__(
                'Pigeon Express answered with the result of the same change sent less than half a minute ago, so it '
                . 'was not made again. Check the shipment below; if it is not as you want, wait 30 seconds and try again.'
            ), true);
        }

        $this->recordSent($fresh, $action, $body, $fields);
        $this->remember($fresh, $details);

        $lines[] = (string) __('The new label is fetched from Pigeon Express when you open or download it.');
        $this->comments->add($order, (string) __(
            'Pigeon Express shipment %1 was changed. The waybill number stays the same.',
            $reference
        ) . "\n" . implode("\n", $lines));

        return [
            'price'     => isset($data['price']) && is_array($data['price']) ? $data['price'] : [],
            'removed'   => self::codes($data['result']['removed_services'] ?? []),
            'message'   => (string) __('The shipment was changed. The waybill number is the same; print the new label.'),
            'reference' => $reference,
        ];
    }

    /**
     * A change of place that made a new shipment: the order moves to it, with
     * what the change sent kept as what the shipment was sent with. Not again
     * when the order holds the new number already. An answer that is the
     * carrier's replay of an identical change sent earlier moves it too while
     * it still holds the old number: the earlier answer never arrived.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $fields
     * @param string[] $lines
     * @param array<string, mixed> $data The carrier's answer
     * @return array{price: array<string, mixed>, removed: string[], message: string, reference: string}
     * @throws QuickActionException
     */
    private function afterRedirect(
        OrderInterface $order,
        Shipment $row,
        string $newReference,
        string $action,
        array $body,
        array $fields,
        array $lines,
        string $replay,
        array $data
    ): array {
        $reference = (string) $row->getReferenceNumber();
        $storeId = (int) $order->getStoreId();
        $fresh = $this->finder->findShipment((int) $order->getEntityId());
        $held = $fresh !== null ? (string) $fresh->getReferenceNumber() : '';
        $price = isset($data['price']) && is_array($data['price']) ? $data['price'] : [];

        if ($held === $newReference) {
            return [
                'price'     => $price,
                'removed'   => [],
                'message'   => (string) __(
                    'Pigeon Express had already made this change a moment ago; the new shipment is %1. The order was not changed again.',
                    $newReference
                ),
                'reference' => $held,
            ];
        }

        if ($fresh === null || $held !== $reference) {
            throw self::changedMeanwhile();
        }

        if ($replay === ShipmentClient::REPLAY_EARLIER) {
            $lines[] = (string) __('Pigeon Express had made this change on an earlier attempt whose answer did not arrive.');
        }

        $target = $this->follower->readableTarget($newReference, [], $storeId);
        if ($target === []) {
            $this->logger->info('[PigeonExpress] Redirect target ' . $newReference . ' could not be read; order left as it is');

            throw new QuickActionException(__(
                'Pigeon Express redirected the shipment (the new number is %1), but the new shipment could not be read now. '
                . 'The order was not changed and the customer was not emailed. Use Refresh in a moment.',
                $newReference
            ), true);
        }

        $adjust = $action !== ''
            ? function (array $payload, array $form) use ($action, $body, $fields, $storeId): array {
                return $this->applySent($payload, $form, $action, $body, $fields, $storeId);
            }
            : null;

        $emailed = $this->follower->move($fresh, $newReference, $lines, $storeId, $target, $adjust);
        if ($emailed === null) {
            throw self::changedMeanwhile();
        }

        return [
            'price'     => $price,
            'removed'   => self::codes($data['result']['removed_services'] ?? []),
            'message'   => $emailed
                ? (string) __(
                    'The shipment was redirected. Its new number is %1; print the new label. The customer was emailed the new tracking number.',
                    $newReference
                )
                : (string) __('The shipment was redirected. Its new number is %1; print the new label.', $newReference),
            'reference' => $newReference,
        ];
    }

    /**
     * Bring the record of what the shipment was sent with (payload and form
     * columns) up to date with a change, so the panel and the comments
     * describe the shipment as it is now.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $fields
     */
    private function recordSent(Shipment $row, string $action, array $body, array $fields): void
    {
        $payload = json_decode((string) $row->getPayload(), true);
        $form = json_decode((string) $row->getFormFields(), true);

        [$payload, $form] = $this->applySent(
            is_array($payload) ? $payload : [],
            is_array($form) ? $form : [],
            $action,
            $body,
            $fields,
            0
        );

        $values = [
            'payload'     => (string) json_encode($payload),
            'form_fields' => (string) json_encode($form),
        ];

        try {
            $this->resource->getConnection()->update(
                $this->resource->getMainTable(),
                $values,
                ['entity_id = ?' => (int) $row->getId()]
            );
            $row->addData($values);
        } catch (\Throwable $e) {
            $this->logger->warning('[PigeonExpress] Quick change not recorded on the row: ' . $e->getMessage());
        }
    }

    /**
     * What a change alters in the stored payload and form.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $form
     * @param array<string, mixed> $body
     * @param array<string, mixed> $fields
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public function applySent(array $payload, array $form, string $action, array $body, array $fields, int $storeId): array
    {
        $codes = isset($payload['service_codes']) && is_array($payload['service_codes']) ? $payload['service_codes'] : [];

        switch ($action) {
            case self::CHANGE_DELIVERY:
                $type = (string) ($body['delivery_type'] ?? '');
                $payload['delivery_type'] = $type;

                if ($type === 'address') {
                    $address = (array) ($body['delivery_address'] ?? []);
                    $old = isset($payload['delivery_address']) && is_array($payload['delivery_address'])
                        ? $payload['delivery_address'] : [];
                    unset($payload['delivery_office_id']);
                    $payload['delivery_address'] = array_filter([
                        'city_id'         => (int) ($address['city_id'] ?? 0),
                        'street_id'       => (int) ($address['street_id'] ?? 0) ?: null,
                        'additional_info' => (string) ($address['additional_info'] ?? ''),
                        'contact_name'    => $old['contact_name'] ?? ($payload['receiver_name'] ?? null),
                        'contact_phone'   => $old['contact_phone'] ?? ($payload['receiver_phone'] ?? null),
                    ], static function ($value) {
                        return $value !== null && $value !== '';
                    });

                    $form['delivery_type'] = ConfigInterface::DELIVERY_TYPE_ADDRESS;
                    $form['location_id'] = '';
                    $form['location'] = '';
                    $form['city_id'] = (string) (int) ($address['city_id'] ?? 0);
                    $form['city'] = trim((string) ($fields['city_label'] ?? ''));
                    $form['street_id'] = (int) ($address['street_id'] ?? 0) > 0 ? (string) (int) $address['street_id'] : '';
                    $form['street'] = (int) ($address['street_id'] ?? 0) > 0 ? trim((string) ($fields['street_label'] ?? '')) : '';
                    $form['street_number'] = (string) ($address['additional_info'] ?? '');
                    foreach (['building_number', 'entrance', 'floor', 'apartment'] as $key) {
                        $form[$key] = '';
                    }
                } else {
                    unset($payload['delivery_address']);
                    $payload['delivery_office_id'] = (int) ($body['delivery_office_id'] ?? 0);

                    $form['delivery_type'] = self::moduleType($type);
                    $form['location_id'] = (string) preg_replace('/\D/', '', (string) ($fields['location_id'] ?? ''));
                    $form['location'] = trim((string) ($fields['location_label'] ?? ''));
                    if ($form['location'] === '') {
                        $form['location'] = $this->apiLocationLabel($type, (int) ($body['delivery_office_id'] ?? 0), $storeId);
                    }
                }
                break;

            case self::SERVICES:
                foreach ((array) ($body['add'] ?? []) as $code) {
                    $codes[(string) $code] = true;
                }
                foreach ((array) ($body['remove'] ?? []) as $code) {
                    unset($codes[(string) $code]);
                }
                $payload['service_codes'] = $codes;
                break;

            case self::COD:
                if ($body['cod_amount'] === null) {
                    unset($codes['cod_amount']);
                    $form['cod_amount'] = '0';
                } else {
                    $codes['cod_amount'] = (float) $body['cod_amount'];
                    $form['cod_amount'] = (string) $body['cod_amount'];
                }
                $payload['service_codes'] = $codes;
                break;

            case self::DECLARED_VALUE:
                if ($body['declared_value'] === null) {
                    unset($codes['declared_value']);
                    $form['declared_value'] = false;
                    $form['declared_value_amount'] = null;
                } else {
                    $codes['declared_value'] = (float) $body['declared_value'];
                    $form['declared_value'] = true;
                    $form['declared_value_amount'] = (string) $body['declared_value'];
                }
                $payload['service_codes'] = $codes;
                break;
        }

        return [$payload, $form];
    }

    /**
     * What a change altered, one line each, in the words of the comment
     * written after Edit.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $before GET /actions `data` read just before, or []
     * @param array<string, mixed> $data The carrier's answer to the change
     * @return string[]
     */
    public function describe(string $action, array $body, array $fields, array $before, array $data, string $currency = ''): array
    {
        $current = isset($before['current']) && is_array($before['current']) ? $before['current'] : [];
        $names = $this->serviceNames($before);
        $lines = [];

        $fromTo = static function (string $label, string $old, string $new): string {
            return (string) __('%1: %2 to %3', $label, $old !== '' ? $old : '-', $new !== '' ? $new : '-');
        };
        $money = static function ($value): string {
            return is_numeric($value) ? number_format((float) $value, 2, '.', '') : '';
        };
        $name = function ($code) use ($names): string {
            return $names[(string) $code] ?? $this->serviceName((string) $code, '');
        };

        switch ($action) {
            case self::CHANGE_DELIVERY:
                $oldType = (string) ($current['delivery_type'] ?? '');
                $newType = (string) ($body['delivery_type'] ?? '');

                if ($oldType !== '' && $oldType !== $newType) {
                    $lines[] = $fromTo((string) __('Delivery type'), self::typeLabel($oldType), self::typeLabel($newType));
                }

                if ($newType === 'address') {
                    $address = (array) ($body['delivery_address'] ?? []);
                    $text = implode(', ', array_filter([
                        trim((string) ($fields['city_label'] ?? '')),
                        trim(((int) ($address['street_id'] ?? 0) > 0 ? (string) ($fields['street_label'] ?? '') : '')
                            . ' ' . (string) ($address['additional_info'] ?? '')),
                    ], 'strlen'));

                    $lines[] = (string) __('%1: %2', (string) __('Destination address'), $text);
                } else {
                    $oldId = is_numeric($current['delivery_office_id'] ?? null) ? (int) $current['delivery_office_id'] : 0;
                    $newLabel = trim((string) ($fields['location_label'] ?? ''));

                    $lines[] = $fromTo(
                        (string) __('Destination office / locker'),
                        $oldId > 0 && $oldType !== 'address' ? $this->apiLocationLabel($oldType, $oldId, 0) : '',
                        $newLabel !== '' ? $newLabel : $this->apiLocationLabel($newType, (int) ($body['delivery_office_id'] ?? 0), 0)
                    );
                }
                break;

            case self::SERVICES:
                $added = array_map($name, (array) ($body['add'] ?? []));
                $removed = array_map($name, (array) ($body['remove'] ?? []));

                if ($added !== []) {
                    $lines[] = (string) __('Services added: %1', implode(', ', $added));
                }
                if ($removed !== []) {
                    $lines[] = (string) __('Services removed: %1', implode(', ', $removed));
                }
                break;

            case self::COD:
                $lines[] = $fromTo((string) __('COD amount'), $money($current['cod_amount'] ?? null), $money($body['cod_amount'] ?? null));

                if (($body['cod_amount'] ?? null) !== null && array_key_exists('allow_cod_card_payment', $current)
                    && self::flag($current['allow_cod_card_payment']) !== !empty($body['allow_cod_card_payment'])
                ) {
                    $lines[] = $fromTo(
                        (string) __('Card payment of the cash on delivery'),
                        self::flag($current['allow_cod_card_payment']) ? (string) __('Allowed') : (string) __('Not allowed'),
                        !empty($body['allow_cod_card_payment']) ? (string) __('Allowed') : (string) __('Not allowed')
                    );
                }
                break;

            case self::DECLARED_VALUE:
                $lines[] = $fromTo(
                    (string) __('Declared value (€)'),
                    $money($current['declared_value'] ?? null),
                    $money($body['declared_value'] ?? null)
                );
                break;
        }

        $price = isset($data['price']) && is_array($data['price']) ? $data['price'] : [];

        if (is_numeric($price['previous_total'] ?? null) && is_numeric($price['new_total'] ?? null)) {
            $lines[] = $fromTo((string) __('Price'), $money($price['previous_total']), $money($price['new_total']));
        }

        if (is_numeric($price['amount_due'] ?? null) && (float) $price['amount_due'] > 0) {
            $lines[] = (string) __('%1: %2', (string) __('Amount due'), $money($price['amount_due']));
        }

        $also = self::codes($data['result']['removed_services'] ?? []);
        if ($also !== []) {
            $lines[] = (string) __('Removed with it by Pigeon Express: %1', implode(', ', array_map($name, $also)));
        }

        if ($lines === []) {
            $lines[] = (string) __('No details were changed.');
        }

        return $lines;
    }

    /**
     * The price of a change for the panel: label => value, already formatted.
     *
     * @param array<string, mixed> $price The carrier's `price`
     * @param string[] $removed Service codes removed with the change
     * @return array<int, array{label: string, value: string}>
     */
    public function priceRows(array $price, string $currency, array $removed = []): array
    {
        $rows = [];

        foreach ([
            'previous_total' => (string) __('Price now'),
            'new_total'      => (string) __('New price'),
            'amount_due'     => (string) __('Amount due'),
        ] as $key => $label) {
            if (is_numeric($price[$key] ?? null)) {
                $rows[] = ['label' => $label, 'value' => $this->money((float) $price[$key], $currency)];
            }
        }

        $payer = self::paymentModeLabel(is_scalar($price['payment_mode'] ?? null) ? (string) $price['payment_mode'] : '');
        if ($payer !== '') {
            $rows[] = ['label' => (string) __('Who pays the courier'), 'value' => $payer];
        }

        if ($removed !== []) {
            $rows[] = [
                'label' => (string) __('Also removed'),
                'value' => implode(', ', array_map(function (string $code): string {
                    return $this->serviceName($code, '');
                }, $removed)),
            ];
        }

        return $rows;
    }

    /** Who pays for the change, from the carrier's payment mode. */
    public static function paymentModeLabel(string $mode): string
    {
        switch ($mode) {
            case 'paid':
                return (string) __('Sender, already paid');
            case 'sender_unpaid':
                return (string) __('Sender, not paid yet');
            case 'deferred':
                return (string) __('Sender, on deferred payment');
            case 'receiver':
                return (string) __('Receiver');
            default:
                return '';
        }
    }

    /**
     * A refused or failed change, in the merchant's words. Decided by the
     * carrier's `code` and the HTTP status, never by its text; where the
     * carrier gave a reason, that reason is what is shown.
     *
     * @param bool $dryRun Only a price was asked for: nothing can have changed
     */
    public function errorMessage(ApiException $error, bool $dryRun, string $currency = 'EUR'): string
    {
        $status = $error->getHttpStatus();
        $code = $error->getApiErrorCode();
        $body = $error->getApiErrorData();
        $reason = trim((string) $error->getMessage());

        // A change that may have been made: the module's own words say so.
        if ($error instanceof OutcomeUnknownException) {
            return $reason;
        }

        if ($error instanceof RateLimitedException || $status === 429) {
            return (string) __('Too many requests to Pigeon Express. Wait a moment and try again.');
        }

        // Raised here or by the client before any answer: already worded.
        if ($status === 0) {
            return $reason;
        }

        // The courier's system was busy and changed nothing: its own words.
        if ($code === 'backend_unavailable' && $reason !== '') {
            return $reason;
        }

        if ($status === 409 && $dryRun) {
            return (string) __('Pigeon Express is still working on the same request. Wait a few seconds and try again.');
        }

        if ($status === 409 || $status >= 500) {
            return $dryRun
                ? (string) __('Could not reach Pigeon Express. Try again.')
                : (string) __('Pigeon Express did not confirm the change, so it may or may not have been made. Use "Refresh status" to check before trying again.');
        }

        if ($status === 401 || $status === 403) {
            return (string) __('Pigeon Express rejected the credentials. Check them in the Pigeon Express settings.');
        }

        if ($status === 404 && ($code === 'not_found' || $code === '')) {
            return (string) __('Pigeon Express did not find this shipment. If you switched between Sandbox and Live, it belongs to the other environment.');
        }

        switch ($code) {
            case 'requires_office_payment':
                $due = $body['price']['amount_due'] ?? null;

                return is_numeric($due)
                    ? trim($reason . ' ' . (string) __('Amount due: %1.', $this->money((float) $due, $currency)))
                    : $reason;

            case 'already_redirected':
                $active = RedirectFollower::cleanReference($body['active_reference_number'] ?? '');

                return $active !== ''
                    ? trim($reason . ' ' . (string) __('The active shipment is %1.', $active))
                    : $reason;

            case '':
                // The API's own check of the fields.
                if (isset($body['errors']) && is_array($body['errors']) && $body['errors'] !== []) {
                    return (string) __('Pigeon Express refused the change: %1', $reason);
                }

                return $reason;

            default:
                // action_not_allowed, no_change, fee_closed, validation_failed
                // and the rest: the carrier's reason says it best.
                return $reason;
        }
    }

    /** An office or a locker as the merchant reads it. */
    public static function typeLabel(string $type): string
    {
        switch ($type) {
            case 'address':
                return (string) __('Address Delivery');
            case 'locker':
            case ConfigInterface::DELIVERY_TYPE_APS:
                return (string) __('Parcel locker');
            default:
                return (string) __('Pigeon Express office');
        }
    }

    /**
     * The module's type for a carrier delivery type: aps for a locker.
     */
    public static function moduleType(string $type): string
    {
        return $type === 'locker' ? ConfigInterface::DELIVERY_TYPE_APS : ConfigInterface::DELIVERY_TYPE_OFFICE;
    }

    /**
     * The local row and label of the office or locker the carrier names by
     * its id, in the current environment, or null when the list lacks it.
     *
     * @return array{id: int, label: string}|null
     */
    private function locationByApiId(string $type, int $apiId, int $storeId): ?array
    {
        $rows = $this->locationSearch->rowsByApiIds(self::moduleType($type), [$apiId], $storeId);
        $row = $rows[0] ?? null;
        if (!is_array($row) || (int) ($row['id'] ?? 0) <= 0) {
            return null;
        }

        return ['id' => (int) $row['id'], 'label' => self::rowLabel($row)];
    }

    /** An office or locker by the carrier's id, as the merchant reads it. */
    private function apiLocationLabel(string $type, int $apiId, int $storeId): string
    {
        if ($apiId <= 0) {
            return '';
        }

        $row = $this->locationByApiId($type, $apiId, $storeId);
        if ($row !== null) {
            return $row['label'];
        }

        return $type === 'locker'
            ? (string) __('Parcel locker %1', (string) $apiId)
            : (string) __('Office %1', (string) $apiId);
    }

    /**
     * @param array<string, mixed> $row A picker row: name, address, city
     */
    public static function rowLabel(array $row): string
    {
        return implode(' - ', array_filter([
            trim((string) ($row['name'] ?? '')),
            trim((string) ($row['address'] ?? '')),
            trim((string) ($row['city'] ?? '')),
        ], 'strlen'));
    }

    /**
     * A delivery address from the carrier's fields in one line.
     *
     * @param array<string, mixed> $address
     */
    public static function addressLine(array $address): string
    {
        $parts = [];

        foreach (['street_number', 'building_number', 'entrance', 'floor', 'apartment', 'neighborhood', 'additional_info'] as $key) {
            $value = is_scalar($address[$key] ?? null) ? trim(strip_tags((string) $address[$key])) : '';

            if ($value !== '' && !in_array($value, $parts, true)) {
                $parts[] = $value;
            }
        }

        return implode(', ', $parts);
    }

    /**
     * @param mixed $rows The carrier's `addable_services` or `removable_services`
     * @return array<int, array{code: string, name: string}>
     */
    private function serviceList($rows): array
    {
        $out = [];

        foreach ((array) $rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $code = self::cleanCode($row['code'] ?? '');
            if ($code === '') {
                continue;
            }

            $out[] = [
                'code' => $code,
                'name' => $this->serviceName($code, is_scalar($row['name'] ?? null) ? trim(strip_tags((string) $row['name'])) : ''),
            ];
        }

        return $out;
    }

    /**
     * Code => name for every service the GET /actions answer names.
     *
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function serviceNames(array $data): array
    {
        $names = [];

        foreach (array_merge($this->serviceList($data['addable_services'] ?? []), $this->serviceList($data['removable_services'] ?? [])) as $row) {
            $names[$row['code']] = $row['name'];
        }

        return $names;
    }

    /**
     * A service by the name Pigeon Express gave, else the synced catalogue's,
     * else the code in words.
     */
    public function serviceName(string $code, string $carrierName): string
    {
        if ($carrierName !== '') {
            return $carrierName;
        }

        try {
            $service = $this->services->getByCode($code);
            $name = $service !== null ? trim((string) $service->getName()) : '';
        } catch (\Throwable $e) {
            $name = '';
        }

        return $name !== '' ? $name : ucfirst(str_replace('_', ' ', $code));
    }

    /**
     * The reference the order holds now ('' when none).
     */
    private function heldReference(int $orderId): string
    {
        $row = $this->finder->findShipment($orderId);

        return $row !== null && $row->getStatus() !== ShipmentCanceller::STATUS_CANCELLED
            ? (string) $row->getReferenceNumber()
            : '';
    }

    /**
     * The carrier's `data` for a shipment, or [] when it could not be read.
     *
     * @return array<string, mixed>
     */
    private function shipmentData(string $reference, int $storeId): array
    {
        try {
            $data = $this->client->get($reference, $storeId)->toArray();
        } catch (\Throwable $e) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    /**
     * Keep the shipment's details as just read; nothing when they were not.
     *
     * @param array<string, mixed> $details
     */
    private function remember(Shipment $row, array $details): void
    {
        if ($details === []) {
            return;
        }

        try {
            $this->details->remember($row, $details);
        } catch (\Throwable $e) {
            $this->logger->warning('[PigeonExpress] Details after a quick change not kept: ' . $e->getMessage());
        }
    }

    private function money(float $amount, string $currency): string
    {
        return (string) $this->priceCurrency->format(
            $amount,
            false,
            PriceCurrencyInterface::DEFAULT_PRECISION,
            null,
            $currency !== '' ? $currency : null
        );
    }

    private static function changedMeanwhile(): QuickActionException
    {
        return new QuickActionException(__('The shipment on this order changed in the meantime. Reload the order.'), true);
    }

    /**
     * The currency the carrier priced in when it is a plain code, else the
     * order's. Only used to format the figures.
     */
    public static function currency(string $candidate, string $orderCurrency): string
    {
        $candidate = strtoupper((string) preg_replace('/[^A-Za-z]/', '', $candidate));

        if (strlen($candidate) === 3) {
            return $candidate;
        }

        $orderCurrency = strtoupper((string) preg_replace('/[^A-Za-z]/', '', $orderCurrency));

        return strlen($orderCurrency) === 3 ? $orderCurrency : 'EUR';
    }

    /**
     * @param mixed $codes
     * @return string[]
     */
    public static function codes($codes): array
    {
        return array_values(array_unique(array_filter(array_map([self::class, 'cleanCode'], (array) $codes))));
    }

    /**
     * @param mixed $code
     */
    private static function cleanCode($code): string
    {
        return is_scalar($code) ? (string) preg_replace('/[^A-Za-z0-9_]/', '', (string) $code) : '';
    }

    /**
     * A boolean as the carrier may write it (true, "true", 1).
     *
     * @param mixed $value
     */
    private static function flag($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * An amount typed in the panel, or null when left empty (remove it).
     *
     * @param mixed $value
     */
    public static function amount($value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $value = trim(str_replace(',', '.', (string) $value));

        return $value === '' ? null : (float) $value;
    }
}
