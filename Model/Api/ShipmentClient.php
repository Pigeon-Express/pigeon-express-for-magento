<?php
/**
 * Shipment creation via Pigeon Express PHP SDK: POST /shipments.
 *
 * Similar to RateClient: uses official SDK (PigeonExpress\PigeonExpress)
 * instead of manual Curl calls, so request/response mapping stays in sync
 * with upstream API and examples from documentation.
 *
 * @copyright Copyright (c). All rights reserved.
 */
declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Api;

use Magento\Framework\Phrase;
use PigeonExpress\DTO\Request\CreateShipmentRequest;
use PigeonExpress\DTO\Response\LockerAvailability;
use PigeonExpress\DTO\Response\ShipmentActions;
use PigeonExpress\DTO\Response\ShipmentDetails;
use PigeonExpress\Exceptions\ConflictException as SdkConflictException;
use PigeonExpress\Exceptions\ConnectionException as SdkConnectionException;
use PigeonExpress\Exceptions\PigeonExpressException as SdkException;
use PigeonExpress\Exceptions\RateLimitException as SdkRateLimitException;
use PigeonExpress\Exceptions\ServerException as SdkServerException;
use PigeonExpress\Exceptions\ValidationException as SdkValidationException;
use PigeonExpress\PigeonExpress;
use PigeonExpress\Resources\AbstractResource;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Api\ShipmentClientInterface;
use PigeonExpress\Shipping\Exception\ApiException;
use PigeonExpress\Shipping\Exception\NotEditableException;
use PigeonExpress\Shipping\Exception\RateLimitedException;
use PigeonExpress\Shipping\Exception\OutcomeUnknownException;
use PigeonExpress\Shipping\Model\Service\CarrierLimits;
use Psr\Log\LoggerInterface;

class ShipmentClient implements ShipmentClientInterface
{
    /**
     * How often a create still answered 409 is repeated, with the same body.
     *
     * The carrier answers 409 while an identical request is being processed
     * and replays the first answer to a repeat within 30 seconds, so a repeat
     * made at once cannot create a second shipment. Two repeats, each waiting
     * at most 8 seconds, end well inside that window, as in WooCommerce.
     */
    public const CONFLICT_RETRIES = 2;

    /** See getLastReplay() in the SDK resources. */
    public const REPLAY_NONE = AbstractResource::REPLAY_NONE;
    public const REPLAY_REPEAT = AbstractResource::REPLAY_REPEAT;
    public const REPLAY_EARLIER = AbstractResource::REPLAY_EARLIER;

    /** Most references Pigeon Express tracks in one request. */
    public const TRACK_BULK_MAX = 100;

    /** Longest external reference the API accepts. */
    public const EXTERNAL_REFERENCE_MAX = 100;

    /**
     * The quick actions of runAction(), as Pigeon Express names them in
     * GET /actions. The path spelling (change-delivery) is accepted too.
     */
    public const ACTION_CHANGE_DELIVERY = ShipmentActions::CHANGE_DELIVERY;
    public const ACTION_SERVICES = ShipmentActions::SERVICES;
    public const ACTION_COD = ShipmentActions::COD;
    public const ACTION_DECLARED_VALUE = ShipmentActions::DECLARED_VALUE;

    /** Seconds for GET /actions and for a priced (dry run) action: the merchant waits. */
    public const ACTIONS_TIMEOUT = 15;

    /** Seconds for a locker's free boxes, read while the shipment form is open. */
    public const AVAILABILITY_TIMEOUT = 10;

    /** @var ConfigInterface */
    private $config;

    /** @var LoggerInterface */
    private $logger;

    /** @var CarrierLimits */
    private $limits;

    /** @var ClientFactory */
    private $clientFactory;

    /** @var int */
    private $conflictRetries;

    public function __construct(
        ConfigInterface $config,
        LoggerInterface $logger,
        CarrierLimits $limits,
        ClientFactory $clientFactory,
        int $conflictRetries = self::CONFLICT_RETRIES
    ) {
        $this->config = $config;
        $this->logger = $logger;
        $this->limits = $limits;
        $this->clientFactory = $clientFactory;
        $this->conflictRetries = max(0, $conflictRetries);
    }

