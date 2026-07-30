<?php
/**
 * Resolve Magento address strings into Pigeon Express city_id + street_id.
 *
 * City lookup: local DB table pigeonexpress_city (synced via cron).
 *   Searches by postal_code first, then by Bulgarian name, then English name.
 * Street lookup: GET /cities/{city_id}/streets filtered by name.
 *
 * @copyright Copyright (c). All rights reserved.
 */
declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Api;

use Magento\Framework\HTTP\Client\Curl;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Exception\ApiException;
use PigeonExpress\Shipping\Model\ResourceModel\City\CollectionFactory as CityCollectionFactory;
use Psr\Log\LoggerInterface;

class AddressResolver
{
    private const API_BASE = 'https://api.pigeonexpress.com/v1';
    private const SANDBOX_API_BASE = 'https://api-demo.pigeonexpress.com/v1';

    /** @var ConfigInterface */
    private $config;

    /** @var LoggerInterface */
    private $logger;

    /** @var Curl */
    private $curl;

    /** @var CityCollectionFactory */
    private $cityCollectionFactory;

    public function __construct(
        ConfigInterface $config,
        LoggerInterface $logger,
        Curl $curl,
        CityCollectionFactory $cityCollectionFactory
    ) {
        $this->config = $config;
        $this->logger = $logger;
        $this->curl = $curl;
        $this->cityCollectionFactory = $cityCollectionFactory;
    }

    /**
     * @param array{city?: string|null, street?: string|array|null, postcode?: string|null} $magentoAddress
     * @param int $storeId
     * @return array{city_id:int, street_id?:int|null, street_number:string, postal_code?:string|null}
     * @throws ApiException
     */
    public function resolve(array $magentoAddress, int $storeId): array
    {
        $city = isset($magentoAddress['city']) ? trim((string) $magentoAddress['city']) : '';
        if ($city === '') {
            throw new ApiException(__('City is required for Pigeon Express address delivery.'));
        }

        // Use only the first street line to avoid city/postcode lines being parsed as house number.
        $streetRaw = $magentoAddress['street'] ?? '';
        $streetStr = is_array($streetRaw)
            ? trim((string) ($streetRaw[0] ?? ''))
            : trim((string) $streetRaw);

        $postcode = isset($magentoAddress['postcode']) ? trim((string) $magentoAddress['postcode']) : null;

        if ($this->config->isLoggingEnabled($storeId)) {
            $this->logger->info('[PigeonExpress] AddressResolver::resolve input', [
                'storeId'    => $storeId,
                'city'       => $city,
                'street_str' => $streetStr,
                'postcode'   => $postcode,
            ]);
        }

        $cityId = $this->findCityId($city, $postcode);

        [$streetName, $streetNumber] = $this->extractStreetNameAndNumber($streetStr);
        $streetId = null;
        if ($streetName !== '') {
            $streetId = $this->searchStreetId($cityId, $streetName, $storeId);
        }

        $result = [
            'city_id'       => $cityId,
            'street_number' => $streetNumber !== '' ? $streetNumber : '0',
        ];
        if ($postcode !== null && $postcode !== '') {
            $result['postal_code'] = $postcode;
        }
        if ($streetId !== null) {
            $result['street_id'] = $streetId;
        } elseif ($streetName !== '') {
            $result['additional_info'] = strlen($streetName) >= 3 ? $streetName : 'Address';
        }

        if ($this->config->isLoggingEnabled($storeId)) {
            $this->logger->info('[PigeonExpress] AddressResolver::resolve result', [
                'storeId' => $storeId,
                'result'  => $result,
            ]);
        }

        return $result;
    }

    /**
     * Find city API ID from local DB.
     * Tries: postal_code (exact) → Bulgarian name (case-insensitive) → English name.
     *
     * @throws ApiException
     */
    private function findCityId(string $name, ?string $postcode): int
    {
        // 1. Match by postal_code.
        if ($postcode !== null && $postcode !== '') {
            $collection = $this->cityCollectionFactory->create();
            $collection->addFieldToFilter('postal_code', $postcode);
            $collection->setPageSize(1);
            /** @var \PigeonExpress\Shipping\Model\City $city */
            $city = $collection->getFirstItem();
            if ($city->getId()) {
                return (int) $city->getApiId();
            }
        }

        // 2. Match by Bulgarian name (case-insensitive via DB LOWER).
        $connection = $this->cityCollectionFactory->create()->getResource()->getConnection();
        $table = $this->cityCollectionFactory->create()->getResource()->getMainTable();

        $select = $connection->select()
            ->from($table, ['api_id'])
            ->where('LOWER(name) = LOWER(?)', $name)
            ->limit(1);
        $apiId = $connection->fetchOne($select);
        if ($apiId !== false && $apiId !== null) {
            return (int) $apiId;
        }

        // 3. Match by English name.
        $select = $connection->select()
            ->from($table, ['api_id'])
            ->where('LOWER(name_en) = LOWER(?)', $name)
            ->limit(1);
        $apiId = $connection->fetchOne($select);
        if ($apiId !== false && $apiId !== null) {
            return (int) $apiId;
        }

        throw new ApiException(__(
            'Pigeon Express city not found for "%1". Please run location sync to update city list.',
            $name
        ));
    }

