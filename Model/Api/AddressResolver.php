<?php
/**
 * Lookups against the Pigeon Express location data, plus the parsing needed to
 * get a Magento address into a shape those lookups accept.
 *
 * Cities come from the local table pigeonexpress_city, refreshed by cron.
 * Streets come from GET /cities/{city_id}/streets, which is the only place they
 * exist, and are cached because the same city is searched repeatedly.
 *
 * This class answers questions. It does not decide whether an address is good
 * enough to ship: that belongs to AddressForm, which is what the admin edits.
 *
 * @copyright Copyright (c). All rights reserved.
 */
declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Api;

use Magento\Framework\App\CacheInterface;
use PigeonExpress\PigeonExpress;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Exception\ApiException;
use PigeonExpress\Shipping\Model\ResourceModel\City\CollectionFactory as CityCollectionFactory;
use Psr\Log\LoggerInterface;

class AddressResolver
{
    private const DEMO_BASE_URL = 'https://api-demo.pigeonexpress.com';

    /** Street lookup runs while an admin is typing, so keep it snappy. */
    private const TIMEOUT = 20;

    /** The API requires at least this many characters for a name search. */
    public const MIN_SEARCH_LENGTH = 2;

    private const CACHE_PREFIX = 'pigeonexpress_streets_';

    /** Own tag so street lists can be dropped without flushing everything. */
    private const CACHE_TAG = 'PIGEONEXPRESS_STREETS';

    /**
     * Streets are renamed about as often as a city is founded, and the same
     * search runs on every keystroke and again on every order view, so an hour
     * costs nothing and saves a great many calls.
     */
    private const CACHE_LIFETIME = 3600;

    /** @var ConfigInterface */
    private $config;

    /** @var LoggerInterface */
    private $logger;

    /** @var CityCollectionFactory */
    private $cityCollectionFactory;

    /** @var CacheInterface */
    private $cache;

    public function __construct(
        ConfigInterface $config,
        LoggerInterface $logger,
        CityCollectionFactory $cityCollectionFactory,
        CacheInterface $cache
    ) {
        $this->config = $config;
        $this->logger = $logger;
        $this->cityCollectionFactory = $cityCollectionFactory;
        $this->cache = $cache;
    }

    // -- Cities --------------------------------------------------------------

    /**
     * Cities whose name, Latin name or postal code starts with the query.
     *
     * Bulgaria has several villages sharing a name, so the postal code is part
     * of the label. Picking the wrong Абланица sends the parcel 200 km away.
     *
     * @return array<int, array{id:int, name:string, postcode:string, label:string}>
     */
    public function searchCities(string $query, int $limit = 20): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $limit = $limit > 0 ? min($limit, 50) : 20;
        $like = $this->escapeLike($query) . '%';

        $connection = $this->cityConnection();
        $select = $connection->select()
            ->from($this->cityTable(), ['api_id', 'name', 'name_en', 'postal_code'])
            ->where('name LIKE ?', $like)
            ->orWhere('name_en LIKE ?', $like)
            ->orWhere('postal_code LIKE ?', $like)
            // Most orders go to a handful of large cities, and alphabetical order
            // buries every one of them: "Соф" offers Софийци before София, "Плов"
            // offers Пловка before Пловдив. Bulgarian district capitals are the
            // codes ending in 000, so those come first, then the shorter name.
            ->order(new \Zend_Db_Expr("(postal_code LIKE '%000') DESC"))
            ->order(new \Zend_Db_Expr('CHAR_LENGTH(name) ASC'))
            ->order('name ASC')
            ->limit($limit);

        $rows = [];
        foreach ($connection->fetchAll($select) as $row) {
            $rows[] = [
                'id'       => (int) $row['api_id'],
                'name'     => (string) $row['name'],
                'postcode' => (string) ($row['postal_code'] ?? ''),
                'label'    => $this->cityLabel((string) $row['name'], (string) ($row['postal_code'] ?? '')),
            ];
        }