    /**
     * Create a shipment. Sent once: never repeated on an error.
     *
     * A create that timed out or met a server error may still have been
     * carried out, and repeating it later could create a second waybill. Only
     * a 409 is repeated, by the SDK, at once and with the same body, which
     * the carrier deduplicates. Every outcome that leaves the shipment in
     * doubt throws OutcomeUnknownException.
     *
     * @inheritdoc
     */
    public function create(array $payload, int $storeId): array
    {
        $body = $this->buildRequest($payload)->toArray();

        if ($this->config->isLoggingEnabled($storeId)) {
            $this->logger->info('[PigeonExpress] API request: shipments()->create', [
                'storeId' => $storeId,
                'request' => $body,
            ]);
        }

        $shipments = $this->clientFactory->create()->shipments();
        $shipments->setConflictRetries($this->conflictRetries);

        try {
            $shipment = $shipments->create($body);
        } catch (SdkValidationException $e) {
            // A refusal at this point still tells us what the carrier will
            // carry, and a merchant may only ever reach the limit here.
            $this->limits->learnFromRefusal(
                $e->getErrors(),
                (string) ($payload['delivery_type'] ?? ''),
                $storeId
            );
            $this->logFailure('validation error', $e, $storeId, ['errors' => $e->getErrors()]);
            throw new ApiException(
                __('Failed to create Pigeon Express shipment: %1', $this->buildErrorMessage($e)),
                $e
            );
        } catch (SdkConflictException $e) {
            $this->logFailure('409 persisted after ' . $this->conflictRetries . ' repeats', $e, $storeId);
            throw new OutcomeUnknownException(
                __(
                    'Pigeon Express is still processing the same shipment, so it is not known yet whether it was '
                    . 'created. Reload the order in a minute and check before creating it again.'
                ),
                $e
            );
        } catch (SdkConnectionException $e) {
            $this->logFailure('no answer', $e, $storeId);
            if ($this->neverLeft($e)) {
                throw new ApiException(
                    __('Could not reach Pigeon Express. Nothing was created. Try again.'),
                    $e
                );
            }
            throw new OutcomeUnknownException(
                __(
                    'Pigeon Express did not confirm the shipment, so it may or may not have been created. '
                    . 'Check your Pigeon Express account before creating it again.'
                ),
                $e
            );
        } catch (SdkServerException $e) {
            $this->logFailure('server error', $e, $storeId);
            throw new OutcomeUnknownException(
                __(
                    'Pigeon Express did not confirm the shipment, so it may or may not have been created. '
                    . 'Check your Pigeon Express account before creating it again.'
                ),
                $e
            );
        } catch (\Exception $e) {
            if ($e instanceof ApiException) {
                throw $e;
            }
            $this->logFailure('refused', $e, $storeId);
            throw new ApiException(
                __('Failed to create Pigeon Express shipment: %1', $this->buildErrorMessage($e)),
                $e
            );
        }

        $status = $shipment->getStatus();
        $statusCode = is_object($status) && method_exists($status, 'getCode')
            ? (string) $status->getCode()
            : (string) $status;

        $reference = (string) $shipment->getReferenceNumber();
        if ($reference === '') {
            throw new OutcomeUnknownException(__(
                'Pigeon Express did not return a shipment reference number. '
                . 'Check your Pigeon Express account before creating it again.'
            ));
        }

        $result = [
            'reference_number' => $reference,
            'tracking_number'  => $shipment->getTrackingNumber(),
            'status'           => $statusCode ?: 'shipment_registered',
            'delivery_price'   => (float) $shipment->getDeliveryPrice(),
            'replay'           => (string) $shipments->getLastReplay(),
        ];

        if ($this->config->isLoggingEnabled($storeId)) {
            $this->logger->info('[PigeonExpress] ShipmentClient::create success', [
                'storeId' => $storeId,
                'result'  => $result,
            ]);
        }

        return $result;
    }

