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

use PigeonExpress\DTO\Request\CreateShipmentRequest;
use PigeonExpress\DTO\Response\ShipmentDetails;
use PigeonExpress\Exceptions\ConflictException as SdkConflictException;
use PigeonExpress\Exceptions\ConnectionException as SdkConnectionException;
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
            throw new ApiException(__('Failed to cancel Pigeon Express shipment: %1', $message));
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
     * A client for the active environment.
     *
     * @throws ApiException
     */
    private function client(int $storeId): PigeonExpress
    {
        return $this->clientFactory->create();
    }
}