    /** The API caps per_page at 100. */
    private const STREETS_PER_PAGE = 100;

    /** Bound on pages walked, so a huge city cannot stall checkout. */
    private const STREETS_MAX_PAGES = 5;

    /**
     * Search street ID via GET /cities/{cityId}/streets.
     *
     * The endpoint is paginated and supports a server side `name` filter with
     * Latin to Cyrillic transliteration. This previously fetched page one
     * unfiltered and scanned it locally, so in any large city the street was
     * simply not on the page and the lookup always failed.
     */
    private function searchStreetId(int $cityId, string $query, int $storeId): ?int
    {
        $query = trim($query);

        // The API requires at least 2 characters for a name search.
        if (mb_strlen($query) < 2) {
            return null;
        }

        try {
            $apiKey    = $this->config->getApiKey($storeId);
            $apiSecret = $this->config->getApiSecret($storeId) ?: $apiKey;

            $queryLower = mb_strtolower($query);
            $singleCandidate = null;
            $candidateCount = 0;
            $page = 1;
            $lastPage = 1;

            do {
                $url = $this->baseUrl($storeId) . '/cities/' . $cityId . '/streets?' . http_build_query([
                    'name'     => mb_substr($query, 0, 100),
                    'page'     => $page,
                    'per_page' => self::STREETS_PER_PAGE,
                ]);

                $data = $this->doGet($url, $apiKey, $apiSecret);
                $rows = $data['data'] ?? [];

                foreach ($rows as $street) {
                    if (!is_array($street) || !isset($street['id'])) {
                        continue;
                    }

                    $candidateCount++;
                    if ($singleCandidate === null) {
                        $singleCandidate = (int) $street['id'];
                    }

                    $name = mb_strtolower((string) ($street['name'] ?? ''));
                    $nameEn = mb_strtolower((string) ($street['name_en'] ?? ''));
                    if ($name === $queryLower || $nameEn === $queryLower) {
                        return (int) $street['id'];
                    }
                }

                $lastPage = (int) ($data['meta']['last_page'] ?? 1);
                $page++;
            } while ($page <= $lastPage && $page <= self::STREETS_MAX_PAGES);

            if ($lastPage > self::STREETS_MAX_PAGES && $this->config->isLoggingEnabled($storeId)) {
                $this->logger->info('[PigeonExpress] Street search stopped at the page limit', [
                    'city_id' => $cityId,
                    'query' => $query,
                    'last_page' => $lastPage,
                ]);
            }

            // No exact match. If the filtered search found exactly one street it
            // is safe to take it, which covers input like "ul. Vitosha" against a
            // stored name of "ВИТОША". More than one and we would be guessing.
            if ($candidateCount === 1 && $singleCandidate !== null) {
                return $singleCandidate;
            }
        } catch (\Throwable $e) {
            if ($this->config->isLoggingEnabled($storeId)) {
                $this->logger->warning('[PigeonExpress] Street lookup failed, continuing without street_id: ' . $e->getMessage());
            }
        }

        return null;
    }

    private function baseUrl(int $storeId): string
    {
        return $this->config->isTestMode($storeId) ? self::SANDBOX_API_BASE : self::API_BASE;
    }

    /**
     * @throws ApiException
     */
    private function doGet(string $url, string $apiKey, string $apiSecret): array
    {
        $this->curl->setHeaders([]);
        $this->curl->setTimeout(20);
        $this->curl->addHeader('X-API-Key', $apiKey);
        $this->curl->addHeader('X-API-Secret', $apiSecret);
        $this->curl->addHeader('Accept', 'application/json');

        $this->curl->get($url);

        $status  = (int) $this->curl->getStatus();
        $body    = (string) $this->curl->getBody();
        $decoded = json_decode($body, true);

        if ($status < 200 || $status >= 300) {
            $msg = is_array($decoded) && isset($decoded['error']['message'])
                ? (string) $decoded['error']['message']
                : substr($body, 0, 300);
            throw new ApiException(__('Pigeon Express API HTTP %1: %2', $status, $msg));
        }

        if (!is_array($decoded)) {
            throw new ApiException(__('Invalid Pigeon Express API response.'));
        }

        return $decoded;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function extractStreetNameAndNumber(string $street): array
    {
        $street = trim($street);
        if ($street === '') {
            return ['', ''];
        }

        // Handle "STREET_NAME, № NUMBER" or "STREET_NAME №NUMBER".
        if (preg_match('/^(.*?)[,\s]*[№#]\s*(\d+[A-Za-zА-Яа-я]?)\s*$/u', $street, $m)) {
            return [trim((string) $m[1]), trim((string) $m[2])];
        }

        // Handle "STREET_NAME, NUMBER" or "STREET_NAME NUMBER" at end.
        if (preg_match('/^(.*?)[,\s]+(\d+[A-Za-zА-Яа-я]?)\s*$/u', $street, $m)) {
            return [trim((string) $m[1]), trim((string) $m[2])];
        }

        return [$street, ''];
    }
}