    /**
     * The SDK request for a create payload, with every field of it kept.
     *
     * Fields with a typed setter in the SDK go through it; any other field is
     * carried with set(), so a field added to the payload reaches Pigeon
     * Express without a change here.
     *
     * @param array<string, mixed> $payload
     */
    public function buildRequest(array $payload): CreateShipmentRequest
    {
        $payload += [
            'receiver_name' => '',
            'receiver_phone' => '',
            'pickup_type' => ConfigInterface::PICKUP_TYPE_OFFICE,
            'delivery_type' => ConfigInterface::DELIVERY_TYPE_OFFICE,
            'service_type' => 'standard',
            // The caller always sets this. The fallback matches the module
            // default so the two cannot disagree if it ever stops.
            'who_pays' => ConfigInterface::WHO_PAYS_RECEIVER,
        ];

        // The pickup address is built once for both endpoints. Calculate names
        // it pickup_address, create reads sender_address.
        if (isset($payload['pickup_address']) && !isset($payload['sender_address'])) {
            $payload['sender_address'] = $payload['pickup_address'];
        }
        unset($payload['pickup_address']);

        // No cash to collect is no cod_amount at all, as before.
        if (array_key_exists('cod_amount', $payload)) {
            if ((float) $payload['cod_amount'] > 0) {
                $payload['cod_amount'] = (float) $payload['cod_amount'];
            } else {
                unset($payload['cod_amount']);
            }
        }

        if (isset($payload['sms_notification'])) {
            $payload['sms_notification'] = (bool) $payload['sms_notification'];
        }

        foreach (['pickup_office_id', 'delivery_office_id'] as $idKey) {
            if (isset($payload[$idKey])) {
                $payload[$idKey] = (int) $payload[$idKey];
            }
        }

        if (isset($payload['external_reference'])) {
            $reference = trim((string) $payload['external_reference']);
            if ($reference === '') {
                unset($payload['external_reference']);
            } else {
                $payload['external_reference'] = mb_substr($reference, 0, self::EXTERNAL_REFERENCE_MAX);
            }
        }

        return CreateShipmentRequest::fromArray($payload);
    }

    /**
     * @inheritdoc
     */
    public function get(string $referenceNumber, int $storeId): ShipmentDetails
    {
        $referenceNumber = trim($referenceNumber);
        if ($referenceNumber === '') {
            throw new ApiException(__('Cannot read a shipment without a reference number.'));
        }

        try {
            return $this->client($storeId)->shipments()->get($referenceNumber);
        } catch (ApiException $e) {
            throw $e;
        } catch (SdkRateLimitException $e) {
            $this->logFailure('read rate limited', $e, $storeId, ['reference_number' => $referenceNumber]);
            throw new RateLimitedException(__('Pigeon Express asked to slow down. Try again later.'), $e);
        } catch (\Exception $e) {
            $this->logFailure('read failed', $e, $storeId, ['reference_number' => $referenceNumber]);
            throw new ApiException(
                __('Failed to read the Pigeon Express shipment: %1', $this->buildErrorMessage($e)),
                $e
            );
        }
    }

    /**
     * @inheritdoc
     */
    public function update(string $referenceNumber, array $payload, int $storeId): array
    {
        $referenceNumber = trim($referenceNumber);
        if ($referenceNumber === '') {
            throw new ApiException(__('Cannot change a shipment without a reference number.'));
        }

        $body = $this->buildRequest($payload)->toArray();
        // buildRequest() fills in a default pickup type for create. A change
        // keeps the pickup end as created, so none is sent unless asked.
        if (!array_key_exists('pickup_type', $payload)) {
            unset($body['pickup_type']);
        }

        if ($this->config->isLoggingEnabled($storeId)) {
            $this->logger->info('[PigeonExpress] API request: shipments()->update', [
                'storeId' => $storeId,
                'reference_number' => $referenceNumber,
                'request' => $body,
            ]);
        }

        $shipments = $this->clientFactory->create()->shipments();
        $shipments->setConflictRetries($this->conflictRetries);

        try {
            $details = $shipments->update($referenceNumber, $body);
        } catch (SdkValidationException $e) {
            $this->logFailure('update refused', $e, $storeId, ['errors' => $e->getErrors()]);
            // A shipment that is no longer newly registered is refused with a
            // message and no field errors.
            if (!$e->hasErrors()) {
                throw new NotEditableException(
                    __(
                        'Pigeon Express no longer takes changes to this shipment: the courier has picked it up '
                        . 'or it was handed in at an office. Nothing was changed.'
                    ),
                    $e
                );
            }
            throw new ApiException(
                __('Failed to change the Pigeon Express shipment: %1', $this->buildErrorMessage($e)),
                $e
            );
        } catch (SdkConflictException $e) {
            $this->logFailure('update 409 persisted', $e, $storeId);
            throw new ApiException(
                __('Pigeon Express is still processing the same request. Wait a few seconds and try again.'),
                $e
            );
        } catch (\Exception $e) {
            if ($e instanceof ApiException) {
                throw $e;
            }
            $this->logFailure('update failed', $e, $storeId);
            throw new ApiException(
                __('Failed to change the Pigeon Express shipment: %1', $this->buildErrorMessage($e)),
                $e
            );
        }

        return [
            'details' => $details,
            'replay'  => (string) $shipments->getLastReplay(),
        ];
    }

