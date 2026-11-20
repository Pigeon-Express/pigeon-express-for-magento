<?php
/**
 * The offices or parcel lockers nearest the shopper, for the top of the
 * checkout picker (when "Nearest office" is on).
 *
 * The browser gives the point; it is rounded to three decimals (about 100 m)
 * before it is used for anything, so the carrier, the cache key and the
 * distance shown all work from the same coarse point and nothing more precise
 * ever leaves the request. The carrier's answer (ids and distances, never the
 * point) is cached briefly under a hash of that rounded point. The point is
 * never stored with the quote or the order and never logged.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Location;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Api\LocationSearchInterface;
use PigeonExpress\Shipping\Api\LocationsClientInterface;
use PigeonExpress\Shipping\Exception\ApiException;
use PigeonExpress\Shipping\Exception\RateLimitedException;
use PigeonExpress\Shipping\Model\Environment;

class NearestLocations
{
    /** How many are shown on top of the picker. */
    public const SHOWN = 5;

    /** Decimals the point is rounded to (three decimals is about 100 m). */
    public const PRECISION = 3;

    /**
     * How many the carrier is asked for. More than are shown: it may list a
     * location the last sync has not brought into the table yet, and the
     * picker can only offer what SetLocation will accept.
     */
    public const ASKED = 20;

    /**
     * Same point, same answer for a while: shoppers in one neighbourhood share
     * it, and a checkout that redraws itself does not ask again.
     */
    public const CACHE_TTL = 1800;

    /** Carrier lookups one cart may cause per minute (cache hits are free). */
    public const LOOKUPS_PER_MINUTE = 10;

    /**
     * And one address, however many carts it opens: a cart costs nothing to
     * get. Above the cart cap so a household or an office behind one address
     * can still use the checkout.
     */
    public const IP_LOOKUPS_PER_MINUTE = 20;

    /** And the whole shop, whatever the number of carts or addresses. */
    public const SITE_LOOKUPS_PER_MINUTE = 60;
    public const SITE_LOOKUPS_PER_HOUR = 600;

    /** No carrier request at all for this long after the carrier answered 429. */
    public const BACKOFF = 300;

    /** Every entry this class writes carries it, so they can be cleaned together. */
    public const CACHE_TAG = 'PIGEONEXPRESS_NEAREST';

    private const CACHE_PREFIX = 'pigeonexpress_nearest_';
    private const THROTTLE_PREFIX = 'pigeonexpress_nearest_rate_';
    private const BACKOFF_KEY = 'pigeonexpress_nearest_backoff';

    /** Row keys the shopper's page gets back: the picker row and nothing else. */
    private const ROW_KEYS = ['id', 'name', 'address', 'city', 'postcode', 'region_id', 'type', 'hours'];

    /** @var LocationsClientInterface */
    private $client;

    /** @var LocationSearchInterface */
    private $locationSearch;

    /** @var CacheInterface */
    private $cache;

    /** @var Environment */
    private $environment;

    /** @var EncryptorInterface */
    private $encryptor;

    /** @var ResolverInterface */
    private $localeResolver;

    /** @var DateTime */
    private $dateTime;

    public function __construct(
        LocationsClientInterface $client,
        LocationSearchInterface $locationSearch,
        CacheInterface $cache,
        Environment $environment,
        EncryptorInterface $encryptor,
        ResolverInterface $localeResolver,
        DateTime $dateTime
    ) {
        $this->client = $client;
        $this->locationSearch = $locationSearch;
        $this->cache = $cache;
        $this->environment = $environment;
        $this->encryptor = $encryptor;
        $this->localeResolver = $localeResolver;
        $this->dateTime = $dateTime;
    }

    /**
     * The nearest locations of a type that the checkout can offer, nearest
     * first, at most SHOWN, each as the picker row plus its `distance` text.
     *
     * @param string $type ConfigInterface::DELIVERY_TYPE_OFFICE or DELIVERY_TYPE_APS
     * @param string $cartKey Identifies the cart (session) the lookup is counted against
     * @param string $remoteAddress The shopper's address, only ever kept as a keyed hash
     * @return array<int, array{id: int, name: string, address: string, city: string, postcode: string, region_id: int|null, type: string, hours: string, distance: string}>
     * @throws NearestRefusedException
     */
    public function find(
        string $type,
        float $latitude,
        float $longitude,
        string $cartKey,
        string $remoteAddress,
        int $storeId = 0
    ): array {
        if ($type !== ConfigInterface::DELIVERY_TYPE_OFFICE && $type !== ConfigInterface::DELIVERY_TYPE_APS) {
            throw new NearestRefusedException(NearestRefusedException::INVALID, __('Unknown location type.'));
        }

        $point = self::roundPoint($latitude, $longitude);
        if ($point === null) {
            throw new NearestRefusedException(NearestRefusedException::INVALID, __('The location could not be read.'));
        }

        [$lat, $lng] = $point;

        // Keyed by environment: the two have different location ids.
        $cacheKey = self::CACHE_PREFIX . md5(
            $this->environment->current() . '|' . $type . '|' . sprintf('%.3F|%.3F', $lat, $lng)
        );
        $found = $this->readFound($cacheKey);

        if ($found === null) {
            $this->claimLookup($cartKey, $remoteAddress);
            $found = $this->askCarrier($type, $lat, $lng);
            $this->cache->save(
                (string) json_encode($found),
                $cacheKey,
                [self::CACHE_TAG],
                self::CACHE_TTL
            );
        }

        return $this->rows($type, $found, $storeId);
    }

    /**
     * The point rounded to PRECISION decimals, or null when it is not a point
     * on the globe.
     *
     * @return array{0: float, 1: float}|null
     */
    public static function roundPoint(float $latitude, float $longitude): ?array
    {
        if (!is_finite($latitude) || !is_finite($longitude)) {
            return null;
        }

        $lat = round($latitude, self::PRECISION);
        $lng = round($longitude, self::PRECISION);

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return null;
        }

        // No "-0" in a cache key or a request.
        return [$lat + 0.0 === 0.0 ? 0.0 : $lat, $lng + 0.0 === 0.0 ? 0.0 : $lng];
    }

    /**
     * A distance as the shopper reads it: "850 m" under a kilometre (to the
     * nearest 10 m, the point is only good to about 100 m anyway), "1.2 km"
     * with one decimal below 100 km, whole kilometres beyond. The decimal
     * mark follows the store language ("1,2 км" in Bulgarian).
     */
    public function formatDistance(?float $km): string
    {
        if ($km === null || !is_finite($km) || $km < 0) {
            return '';
        }

        $metres = (int) (round($km * 100) * 10);

        if ($metres < 1000) {
            return (string) __('%1 m', $this->number((float) max(10, $metres), 0));
        }

        return (string) __('%1 km', $this->number($km, round($km, 1) < 100 ? 1 : 0));
    }

    /**
     * The carrier's cached answer for a point, or null when there is none.
     *
     * @return array<int, array{id: string, km: float|null}>|null
     */
    private function readFound(string $cacheKey): ?array
    {
        $raw = $this->cache->load($cacheKey);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $found = json_decode($raw, true);

        return is_array($found) ? $found : null;
    }

    /**
     * Ask Pigeon Express, keeping only what is needed again: ids and
     * distances, no point.
     *
     * @return array<int, array{id: string, km: float|null}>
     * @throws NearestRefusedException
     */
    private function askCarrier(string $type, float $lat, float $lng): array
    {
        try {
            $items = $this->client->nearest($lat, $lng, $type, self::ASKED);
        } catch (ApiException $e) {
            // The carrier says it is being asked too much: leave it alone for a
            // while, for every shopper, instead of asking again.
            if ($e instanceof RateLimitedException || $e->getHttpStatus() === 429) {
                $this->cache->save((string) $this->now(), self::BACKOFF_KEY, [self::CACHE_TAG], self::BACKOFF);

                throw new NearestRefusedException(NearestRefusedException::BUSY, $this->busyPhrase(), $e);
            }

            throw new NearestRefusedException(
                NearestRefusedException::UNAVAILABLE,
                __('The nearest offices could not be found just now.'),
                $e
            );
        }

        $found = [];
        foreach ($items as $item) {
            $id = isset($item['id']) && is_scalar($item['id']) ? trim((string) $item['id']) : '';
            if ($id === '') {
                continue;
            }

            $found[] = [
                'id' => $id,
                'km' => isset($item['distance_km']) && is_numeric($item['distance_km'])
                    ? (float) $item['distance_km']
                    : null,
            ];
        }

        return $found;
    }

    /**
     * The local rows for the carrier's answer, in its order, with distances.
     *
     * Checked on every request, not cached: a sync can add or drop locations
     * while the carrier's answer is still cached.
     *
     * @param array<int, array{id: string, km: float|null}> $found
     * @return array<int, array<string, mixed>>
     */
    private function rows(string $type, array $found, int $storeId): array
    {
        $kmById = [];
        foreach ($found as $entry) {
            if (!is_array($entry) || !isset($entry['id']) || !is_scalar($entry['id'])) {
                continue;
            }
            $id = (string) $entry['id'];
            if (!array_key_exists($id, $kmById)) {
                $kmById[$id] = isset($entry['km']) && is_numeric($entry['km']) ? (float) $entry['km'] : null;
            }
        }

        if ($kmById === []) {
            return [];
        }

        $out = [];
        foreach ($this->locationSearch->rowsByApiIds($type, array_keys($kmById), $storeId) as $row) {
            $apiId = isset($row['api_id']) ? (string) $row['api_id'] : '';
            if ($apiId === '' || !array_key_exists($apiId, $kmById)) {
                continue;
            }

            $item = [];
            foreach (self::ROW_KEYS as $key) {
                $item[$key] = $row[$key] ?? null;
            }
            $item['distance'] = $this->formatDistance($kmById[$apiId]);
            $out[] = $item;

            if (count($out) >= self::SHOWN) {
                break;
            }
        }

        return $out;
    }

    /**
     * Count one carrier lookup against this cart's allowance, the address's
     * and the shop's. The endpoint is public, so a script sending new points
     * must not turn into as many carrier requests, and a script that opens
     * many carts must not use up everyone's allowance.
     *
     * Kept in short counters in the Magento cache, not in the session: this
     * request may run while the checkout is still saving the method just
     * chosen. The cache has no atomic increment, so under requests that land
     * at the same instant the counters are a soft limit (a few may slip
     * through); the per-hour site cap and the back-off bound the rest.
     *
     * @throws NearestRefusedException
     */
    private function claimLookup(string $cartKey, string $remoteAddress): void
    {
        // The carrier asked to be left alone: no request, and no count.
        $since = $this->cache->load(self::BACKOFF_KEY);
        if (is_string($since) && is_numeric($since) && $this->now() - (int) $since < self::BACKOFF) {
            throw new NearestRefusedException(NearestRefusedException::BUSY, $this->busyPhrase());
        }

        if (trim($cartKey) === '') {
            throw new NearestRefusedException(
                NearestRefusedException::NO_SESSION,
                __('The nearest locations are not available right now. Reload the page and try again.')
            );
        }

        $ok = $this->countLookup('c_' . md5($cartKey), self::LOOKUPS_PER_MINUTE, 60)
            && $this->countLookup('ip_' . $this->addressHash($remoteAddress), self::IP_LOOKUPS_PER_MINUTE, 60)
            && $this->countLookup('site_h', self::SITE_LOOKUPS_PER_HOUR, 3600)
            && $this->countLookup('site_m', self::SITE_LOOKUPS_PER_MINUTE, 60);

        if (!$ok) {
            throw new NearestRefusedException(NearestRefusedException::BUSY, $this->busyPhrase());
        }
    }

    /**
     * Add one lookup to the current window of a counter, unless it is full.
     * Fixed windows: the key carries the window number.
     */
    private function countLookup(string $name, int $limit, int $window): bool
    {
        $key = self::THROTTLE_PREFIX . $name . '_' . (int) floor($this->now() / $window);
        $raw = $this->cache->load($key);
        $count = is_string($raw) && is_numeric($raw) ? (int) $raw : 0;

        if ($count >= $limit) {
            return false;
        }

        $this->cache->save((string) ($count + 1), $key, [self::CACHE_TAG], $window);

        return true;
    }

    /**
     * The shopper's address as a keyed hash (the store's own key), so the
     * cache never holds the address itself.
     */
    private function addressHash(string $remoteAddress): string
    {
        try {
            return $this->encryptor->hash($remoteAddress);
        } catch (\Throwable $e) {
            return hash('sha256', 'pigeonexpress|' . $remoteAddress);
        }
    }

    private function busyPhrase(): \Magento\Framework\Phrase
    {
        return __('Too many searches at once. Wait a moment and try again.');
    }

    private function now(): int
    {
        return (int) $this->dateTime->gmtTimestamp();
    }

    /**
     * A number written the store language's way.
     */
    private function number(float $value, int $decimals): string
    {
        $locale = (string) $this->localeResolver->getLocale();

        if ($locale !== '' && class_exists(\NumberFormatter::class)) {
            $formatter = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
            $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
            $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
            $formatter->setAttribute(\NumberFormatter::ROUNDING_MODE, \NumberFormatter::ROUND_HALFUP);
            $text = $formatter->format($value);

            if (is_string($text) && $text !== '') {
                return $text;
            }
        }

        return number_format($value, $decimals, '.', ',');
    }
}
