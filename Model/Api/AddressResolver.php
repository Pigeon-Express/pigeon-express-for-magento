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

    /**
     * Search street ID via GET /cities/{cityId}/streets filtered by name.
     */
    private function searchStreetId(int $cityId, string $query, int $storeId): ?int
    {
        try {
            $apiKey    = $this->config->getApiKey($storeId);
            $apiSecret = $this->config->getApiSecret($storeId) ?: $apiKey;

            $url  = $this->baseUrl($storeId) . '/cities/' . $cityId . '/streets';
            $data = $this->doGet($url, $apiKey, $apiSecret);

            $queryLower = mb_strtolower($query);
            foreach ($data['data'] ?? [] as $street) {
                if (!is_array($street)) {
                    continue;
                }
                if (mb_strtolower((string) ($street['name'] ?? '')) === $queryLower) {
                    return (int) $street['id'];
                }
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
