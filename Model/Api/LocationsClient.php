<?php
/**
 * Locations API client — direct HTTP calls to GET /offices with pagination.
 * Bypasses the PHP SDK (which ignores query params in all()) and uses Magento Curl.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Api;

use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Api\LocationsClientInterface;
use PigeonExpress\Shipping\Exception\ApiException;

class LocationsClient implements LocationsClientInterface
{
    private const API_BASE = 'https://api.pigeonexpress.com/v1';
    private const SANDBOX_API_BASE = 'https://api-demo.pigeonexpress.com/v1';

    private const TYPE_OFFICE = 'office';
    private const TYPE_LOCKER = 'locker';

    /** @var int API hard cap is 100 items per page. */
    private const PER_PAGE = 100;

    /** @var int Max attempts per request (initial + retries). */
    private const MAX_ATTEMPTS = 3;

    /** @var int Delay between retries in microseconds (1.5 s). */
    private const RETRY_DELAY_US = 1500000;

    /** @var int Delay between pagination pages in microseconds (1 s). */
    private const PAGE_DELAY_US = 1000000;

    /** @var int Delay after rate limit error in microseconds (65 s). */
    private const RATE_LIMIT_DELAY_US = 65000000;

    /** @var ConfigInterface */
    private $config;

    /** @var LoggerInterface */
    private $logger;

    /** @var Curl */
    private $curl;

    public function __construct(
        ConfigInterface $config,
        LoggerInterface $logger,
        Curl $curl
    ) {
        $this->config = $config;
        $this->logger = $logger;
        $this->curl = $curl;
    }

    /**
     * @inheritdoc
     */
    public function fetchOffices(): array
    {
        return $this->fetchByType(self::TYPE_OFFICE);
    }

    /**
     * @inheritdoc
     */
    public function fetchAps(): array
    {
        return $this->fetchByType(self::TYPE_LOCKER);
    }

    /**
     * @inheritdoc
     */
    public function fetchCities(): array
    {
        $apiKey = $this->config->getApiKey();
        if ($apiKey === '') {
            throw new ApiException(__('Pigeon Express API key is not configured.'));
        }

        $apiSecret = $this->config->getApiSecret();
        if ($apiSecret === '') {
            $apiSecret = $apiKey;
        }

        $base = $this->config->isTestMode() ? self::SANDBOX_API_BASE : self::API_BASE;

        if ($this->config->isLoggingEnabled()) {
            $this->logger->info('[PigeonExpress] Fetching all cities from API...');
        }

        $cities = [];
        $page = 1;

        do {
            $url = $base . '/cities?' . http_build_query(['page' => $page, 'per_page' => self::PER_PAGE]);
            $data = $this->doGetFull($url, $apiKey, $apiSecret);
            $items = $data['data'] ?? [];
            foreach ($items as $item) {
                if (!isset($item['id'])) {
                    continue;
                }
                $cities[] = [
                    'id'          => (int) $item['id'],
                    'name'        => (string) ($item['name'] ?? ''),
                    'name_en'     => isset($item['name_en']) ? (string) $item['name_en'] : null,
                    'postal_code' => isset($item['postal_code']) ? (string) $item['postal_code'] : null,
                ];
            }

            $lastPage = (int) ($data['meta']['last_page'] ?? 1);
            $page++;
        } while ($page <= $lastPage);

        if ($this->config->isLoggingEnabled()) {
            $this->logger->info('[PigeonExpress] Cities fetched: ' . count($cities));
        }

        return $cities;
    }

    /**
     * Fetch all locations of a given type via GET /offices?type=...&page=N&per_page=100.
     *
     * @param string $type 'office' or 'locker'
     * @return array[]
     * @throws ApiException
     */
    private function fetchByType(string $type): array
    {
        $apiKey = $this->config->getApiKey();
        if ($apiKey === '') {
            throw new ApiException(__('Pigeon Express API key is not configured.'));
        }

        $apiSecret = $this->config->getApiSecret();
        if ($apiSecret === '') {
            $apiSecret = $apiKey;
        }

        $base = $this->config->isTestMode() ? self::SANDBOX_API_BASE : self::API_BASE;

        $normalized = [];
        $seenIds = [];
        $page = 1;

        do {
            $params = [
                'type'     => $type,
                'page'     => $page,
                'per_page' => self::PER_PAGE,
            ];
            $url = $base . '/offices?' . http_build_query($params);

            if ($this->config->isLoggingEnabled()) {
                $this->logger->info('[PigeonExpress] API request: GET /offices?' . http_build_query($params));
            }

            $items = $this->getWithRetry($url, $apiKey, $apiSecret, $page);

            if (empty($items)) {
                break;
            }

            // Stop if we see IDs we already have — API wrap-around protection.
            $newItems = [];
            foreach ($items as $item) {
                $id = $item['id'] ?? null;
                if ($id === null) {
                    continue;
                }
                if (isset($seenIds[$id])) {
                    if ($this->config->isLoggingEnabled()) {
                        $this->logger->info('[PigeonExpress] Duplicate ID ' . $id . ' on page ' . $page . ' — stopping pagination.');
                    }
                    $items = [];
                    break;
                }
                $seenIds[$id] = true;
                $newItems[] = $item;
            }

            foreach ($newItems as $item) {
                $normalized[] = $this->normalizeItem($item);
            }

            $page++;
            if (count($items) >= self::PER_PAGE) {
                usleep(self::PAGE_DELAY_US);
            }
        } while (count($items) >= self::PER_PAGE);

        if ($this->config->isLoggingEnabled()) {
            $this->logger->info('[PigeonExpress] Sync done for type=' . $type . ', total=' . count($normalized));
        }

        return $normalized;
    }

    /**
     * GET request with per-page retry on transient errors and rate limit back-off.
     *
     * @param string $url
     * @param string $apiKey
     * @param string $apiSecret
     * @param int $page Used only for log context.
     * @return array Raw items from response data field.
     * @throws ApiException
     */
    private function getWithRetry(string $url, string $apiKey, string $apiSecret, int $page): array
    {
        $lastError = null;
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                return $this->doGet($url, $apiKey, $apiSecret);
            } catch (ApiException $e) {
                $lastError = $e;
                if ($this->config->isLoggingEnabled()) {
                    $this->logger->warning('[PigeonExpress] Page ' . $page . ' error (attempt ' . $attempt . '): ' . $e->getMessage());
                }
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw $e;
                }
                $delay = stripos($e->getMessage(), 'Too Many') !== false
                    ? self::RATE_LIMIT_DELAY_US
                    : self::RETRY_DELAY_US;
                if ($this->config->isLoggingEnabled()) {
                    $this->logger->info('[PigeonExpress] Waiting ' . ($delay / 1000000) . 's before retry...');
                }
                usleep($delay);
            }
        }

        throw $lastError ?? new ApiException(__('Failed to fetch page %1.', $page));
    }

    /**
     * Single GET request. Returns full decoded response (including meta).
     *
     * @throws ApiException
     */
    private function doGetFull(string $url, string $apiKey, string $apiSecret): array
    {
        $this->curl->setHeaders([]);
        $this->curl->setTimeout(30);
        $this->curl->addHeader('X-API-Key', $apiKey);
        $this->curl->addHeader('X-API-Secret', $apiSecret);
        $this->curl->addHeader('Accept', 'application/json');

        $this->curl->get($url);

        $status  = (int) $this->curl->getStatus();
        $body    = (string) $this->curl->getBody();
        $decoded = json_decode($body, true);

        if ($status < 200 || $status >= 300) {
            $msg = is_array($decoded) && isset($decoded['message'])
                ? (string) $decoded['message']
                : substr($body, 0, 200);
            throw new ApiException(__('Pigeon Express API HTTP %1: %2', $status, $msg));
        }

        if (!is_array($decoded)) {
            throw new ApiException(__('Invalid Pigeon Express API response (not JSON).'));
        }

        return $decoded;
    }

    /**
     * Single GET request. Returns decoded items array.
     *
     * @param string $url
     * @param string $apiKey
     * @param string $apiSecret
     * @return array
     * @throws ApiException
     */
    private function doGet(string $url, string $apiKey, string $apiSecret): array
    {
        $this->curl->setHeaders([]);
        $this->curl->setTimeout(30);
        $this->curl->addHeader('X-API-Key', $apiKey);
        $this->curl->addHeader('X-API-Secret', $apiSecret);
        $this->curl->addHeader('Accept', 'application/json');

        $this->curl->get($url);

        $status = (int) $this->curl->getStatus();
        $body = (string) $this->curl->getBody();
        $decoded = json_decode($body, true);

        if ($status < 200 || $status >= 300) {
            $msg = is_array($decoded) && isset($decoded['message'])
                ? (string) $decoded['message']
                : substr($body, 0, 200);
            throw new ApiException(__('Pigeon Express API HTTP %1: %2', $status, $msg));
        }

        if (!is_array($decoded)) {
            throw new ApiException(__('Invalid Pigeon Express API response (not JSON).'));
        }

        // Response: {"success": true, "data": [...]} or plain array.
        if (isset($decoded['data']) && is_array($decoded['data'])) {
            return $decoded['data'];
        }

        // Plain array response (unlikely but guard).
        if (isset($decoded[0])) {
            return $decoded;
        }

        return [];
    }

    /**
     * @param array<string,mixed> $item
     * @return array
     */
    private function normalizeItem(array $item): array
    {
        $cityId = isset($item['city']['id']) ? (int) $item['city']['id'] : null;

        return [
            'id'        => $item['id'] ?? null,
            'name'      => $item['name'] ?? '',
            'city_id'   => $cityId,
            'address'   => $item['address'] ?? '',
            'type'      => $item['type'] ?? '',
            'latitude'  => $item['latitude'] ?? null,
            'longitude' => $item['longitude'] ?? null,
        ];
    }
}
