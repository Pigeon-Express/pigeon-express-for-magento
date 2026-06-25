<?php
/**
 * Rate calculation via Pigeon Express PHP SDK: POST /shipments/calculate.
 *
 * Similar to LocationsClient: uses official SDK (PigeonExpress\PigeonExpress)
 * instead of manual Curl calls, so request/response mapping stays in sync
 * with upstream API and examples from documentation.
 *
 * @copyright Copyright (c). All rights reserved.
 */
declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Api;

use PigeonExpress\Exceptions\PigeonExpressException as SdkException;
use PigeonExpress\Exceptions\ValidationException as SdkValidationException;
use PigeonExpress\PigeonExpress;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Api\RateClientInterface;
use PigeonExpress\Shipping\Exception\ApiException;
use Psr\Log\LoggerInterface;

class RateClient implements RateClientInterface
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

    public function calculate(array $payload, int $storeId): array
    {
        $apiKey = $this->config->getApiKey($storeId);
        if ($apiKey === '') {
            throw new ApiException(__('Pigeon Express API key is not configured.'));
        }
        $apiSecret = $this->config->getApiSecret($storeId);
        if ($apiSecret === '') {
            $apiSecret = $apiKey;
        }

        $lastException = null;
        if ($this->config->isLoggingEnabled($storeId)) {
            $this->logger->info('[PigeonExpress] RateClient::calculate start', [
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

                if ($this->config->isLoggingEnabled($storeId)) {
                    $this->logger->info('[PigeonExpress] API request: shipments()->calculate', [
                        'attempt' => $attempt,
                        'request' => $payload,
                    ]);
                }

                $calculation = $client->shipments()->calculate($payload);

                $data = $calculation->toArray();
                // DTO toArray() uses delivery_fee/total (not shipping_price/total_price).
                return [
                    'shipping_price' => isset($data['delivery_fee']) ? (float) $data['delivery_fee'] : 0.0,
                    'total_price' => isset($data['total']) ? (float) $data['total'] : 0.0,
                    'currency' => isset($data['currency']) ? (string) $data['currency'] : null,
                    'estimated_delivery_days' => isset($data['expected_delivery_days']) ? (int) $data['expected_delivery_days'] : null,
                    'service_fees' => isset($data['fee_breakdown']) && !empty($data['fee_breakdown']) ? $data['fee_breakdown'] : null,
                ];
            } catch (SdkValidationException $e) {
                $lastException = $e;
                if ($this->config->isLoggingEnabled($storeId)) {
                    $this->logger->warning('[PigeonExpress] Rate API attempt ' . $attempt . ' validation failed: ' . $e->getMessage(), [
                        'errors' => $e->getErrors(),
                    ]);
                }
            } catch (SdkException $e) {
                $lastException = $e;
                if ($this->config->isLoggingEnabled($storeId)) {
                    $this->logger->warning('[PigeonExpress] Rate API attempt ' . $attempt . ' failed: ' . $e->getMessage());
                }
            } catch (\Throwable $e) {
                $lastException = $e;
                if ($this->config->isLoggingEnabled($storeId)) {
                    $this->logger->warning('[PigeonExpress] Rate API attempt ' . $attempt . ' failed: ' . $e->getMessage());
                }
                if ($attempt < self::MAX_ATTEMPTS) {
                    usleep(self::RETRY_DELAY_MS * 1000);
                }
            }
        }

        $msg = $lastException ? $lastException->getMessage() : 'Unknown error';
        if ($this->config->isLoggingEnabled($storeId)) {
            $this->logger->warning('[PigeonExpress] RateClient::calculate all attempts failed', [
                'storeId' => $storeId,
                'last_exception' => $msg,
            ]);
        }
        if ($lastException instanceof \Exception) {
            throw new ApiException(__('Failed to calculate Pigeon Express rate: %1', $msg), $lastException);
        }
        throw new ApiException(__('Failed to calculate Pigeon Express rate: %1', $msg));
    }

}