        return $rows;
    }

    /**
     * A city by its Pigeon Express id.
     *
     * @return array{id:int, name:string, postcode:string, label:string}|null
     */
    public function getCityById(int $apiId): ?array
    {
        if ($apiId <= 0) {
            return null;
        }

        $connection = $this->cityConnection();
        $select = $connection->select()
            ->from($this->cityTable(), ['api_id', 'name', 'postal_code'])
            ->where('api_id = ?', $apiId)
            ->limit(1);

        $row = $connection->fetchRow($select);
        if (!$row) {
            return null;
        }

        return [
            'id'       => (int) $row['api_id'],
            'name'     => (string) $row['name'],
            'postcode' => (string) ($row['postal_code'] ?? ''),
            'label'    => $this->cityLabel((string) $row['name'], (string) ($row['postal_code'] ?? '')),
        ];
    }

    /**
     * Best match for a city typed as free text, or null when there is none.
     *
     * Postal code first, because it is unambiguous and the name is not.
     */
    public function matchCityId(string $name, ?string $postcode = null): ?int
    {
        $name = trim($name);
        $postcode = $postcode !== null ? trim($postcode) : '';

        if ($postcode !== '') {
            $collection = $this->cityCollectionFactory->create();
            $collection->addFieldToFilter('postal_code', $postcode);
            if ($name !== '') {
                // A postal code covers one settlement, but a mistyped code should
                // not win over a name that matches, so require both when both are
                // present and fall back to the code alone below.
                $collection->addFieldToFilter('name', ['like' => $this->escapeLike($name)]);
            }
            $collection->setPageSize(1);
            /** @var \PigeonExpress\Shipping\Model\City $city */
            $city = $collection->getFirstItem();
            if ($city->getId()) {
                return (int) $city->getApiId();
            }
        }

        if ($name === '') {
            return $postcode !== '' ? $this->matchCityByPostcode($postcode) : null;
        }

        $connection = $this->cityConnection();
        $table = $this->cityTable();

        foreach (['name', 'name_en'] as $column) {
            $select = $connection->select()
                ->from($table, ['api_id'])
                ->where('LOWER(' . $column . ') = LOWER(?)', $name)
                ->limit(1);
            $apiId = $connection->fetchOne($select);
            if ($apiId !== false && $apiId !== null && $apiId !== '') {
                return (int) $apiId;
            }
        }

        return $postcode !== '' ? $this->matchCityByPostcode($postcode) : null;
    }

    private function matchCityByPostcode(string $postcode): ?int
    {
        $connection = $this->cityConnection();
        $select = $connection->select()
            ->from($this->cityTable(), ['api_id'])
            ->where('postal_code = ?', $postcode)
            ->limit(1);

        $apiId = $connection->fetchOne($select);

        return ($apiId !== false && $apiId !== null && $apiId !== '') ? (int) $apiId : null;
    }

    private function cityLabel(string $name, string $postcode): string
    {
        return $postcode !== '' ? $name . ' (' . $postcode . ')' : $name;
    }

    // -- Streets -------------------------------------------------------------

    /**
     * Streets in a city matching a name fragment.
     *
     * Returns an empty list rather than throwing when the API is unreachable.
     * The admin can still type the street by hand, and the form says so.
     *
     * @return array<int, array{id:int, name:string, name_en:string, type:string, label:string}>
     */
    public function searchStreets(int $cityId, string $query, int $storeId, int $limit = 20): array
    {
        $query = trim($query);
        if ($cityId <= 0 || mb_strlen($query) < self::MIN_SEARCH_LENGTH) {
            return [];
        }

        $rows = $this->loadStreets($cityId, $query, $storeId);

        return $limit > 0 ? array_slice($rows, 0, $limit) : $rows;
    }

    /**
     * The street row whose name equals the query, or null.
     *
     * Only an exact name match, or a single hit on an already filtered search,
     * is accepted. Anything looser would put a plausible neighbouring street on
     * the waybill, which is worse than sending no street id at all.
     *
     * A name is not unique. Sofia has both a квартал and a булевард called
     * ВИТОША, in different parts of the city. When the customer's own street
     * type settles it, it is used; when it does not, the row comes back marked
     * ambiguous so the admin is asked rather than a coin being tossed.
     *
     * @return array{id:int, name:string, name_en:string, type:string, label:string, ambiguous:bool}|null
     */
    public function matchStreet(int $cityId, string $query, int $storeId, string $preferredType = ''): ?array
    {
        $query = trim($query);
        if ($cityId <= 0 || mb_strlen($query) < self::MIN_SEARCH_LENGTH) {
            return null;
        }

        $streets = $this->loadStreets($cityId, $query, $storeId);
        if (empty($streets)) {
            return null;
        }

        $needle = mb_strtolower($query);
        $exact = array_values(array_filter($streets, static function (array $street) use ($needle): bool {
            return mb_strtolower((string) $street['name']) === $needle
                || mb_strtolower((string) $street['name_en']) === $needle;
        }));

        if (empty($exact)) {
            // A single hit on an already filtered search is safe to take, which
            // covers input like "ul. Vitosha" against a stored "ВИТОША".
            return count($streets) === 1 ? $streets[0] + ['ambiguous' => false] : null;
        }

        if (count($exact) === 1) {
            return $exact[0] + ['ambiguous' => false];
        }

        if ($preferredType !== '') {
            $byType = array_values(array_filter($exact, static function (array $street) use ($preferredType): bool {
                return mb_strtolower((string) $street['type']) === mb_strtolower($preferredType);
            }));

            if (count($byType) === 1) {
                return $byType[0] + ['ambiguous' => false];
            }
        }

        return $exact[0] + ['ambiguous' => true];
    }

    /**
     * Street id for a name, or null when it cannot be matched.
     */
    public function searchStreetId(int $cityId, string $query, int $storeId): ?int
    {
        $street = $this->matchStreet($cityId, $query, $storeId);

        return $street !== null ? $street['id'] : null;
    }

    /**
     * The street type a Bulgarian address leads with, normalised to the word the
     * carrier stores. Empty when the address does not say.
     */
    public function extractStreetType(string $streetName): string
    {
        if (!preg_match(self::TYPE_PREFIX_PATTERN, $streetName, $matches)) {
            return '';
        }

        $typed = mb_strtolower(trim(str_replace([' ', '.'], '', $matches[1])));

        return self::TYPE_ALIASES[$typed] ?? '';
    }

    /**
     * @return array<int, array{id:int, name:string, name_en:string, type:string, label:string}>
     */
    private function loadStreets(int $cityId, string $query, int $storeId): array
    {
        $cacheKey = self::CACHE_PREFIX . $cityId . '_' . md5(mb_strtolower($query));

        $cached = $this->cache->load($cacheKey);
        if (is_string($cached) && $cached !== '') {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        try {
            $streets = $this->client($storeId)->streets()->searchInCity($cityId, $query);
        } catch (\Throwable $e) {
            $this->logStreetFailure($e, $storeId);

            return [];
        }

        $rows = [];
        foreach ($streets as $street) {
            $name = (string) $street->getName();
            $type = (string) $street->getType();
            $rows[] = [
                'id'      => (int) $street->getId(),
                'name'    => $name,
                'name_en' => (string) $street->getNameEn(),
                'type'    => $type,
                'label'   => trim($type . ' ' . $name),
            ];
        }

        $rows = $this->rankStreets($rows, $query);

        $this->cache->save(json_encode($rows), $cacheKey, [self::CACHE_TAG], self::CACHE_LIFETIME);

        return $rows;
    }

    /**
     * Put the street that was actually typed at the top of the list.
     *
     * The API returns anything containing the query, in its own order, so a
     * search for Витоша leads with "600-НА (ВИТОША)" and buries Витоша itself
     * below the fold. An admin scanning a list picks the first plausible row.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function rankStreets(array $rows, string $query): array
    {
        $needle = mb_strtolower($query);

        $rank = static function (array $row) use ($needle): int {
            $name = mb_strtolower((string) $row['name']);
            $nameEn = mb_strtolower((string) $row['name_en']);

            if ($name === $needle || $nameEn === $needle) {
                return 0;
            }

            if (mb_strpos($name, $needle) === 0 || ($nameEn !== '' && mb_strpos($nameEn, $needle) === 0)) {
                return 1;
            }

            return 2;
        };

        // Stable: equally ranked streets keep the order the carrier sent them in.
        $ordered = [];
        foreach ($rows as $index => $row) {
            $ordered[] = [$rank($row), $index, $row];
        }

        usort($ordered, static function (array $a, array $b): int {
            return $a[0] <=> $b[0] ?: $a[1] <=> $b[1];
        });

        return array_column($ordered, 2);
    }

    private function logStreetFailure(\Throwable $e, int $storeId): void
    {
        if ($this->config->isLoggingEnabled($storeId)) {
            $this->logger->warning(
                '[PigeonExpress] Street lookup failed, continuing without street_id: ' . $e->getMessage()
            );
        }
    }

    // -- Parsing -------------------------------------------------------------

    /**
     * Where block, entrance, floor and apartment are recognised.
     *
     * Two rules keep these honest. Longest alternative first, otherwise `ap`
     * matches inside `apartment` and captures a fragment of the word. And a
     * leading word boundary, otherwise `et` matches inside `Street` and turns a
     * house number into a floor.
     */
    /**
     * The street type Bulgarian addresses lead with. Captured rather than only
     * matched, because which type it was decides between two streets of the
     * same name.
     */
    private const TYPE_PREFIX_PATTERN = '/^\s*(улица|булевард|площад|квартал|жилищен\s+комплекс|ж\.?\s*к\.?'
        . '|ул|бул|пл|кв|бл|street|boulevard|square|str|blvd|ul|bul)\s*\.?\s*/ui';

    /** What each of those is called in the carrier's own data. */
    private const TYPE_ALIASES = [
        'улица'     => 'улица',
        'ул'        => 'улица',
        'street'    => 'улица',
        'str'       => 'улица',
        'ul'        => 'улица',
        'булевард'  => 'булевард',
        'бул'       => 'булевард',
        'boulevard' => 'булевард',
        'blvd'      => 'булевард',
        'bul'       => 'булевард',
        'площад'    => 'площад',
        'пл'        => 'площад',
        'square'    => 'площад',
        'квартал'   => 'квартал',
        'кв'        => 'квартал',
        'жк'        => 'квартал',
        'жилищенкомплекс' => 'квартал',
    ];

    private const COMPONENT_PATTERNS = [
        'building_number' => '/\b(?:блок|block|бл|bl)\.?\s*([0-9]+[A-Za-zА-Яа-я]?)\b/ui',
        'entrance'        => '/\b(?:вход|entrance|вх|vh|ent)\.?\s*([0-9A-Za-zА-Яа-я]{1,3})\b/ui',
        'floor'           => '/\b(?:етаж|floor|ет|et|fl)\.?\s*([0-9]{1,3})\b/ui',
        'apartment'       => '/\b(?:апартамент|apartment|apt|ап|ap)\.?\s*([0-9A-Za-zА-Яа-я]{1,5})\b/ui',
    ];

    /**
     * Pull block, entrance, floor and apartment out of the address text.
     *
     * Bulgarian addresses routinely carry these as abbreviations, and the API has
     * a dedicated field for each. Left inside a free text blob the courier has to
     * read them; sent structured, the carrier can route on them.
     *
     * @return array<string, string>
     */
    public function extractAddressComponents(string $address): array
    {
        if ($address === '') {
            return [];
        }

        $components = [];
        foreach (self::COMPONENT_PATTERNS as $field => $pattern) {
            if (preg_match($pattern, $address, $matches)) {
                $value = trim($matches[1]);
                if ($value !== '') {
                    $components[$field] = $value;
                }
            }
        }

        return $components;
    }

    /**
     * The street part of a line that also carries block, floor and the rest.
     *
     * Customers routinely put the whole address on one line. Split naively, the
     * street name comes out as "Витоша 15, бл. 5, вх. А, ет. 3, ап." and the
     * house number as the flat number, so the carrier matches nothing and falls
     * back to whatever address it holds for the phone. Cutting at the first
     * component keeps the street a street.
     */
    public function cutAtAddressComponents(string $streetLine): string
    {
        $cut = null;

        foreach (self::COMPONENT_PATTERNS as $pattern) {
            if (preg_match($pattern, $streetLine, $matches, PREG_OFFSET_CAPTURE)) {
                $offset = (int) $matches[0][1];
                if ($cut === null || $offset < $cut) {
                    $cut = $offset;
                }
            }
        }

        if ($cut === null) {
            return trim($streetLine);
        }

        return trim(substr($streetLine, 0, $cut), " \t\n\r\0\x0B,;-");
    }

    /**
     * Free text fallback, never shorter than the API's three character minimum.
     *
     * The API rejects additional_info under three characters whenever street_id
     * is absent, which is exactly when the address is weakest.
     */
    public function buildAdditionalInfo(string $wholeAddress, string $streetName): string
    {
        foreach ([$wholeAddress, $streetName] as $candidate) {
            $candidate = trim($candidate);
            if (mb_strlen($candidate) >= 3) {
                return mb_substr($candidate, 0, 255);
            }
        }

        return 'Address';
    }

    /**
     * @return array{0:string,1:string}
     */
    public function extractStreetNameAndNumber(string $street): array
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

    /**
     * Drop the street type that Bulgarian addresses lead with.
     *
     * Pigeon Express keeps the type in its own field, so a search has to be by
     * bare name. Customers type "ул. Витоша", the carrier holds "ВИТОША", and
     * the two never match until the prefix is removed.
     */
    public function stripStreetTypePrefix(string $streetName): string
    {
        $stripped = preg_replace(self::TYPE_PREFIX_PATTERN, '', $streetName);

        $stripped = trim((string) $stripped);

        // Never strip the whole thing: a street genuinely called "Булевард"
        // would otherwise disappear.
        return $stripped !== '' ? $stripped : trim($streetName);
    }

    // -- Plumbing ------------------------------------------------------------

    /**
     * @return \Magento\Framework\DB\Adapter\AdapterInterface
     */
    private function cityConnection()
    {
        return $this->cityCollectionFactory->create()->getResource()->getConnection();
    }

    private function cityTable(): string
    {
        return $this->cityCollectionFactory->create()->getResource()->getMainTable();
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '_', '%'], ['\\\\', '\_', '\%'], $value);
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
}