    /**
     * @inheritdoc
     */
    public function trackBulk(array $references, int $storeId): array
    {
        $clean = [];
        foreach ($references as $reference) {
            $reference = trim((string) $reference);
            if ($reference !== '') {
                $clean[$reference] = $reference;
            }
        }
        if ($clean === []) {
            return [];
        }
        if (count($clean) > self::TRACK_BULK_MAX) {
            throw new ApiException(__('At most %1 shipments can be tracked in one request.', self::TRACK_BULK_MAX));
        }

        try {
            $entries = $this->clientFactory->create()->shipments()->trackBulk(array_values($clean));
        } catch (SdkRateLimitException $e) {
            $this->logFailure('track bulk rate limited', $e, $storeId);
            throw new RateLimitedException(__('Pigeon Express asked to slow down. Try again later.'), $e);
        } catch (\Exception $e) {
            if ($e instanceof ApiException) {
                throw $e;
            }
            $this->logFailure('track bulk failed', $e, $storeId);
            throw new ApiException(
                __('Failed to read the Pigeon Express shipment status: %1', $this->buildErrorMessage($e)),
                $e
            );
        }

        $out = [];
        foreach ($entries as $key => $entry) {
            $out[(string) $key] = $entry->toArray();
        }

        return $out;
    }

    /**
     * cURL 6 (host not resolved) and 7 (connection refused) prove the request
     * never left. Anything else, a timeout above all, does not.
     */
    private function neverLeft(\Throwable $e): bool
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if (preg_match('/cURL error (6|7):/', $cause->getMessage())) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logFailure(string $what, \Throwable $e, int $storeId, array $context = []): void
    {
        if ($this->config->isLoggingEnabled($storeId)) {
            $this->logger->warning(
                '[PigeonExpress] Shipment API ' . $what . ': ' . $e->getMessage(),
                $context + ['storeId' => $storeId]
            );
        }
    }

    /**
     * Build a human-readable error message, including field-level details for ValidationException.
     */
    private function buildErrorMessage(\Throwable $e): string
    {
        if ($e instanceof SdkValidationException && $e->hasErrors()) {
            $parts = [$e->getMessage()];
            foreach ($e->getErrors() as $field => $messages) {
                $fieldErrors = is_array($messages) ? implode(', ', $messages) : (string) $messages;
                $parts[] = $field . ': ' . $fieldErrors;
            }
            return implode(' | ', $parts);
        }
        return $e->getMessage();
    }

    /**
     * @inheritdoc
     */
    public function cancel(string $referenceNumber, int $storeId): array
    {
        $referenceNumber = trim($referenceNumber);
        if ($referenceNumber === '') {
            throw new ApiException(__('Cannot cancel a shipment without a reference number.'));
        }

        if ($this->config->isLoggingEnabled($storeId)) {
            $this->logger->info('[PigeonExpress] ShipmentClient::cancel', [
                'storeId' => $storeId,
                'reference_number' => $referenceNumber,
            ]);
        }

        try {
            $result = $this->client($storeId)->shipments()->cancel($referenceNumber);
        } catch (SdkConflictException $e) {
            // A refusal: the same cancel is still being processed. The local
            // row stays as it is, and the merchant tries again shortly.
            $this->logFailure('cancel answered 409', $e, $storeId, ['reference_number' => $referenceNumber]);
            throw new ApiException(
                __('Pigeon Express is still processing the same request. Wait a few seconds and try again.'),
                $e
            );
        } catch (\Throwable $e) {
            $message = $this->buildErrorMessage($e);
            if ($this->config->isLoggingEnabled($storeId)) {
                $this->logger->warning('[PigeonExpress] Cancel failed: ' . $message, [
                    'reference_number' => $referenceNumber,
                ]);
            }
            // The cause is kept: the HTTP status and the API's code tell a
            // refusal (422, e.g. a return or redirect that cannot be
            // cancelled) from a failure to reach Pigeon Express.
            throw new ApiException(
                __('Failed to cancel Pigeon Express shipment: %1', $message),
                $e instanceof \Exception ? $e : null
            );
        }

        return $result;
    }

