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

use Magento\Framework\App\CacheInterface;
use PigeonExpress\Exceptions\PigeonExpressException as SdkException;
use PigeonExpress\Exceptions\ValidationException as SdkValidationException;
use PigeonExpress\PigeonExpress;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Api\RateClientInterface;
use PigeonExpress\Shipping\Exception\ApiException;
use PigeonExpress\Shipping\Model\Service\CarrierLimits;
use Psr\Log\LoggerInterface;

class RateClient implements RateClientInterface
{
    private const DEMO_BASE_URL = 'https://api-demo.pigeonexpress.com';

    /** @var int */
    private const MAX_ATTEMPTS = 3;

    /** @var int */
    private const RETRY_DELAY_MS = 1200;

    private const CACHE_PREFIX = 'pigeonexpress_rate_';

    /** Own tag so rates can be invalidated without flushing everything. */
    private const CACHE_TAG = 'PIGEONEXPRESS_RATE';

    /**
     * How long a quoted price may be reused, in seconds.
     *
     * Magento recollects totals several times during a single checkout, and the
     * carrier prices every enabled delivery type on each pass, so without this a
     * COD checkout makes well over a dozen identical calls. Fifteen minutes is
     * long enough to collapse those into one round of calls, and short enough
     * that a tariff change reaches customers the same day. Do not raise this: a
     * long window recreates the stale pricing this module was fixed to avoid.
     */
    private const CACHE_LIFETIME = 900;

    /** @var ConfigInterface */
    private $config;

    /** @var LoggerInterface */
    private $logger;

    /** @var CacheInterface */
    private $cache;

    /** @var CarrierLimits */
    private $limits;

    public function __construct(
        ConfigInterface $config,
        LoggerInterface $logger,
        CacheInterface $cache,
        CarrierLimits $limits
    ) {
        $this->config = $config;
        $this->logger = $logger;
        $this->cache = $cache;
        $this->limits = $limits;
    }

    public function calculate(array $payload, int $storeId): array
    {
        $cacheKey = $this->cacheKey($payload, $storeId);

        $cached = $this->loadFromCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $result = $this->callApi($payload, $storeId);
        $this->saveToCache($cacheKey, $result);

        return $result;
    }

    /**
     * Cache key for a calculate request.
     *
     * The price is deterministic for identical inputs, so hashing the whole
     * payload is a safe key: any change to weight, dimensions, route or services
     * produces a different key and a fresh quote.
     *
     * @param array<string,mixed> $payload
     */
    private function cacheKey(array $payload, int $storeId): string
    {
        // Sort so two logically identical payloads hash the same.
        $normalized = $payload;
        $this->ksortRecursive($normalized);

        return self::CACHE_PREFIX . $storeId . '_' . sha1((string) json_encode($normalized));
    }

    /**
     * @param array<string,mixed> $data
     */
    private function ksortRecursive(array &$data): void
    {
        ksort($data);
        foreach ($data as &$value) {
            if (is_array($value)) {
                $this->ksortRecursive($value);
            }
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadFromCache(string $key): ?array
    {
        try {
            $raw = $this->cache->load($key);
            if ($raw === false || $raw === null || $raw === '') {
                return null;
            }
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param array<string,mixed> $result
     */
    private function saveToCache(string $key, array $result): void
    {
        try {
            $this->cache->save(
                (string) json_encode($result),
                $key,
                [self::CACHE_TAG],
                self::CACHE_LIFETIME
            );
        } catch (\Throwable $e) {
            // A cache write failure must never break a quote.
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     * @throws ApiException
     */
    private function callApi(array $payload, int $storeId): array
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
                // DTO toArray() exposes both the legacy and the current field names.
                $result = [
                    'shipping_price' => isset($data['delivery_fee']) ? (float) $data['delivery_fee'] : 0.0,
                    'total_price' => isset($data['total']) ? (float) $data['total'] : 0.0,
                    // Authoritative total of every additional service fee the API applied.
                    'total_service_fees' => isset($data['total_service_fees']) ? (float) $data['total_service_fees'] : 0.0,
                    'currency' => isset($data['currency']) ? (string) $data['currency'] : null,
                    'estimated_delivery_days' => isset($data['expected_delivery_days']) ? (int) $data['expected_delivery_days'] : null,
                    'service_fees' => isset($data['fee_breakdown']) && !empty($data['fee_breakdown']) ? $data['fee_breakdown'] : null,
                    // Codes the API reports it actually charged for.
                    'applied_service_codes' => isset($data['service_codes']) && is_array($data['service_codes'])
                        ? $data['service_codes']
                        : [],
                ];

                $this->limits->learnFromAcceptance(
                    is_array($payload['packages'] ?? null) ? $payload['packages'] : [],
                    (string) ($payload['delivery_type'] ?? ''),
                    $storeId
                );

                return $result;
            } catch (SdkValidationException $e) {
                $lastException = $e;

                // The carrier has just told us what it will not carry. That is
                // the only place those numbers are published, so they are kept.
                $this->limits->learnFromRefusal(
                    $e->getErrors(),
                    (string) ($payload['delivery_type'] ?? ''),
                    $storeId
                );

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

