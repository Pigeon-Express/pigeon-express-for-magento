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
use PigeonExpress\Exceptions\PigeonExpressException as SdkException;
use PigeonExpress\Exceptions\ValidationException as SdkValidationException;
use PigeonExpress\PigeonExpress;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Api\ShipmentClientInterface;
use PigeonExpress\Shipping\Exception\ApiException;
use Psr\Log\LoggerInterface;

class ShipmentClient implements ShipmentClientInterface
{
    private const DEMO_BASE_URL = 'https://api-demo.pigeonexpress.com';

    /** @var int */
    private const MAX_ATTEMPTS = 3;

    /** @var int */
    private const RETRY_DELAY_MS = 1200;

    /** @var ConfigInterface */
    private $config;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        ConfigInterface $config,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->logger = $logger;
    }

    public function create(array $payload, int $storeId): array
    {
        $apiKey = $this->config->getApiKey($storeId);
        if ($apiKey === '') {
            throw new ApiException(__('Pigeon Express API key is not configured.'));
        }
        $apiSecret = $this->config->getApiSecret($storeId);
        if ($apiSecret === '') {
            $apiSecret = $apiKey; // Some API plans use the same value for both key and secret
        }

        $lastException = null;
        if ($this->config->isLoggingEnabled($storeId)) {
            $this->logger->info('[PigeonExpress] ShipmentClient::create start', [
                'storeId' => $storeId,
                'payload' => $payload,
            ]);
        }
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $options = ['timeout' => 30];
                if ($this->config->isTestMode($storeId)) {
                    $options['base_url'] = self::DEMO_BASE_URL;
                }

                $client = new PigeonExpress($apiKey, $apiSecret, $options);

                // Build DTO for standard fields, then convert to array and merge
                // extra fields the SDK DTO does not support (e.g. inventory_items, raw services).
                $requestDto = $this->buildSdkRequest($payload);
                $requestArray = $requestDto->toArray();
                if (!empty($payload['inventory_items']) && is_array($payload['inventory_items'])) {
                    $requestArray['inventory_items'] = $payload['inventory_items'];
                }
                if (!empty($payload['service_codes']) && is_array($payload['service_codes'])) {
                    $requestArray['service_codes'] = $payload['service_codes'];
                }

                if ($this->config->isLoggingEnabled($storeId)) {
                    $this->logger->info('[PigeonExpress] API request: shipments()->create', [
                        'attempt' => $attempt,
                        'request' => $requestArray,
                    ]);
                }

                $shipment = $client->shipments()->create($requestArray);

                $status = $shipment->getStatus();
                $statusCode = is_object($status) && method_exists($status, 'getCode')
                    ? (string) $status->getCode()
                    : (string) $status;

                $result = [
                    'reference_number' => (string) $shipment->getReferenceNumber(),
                    'tracking_number'  => $shipment->getTrackingNumber(),
                    'status'           => $statusCode ?: 'shipment_registered',
                    'delivery_price'   => (float) $shipment->getDeliveryPrice(),
                ];

                if ($this->config->isLoggingEnabled($storeId)) {
                    $this->logger->info('[PigeonExpress] ShipmentClient::create success', [
                        'storeId' => $storeId,
                        'result'  => $result,
                    ]);
                }

                return $result;
            } catch (SdkValidationException $e) {
                // Validation errors are not retryable — surface field details immediately.
                $lastException = $e;
                if ($this->config->isLoggingEnabled($storeId)) {
                    $this->logger->warning('[PigeonExpress] Shipment API validation error', [
                        'storeId' => $storeId,
                        'message' => $e->getMessage(),
                        'errors'  => $e->getErrors(),
                    ]);
                }
                break;
            } catch (\Throwable $e) {
                $lastException = $e;
                if ($this->config->isLoggingEnabled($storeId)) {
                    $this->logger->warning('[PigeonExpress] Shipment API attempt ' . $attempt . ' failed: ' . $e->getMessage());
                }
                if ($attempt < self::MAX_ATTEMPTS) {
                    usleep(self::RETRY_DELAY_MS * 1000);
                }
            }
        }

        $msg = $lastException ? $this->buildErrorMessage($lastException) : 'Unknown error';
        if ($this->config->isLoggingEnabled($storeId)) {
            $this->logger->warning('[PigeonExpress] ShipmentClient::create all attempts failed', [
                'storeId'        => $storeId,
                'last_exception' => $msg,
            ]);
        }
        if ($lastException instanceof \Exception) {
            throw new ApiException(__('Failed to create Pigeon Express shipment: %1', $msg), $lastException);
        }
        throw new ApiException(__('Failed to create Pigeon Express shipment: %1', $msg));
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
     * Build SDK CreateShipmentRequest DTO from our array payload.
     *
     * @param array $payload
     * @return CreateShipmentRequest
     */
    private function buildSdkRequest(array $payload): CreateShipmentRequest
    {
        $receiverName  = (string) ($payload['receiver_name'] ?? '');
        $receiverPhone = (string) ($payload['receiver_phone'] ?? '');
        $pickupType    = (string) ($payload['pickup_type'] ?? 'office');
        $deliveryType  = (string) ($payload['delivery_type'] ?? 'office');
        $serviceType   = (string) ($payload['service_type'] ?? 'standard');
        $whoPays       = (string) ($payload['who_pays'] ?? 'sender');

        $request = new CreateShipmentRequest(
            $receiverName,
            $receiverPhone,
            $pickupType,
            $deliveryType,
            $serviceType,
            $whoPays
        );

        if (isset($payload['pickup_office_id'])) {
            $request->setPickupOfficeId((int) $payload['pickup_office_id']);
        }

        if (!empty($payload['sender_address']) && is_array($payload['sender_address'])) {
            $request->setSenderAddress($payload['sender_address']);
        }

        if (isset($payload['delivery_office_id'])) {
            $request->setDeliveryOfficeId((int) $payload['delivery_office_id']);
        }

        if (!empty($payload['delivery_address']) && is_array($payload['delivery_address'])) {
            $request->setDeliveryAddress($payload['delivery_address']);
        }

        if (!empty($payload['packages']) && is_array($payload['packages'])) {
            foreach ($payload['packages'] as $package) {
                $request->addPackage($package);
            }
        }

        if (isset($payload['cod_amount']) && (float) $payload['cod_amount'] > 0) {
            $request->setCodAmount((float) $payload['cod_amount']);
        }

        if (isset($payload['note'])) {
            $request->setNote((string) $payload['note']);
        }

        if (isset($payload['sms_notification'])) {
            $request->enableSmsNotification((bool) $payload['sms_notification']);
        }

        return $request;
    }
}