    /**
     * @inheritdoc
     */
    public function label(string $referenceNumber, ?string $format, bool $download, int $storeId): array
    {
        $referenceNumber = trim($referenceNumber);
        if ($referenceNumber === '') {
            throw new ApiException(__('Cannot fetch a label without a reference number.'));
        }

        try {
            $label = $this->client($storeId)->shipments()->label($referenceNumber, $format, $download);
        } catch (\Throwable $e) {
            $message = $this->buildErrorMessage($e);
            if ($this->config->isLoggingEnabled($storeId)) {
                $this->logger->warning('[PigeonExpress] Label fetch failed: ' . $message, [
                    'reference_number' => $referenceNumber,
                    'format' => $format,
                ]);
            }
            throw new ApiException(__('Failed to fetch the Pigeon Express label: %1', $message));
        }

        if (!isset($label['body']) || $label['body'] === '') {
            throw new ApiException(__('Pigeon Express returned an empty label for %1.', $referenceNumber));
        }

        return $label;
    }

    /**
     * @inheritdoc
     */
    public function actions(string $referenceNumber, int $storeId): array
    {
        $referenceNumber = trim($referenceNumber);
        if ($referenceNumber === '') {
            throw new ApiException(__('The shipment has no Pigeon Express number.'));
        }

        try {
            $actions = $this->clientFactory->create(self::ACTIONS_TIMEOUT)->shipments()->actions($referenceNumber);
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logFailure('actions read failed', $e, $storeId, $this->errorContext($e, $referenceNumber));
            throw $this->refusal($e, false);
        }

        return $actions->toArray();
    }

    /**
     * @inheritdoc
     */
    public function runAction(
        string $referenceNumber,
        string $action,
        array $body,
        bool $dryRun,
        int $storeId
    ): array {
        $referenceNumber = trim($referenceNumber);
        if ($referenceNumber === '') {
            throw new ApiException(__('The shipment has no Pigeon Express number.'));
        }
        if (ShipmentActions::segmentOf($action) === '') {
            throw new ApiException(__('Pigeon Express does not offer this change.'));
        }

        if ($this->config->isLoggingEnabled($storeId)) {
            $this->logger->info('[PigeonExpress] API request: shipments()->runAction', [
                'storeId' => $storeId,
                'reference_number' => $referenceNumber,
                'action' => ShipmentActions::nameOf($action),
                'dry_run' => $dryRun,
                'request' => $body,
            ]);
        }

        $shipments = $this->clientFactory->create($dryRun ? self::ACTIONS_TIMEOUT : null)->shipments();
        $shipments->setConflictRetries($this->conflictRetries);

        try {
            $result = $shipments->runAction($referenceNumber, $action, $body, $dryRun);
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logFailure(
                ($dryRun ? 'action price' : 'action') . ' refused',
                $e,
                $storeId,
                $this->errorContext($e, $referenceNumber)
            );
            throw $this->refusal($e, !$dryRun);
        }

        $out = [
            'data'                 => $result->toArray(),
            'message'              => $result->getMessage(),
            'replay'               => $dryRun ? self::REPLAY_NONE : (string) $shipments->getLastReplay(),
            'new_reference_number' => $dryRun ? '' : $result->getNewReferenceNumber(),
        ];

        if (!$dryRun && $this->config->isLoggingEnabled($storeId)) {
            $this->logger->info('[PigeonExpress] ShipmentClient::runAction success', [
                'storeId' => $storeId,
                'reference_number' => $referenceNumber,
                'result' => $out,
            ]);
        }

        return $out;
    }

    /**
     * @inheritdoc
     */
    public function lockerAvailability(string $code, int $storeId): array
    {
        $code = trim($code);
        if ($code === '') {
            throw new ApiException(__('This parcel locker has no code to look it up by.'));
        }

        try {
            $locker = $this->clientFactory->create(self::AVAILABILITY_TIMEOUT)->lockers()->availability($code);
        } catch (ApiException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logFailure('locker availability failed', $e, $storeId, ['code' => $code]);
            throw $this->refusal($e, false);
        }

        return self::lockerView($locker);
    }

