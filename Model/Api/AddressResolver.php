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

use PigeonExpress\Exceptions\PigeonExpressException as SdkException;
use PigeonExpress\PigeonExpress;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Exception\ApiException;
use PigeonExpress\Shipping\Model\ResourceModel\City\CollectionFactory as CityCollectionFactory;
use Psr\Log\LoggerInterface;

class AddressResolver
{
    private const DEMO_BASE_URL = 'https://api-demo.pigeonexpress.com';

    /** Street lookup runs while pricing a quote, so keep it snappy. */
    private const TIMEOUT = 20;

    /** The API requires at least this many characters for a name search. */
    private const MIN_SEARCH_LENGTH = 2;

    /** @var ConfigInterface */
    private $config;

    /** @var LoggerInterface */
    private $logger;

    /** @var CityCollectionFactory */
    private $cityCollectionFactory;

    public function __construct(
        ConfigInterface $config,
        LoggerInterface $logger,
        CityCollectionFactory $cityCollectionFactory
    ) {
        $this->config = $config;
        $this->logger = $logger;
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
     * Search street ID via GET /cities/{cityId}/streets.
     *
     * The SDK applies the server side name filter and walks the pages, so a
     * street in a large city is actually found. Returning null is fine: the
     * caller then sends the street as free text instead.
     */
    private function searchStreetId(int $cityId, string $query, int $storeId): ?int
    {
        $query = trim($query);

        if (mb_strlen($query) < self::MIN_SEARCH_LENGTH) {
            return null;
        }

        try {
            $streets = $this->client($storeId)->streets()->searchInCity($cityId, $query);

            $queryLower = mb_strtolower($query);
            foreach ($streets as $street) {
                $name = mb_strtolower((string) $street->getName());
                $nameEn = mb_strtolower((string) $street->getNameEn());
                if ($name === $queryLower || $nameEn === $queryLower) {
                    return $street->getId();
                }
            }

            // No exact match. A single hit on an already filtered search is safe
            // to take, which covers input like "ul. Vitosha" against a stored
            // "ВИТОША". More than one and we would be guessing.
            if (count($streets) === 1) {
                return $streets[0]->getId();
            }
        } catch (SdkException $e) {
            $this->logStreetFailure($e, $storeId);
        } catch (ApiException $e) {
            $this->logStreetFailure($e, $storeId);
        } catch (\Throwable $e) {
            $this->logStreetFailure($e, $storeId);
        }

        return null;
    }

    private function logStreetFailure(\Throwable $e, int $storeId): void
    {
        if ($this->config->isLoggingEnabled($storeId)) {
            $this->logger->warning(
                '[PigeonExpress] Street lookup failed, continuing without street_id: ' . $e->getMessage()
            );
        }
    }

    /**
     * Build a configured SDK client.
     *
     * @throws ApiException
     */
    private function client(int $storeId): PigeonExpress
    {
        $apiKey = $this->config->getApiKey($storeId);
        if ($apiKey === '') {
            throw new ApiException(__('Pigeon Express API key is not configured.'));
        }

        $apiSecret = $this->config->getApiSecret($storeId);
        if ($apiSecret === '') {
            $apiSecret = $apiKey;
        }

        $options = ['timeout' => self::TIMEOUT];
        if ($this->config->isTestMode($storeId)) {
            $options['base_url'] = self::DEMO_BASE_URL;
        }

        return new PigeonExpress($apiKey, $apiSecret, $options);
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