    /**
     * A locker's boxes and forecast in the fixed shape lockerAvailability()
     * returns.
     *
     * @return array{code: string, name: string, is_blocked: bool, blocked_until: string, box_sizes: array<int, array{size: string, length: int, width: int, height: int, max_weight: float|null, total: int}>, today: array<string, int>, tomorrow: array<string, int>, today_date: string, tomorrow_date: string}
     */
    public static function lockerView(LockerAvailability $locker): array
    {
        $sizes = [];
        foreach ($locker->getBoxSizes() as $box) {
            $sizes[] = $box->toArray();
        }

        return [
            'code'          => $locker->getCode(),
            'name'          => $locker->getName(),
            'is_blocked'    => $locker->isBlocked(),
            'blocked_until' => $locker->getBlockedUntil(),
            'box_sizes'     => $sizes,
            'today'         => $locker->getFreeBoxes(LockerAvailability::DAY_TODAY),
            'tomorrow'      => $locker->getFreeBoxes(LockerAvailability::DAY_TOMORROW),
            'today_date'    => $locker->getForecastDate(LockerAvailability::DAY_TODAY),
            'tomorrow_date' => $locker->getForecastDate(LockerAvailability::DAY_TOMORROW),
        ];
    }

    /**
     * The module exception for a failed quick action or read, with the SDK
     * exception kept as its cause (HTTP status, API code, error body).
     *
     * A refusal carries Pigeon Express's own reason, ready to show. When a
     * real change may have been made after all (no answer, or a 409 that
     * outlasted the repeats) it is an OutcomeUnknownException.
     *
     * @param bool $changes Whether the request could have changed the shipment
     */
    private function refusal(\Exception $e, bool $changes): ApiException
    {
        if ($e instanceof SdkRateLimitException) {
            return new RateLimitedException(__('Pigeon Express asked to slow down. Try again later.'), $e);
        }

        if ($e instanceof SdkConflictException) {
            if ($changes) {
                return new OutcomeUnknownException(
                    __(
                        'Pigeon Express is still processing the same change, so it is not known yet whether it '
                        . 'was made. Reload the order in a minute and check before trying again.'
                    ),
                    $e
                );
            }

            return new ApiException(
                __('Pigeon Express is still processing the same request. Wait a few seconds and try again.'),
                $e
            );
        }

        if ($e instanceof SdkConnectionException) {
            if ($changes && !$this->neverLeft($e)) {
                return new OutcomeUnknownException(
                    __(
                        'Pigeon Express did not confirm the change, so it may or may not have been made. '
                        . 'Reload the order and check before trying again.'
                    ),
                    $e
                );
            }

            return new ApiException(__('Could not reach Pigeon Express. Nothing was changed. Try again.'), $e);
        }

        if ($e instanceof \InvalidArgumentException) {
            return new ApiException(__('Pigeon Express does not offer this change.'), $e);
        }

        return new ApiException(new Phrase('%1', [$this->reasonOf($e)]), $e);
    }

    /**
     * Pigeon Express's reason for a refusal as one line: the field messages
     * when it gave them (its sentences are written to stand alone), else its
     * message.
     */
    private function reasonOf(\Throwable $e): string
    {
        if ($e instanceof SdkValidationException && $e->hasErrors()) {
            $pieces = [];
            foreach ($e->getErrors() as $messages) {
                $text = is_array($messages)
                    ? implode(' ', array_map('strval', array_filter($messages, 'is_scalar')))
                    : (is_scalar($messages) ? (string) $messages : '');
                if (trim($text) !== '') {
                    $pieces[] = trim($text);
                }
            }
            if ($pieces !== []) {
                return implode('; ', $pieces);
            }
        }

        $message = trim($e->getMessage());

        return $message !== '' ? $message : (string) __('Unknown Pigeon Express API error.');
    }

    /**
     * What a failed call logs: the reference and, for an API answer, its
     * status and code.
     *
     * @return array<string, mixed>
     */
    private function errorContext(\Throwable $e, string $referenceNumber): array
    {
        $context = ['reference_number' => $referenceNumber];
        if ($e instanceof SdkException) {
            $context['status'] = $e->getHttpStatusCode();
            $context['code'] = $e->getErrorCode();
        }

        return $context;
    }

    /**
     * A client for the active environment.
     *
     * @throws ApiException
     */
    private function client(int $storeId): PigeonExpress
    {
        return $this->clientFactory->create();
    }
}
