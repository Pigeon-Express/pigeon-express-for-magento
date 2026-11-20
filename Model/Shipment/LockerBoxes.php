<?php
/**
 * Free boxes of a parcel locker, for the admin shipment form: whether a box
 * the parcel fits in is expected to be free tomorrow, and the boxes per size.
 *
 * Copied from the WooCommerce plugin (Labels/LockerBoxes.php). The carrier's
 * numbers are a forecast (parcels dropped off at the locker take boxes nobody
 * can foresee), so the panel says so and never blocks a shipment on them. The
 * answer is kept for a few minutes per environment and locker: the form asks
 * again whenever the parcel's size changes, and the boxes do not change that
 * fast.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Shipment;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use PigeonExpress\Shipping\Api\LocationSearchInterface;
use PigeonExpress\Shipping\Api\ShipmentClientInterface;
use PigeonExpress\Shipping\Exception\ApiException;
use PigeonExpress\Shipping\Model\Environment;

class LockerBoxes
{
    private const CACHE_PREFIX = 'pigeonexpress_locker_boxes_';

    /** Five minutes. */
    public const CACHE_TTL = 300;

    /** The forecast says a box this parcel fits in is free tomorrow. */
    public const VERDICT_FREE = 'free';

    /** It says none is. */
    public const VERDICT_NONE = 'none';

    /** No box of this locker takes the parcel at all. */
    public const VERDICT_NO_FIT = 'no_fit';

    /** The locker is out of service. */
    public const VERDICT_BLOCKED = 'blocked';

    /** Nothing to say about this parcel: its size is not known. */
    public const VERDICT_UNKNOWN = '';

    /** @var ShipmentClientInterface */
    private $client;

    /** @var LocationSearchInterface */
    private $locations;

    /** @var CacheInterface */
    private $cache;

    /** @var Environment */
    private $environment;

    /** @var TimezoneInterface */
    private $timezone;

    /** @var ResolverInterface */
    private $localeResolver;

    public function __construct(
        ShipmentClientInterface $client,
        LocationSearchInterface $locations,
        CacheInterface $cache,
        Environment $environment,
        TimezoneInterface $timezone,
        ResolverInterface $localeResolver
    ) {
        $this->client = $client;
        $this->locations = $locations;
        $this->cache = $cache;
        $this->environment = $environment;
        $this->timezone = $timezone;
        $this->localeResolver = $localeResolver;
    }

    /**
     * The locker's boxes and forecast, trimmed (see clean()), from the cache
     * or from Pigeon Express.
     *
     * @param int $entityId The locker's row in the synced list (pigeonexpress_aps.entity_id)
     * @return array{is_blocked: bool, blocked_until: string, box_sizes: array<int, array{size: string, length: int, width: int, height: int, max_weight: float|null}>, today: array<string, int>, tomorrow: array<string, int>}
     * @throws ApiException 404 for a locker Pigeon Express does not list; status 0 without a code
     */
    public function availability(int $entityId, int $storeId = 0): array
    {
        // Looked up by code, never by id; an unknown locker has none, and the
        // client says so without asking the carrier.
        $code = trim((string) $this->locations->getApsCode($entityId));
        if ($code === '') {
            throw new ApiException(__('This parcel locker has no code to look it up by.'));
        }

        // Keyed by environment: a locker list of one side is no answer for the other.
        $key = self::CACHE_PREFIX . md5($this->environment->current() . '|' . $code);
        $cached = json_decode((string) $this->cache->load($key), true);
        if (is_array($cached) && isset($cached['box_sizes'])) {
            return $cached;
        }

        $clean = self::clean($this->client->lockerAvailability($code, $storeId));
        $this->cache->save((string) json_encode($clean), $key, [], self::CACHE_TTL);

        return $clean;
    }

    /**
     * What the panel uses of the carrier's answer, in a fixed shape.
     *
     * @param array<string, mixed> $data ShipmentClientInterface::lockerAvailability(), or the carrier's
     *                                   own `data` (availability.today / availability.tomorrow)
     * @return array{is_blocked: bool, blocked_until: string, box_sizes: array<int, array{size: string, length: int, width: int, height: int, max_weight: float|null}>, today: array<string, int>, tomorrow: array<string, int>}
     */
    public static function clean(array $data): array
    {
        $sizes = [];

        foreach ((array) ($data['box_sizes'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $size = strtolower((string) preg_replace('/[^A-Za-z0-9_]/', '', (string) ($row['size'] ?? '')));
            if ($size === '') {
                continue;
            }

            $sizes[] = [
                'size'       => $size,
                'length'     => max(0, (int) ($row['length'] ?? 0)),
                'width'      => max(0, (int) ($row['width'] ?? 0)),
                'height'     => max(0, (int) ($row['height'] ?? 0)),
                'max_weight' => isset($row['max_weight']) && is_numeric($row['max_weight'])
                    ? (float) $row['max_weight'] : null,
            ];
        }

        $availability = isset($data['availability']) && is_array($data['availability']) ? $data['availability'] : [];

        return [
            'is_blocked'    => !empty($data['is_blocked']),
            'blocked_until' => is_scalar($data['blocked_until'] ?? null)
                ? trim(strip_tags((string) $data['blocked_until'])) : '',
            'box_sizes'     => $sizes,
            'today'         => self::freeCounts($data['today'] ?? ($availability['today'] ?? []), $sizes),
            'tomorrow'      => self::freeCounts($data['tomorrow'] ?? ($availability['tomorrow'] ?? []), $sizes),
        ];
    }

    /**
     * @param mixed $day
     * @param array<int, array<string, mixed>> $sizes
     * @return array<string, int> size => free boxes
     */
    private static function freeCounts($day, array $sizes): array
    {
        $out = [];

        foreach ($sizes as $row) {
            $size = (string) $row['size'];
            $out[$size] = is_array($day) && isset($day[$size]) && is_numeric($day[$size])
                ? max(0, (int) $day[$size]) : 0;
        }

        return $out;
    }

    /**
     * Whether a parcel goes into a box turned any way round: the longest side
     * against the longest, and so on.
     *
     * @param array<int, float|int> $parcel Three sides, cm
     * @param array<int, float|int> $box Three inner sides, cm
     */
    public static function fits(array $parcel, array $box): bool
    {
        $parcel = array_map('floatval', array_values($parcel));
        $box = array_map('floatval', array_values($box));

        if (count($parcel) !== 3 || count($box) !== 3) {
            return false;
        }

        rsort($parcel);
        rsort($box);

        foreach ($parcel as $i => $side) {
            if ($side > $box[$i]) {
                return false;
            }
        }

        return true;
    }

    /**
     * The box sizes of the locker this parcel goes into: by its sides, and by
     * its weight when that is known and the box has a limit.
     *
     * @param array<string, mixed> $locker clean() output
     * @param array<int, float|int>|null $parcel Three sides, cm; null when not known
     * @return string[]
     */
    public static function fittingSizes(array $locker, ?array $parcel, float $weight): array
    {
        if ($parcel === null) {
            return [];
        }

        $out = [];

        foreach ((array) $locker['box_sizes'] as $row) {
            $box = [(int) $row['length'], (int) $row['width'], (int) $row['height']];

            if (!self::fits($parcel, $box)) {
                continue;
            }

            if ($weight > 0 && $row['max_weight'] !== null && $weight > (float) $row['max_weight']) {
                continue;
            }

            $out[] = (string) $row['size'];
        }

        return $out;
    }

    /**
     * What the forecast says for this parcel: one of the VERDICT_* values.
     *
     * @param array<string, mixed> $locker clean() output
     * @param array<int, float|int>|null $parcel Three sides, cm; null when not known
     */
    public static function verdict(array $locker, ?array $parcel, float $weight): string
    {
        if (!empty($locker['is_blocked'])) {
            return self::VERDICT_BLOCKED;
        }

        if ($parcel === null) {
            return self::VERDICT_UNKNOWN;
        }

        $sizes = self::fittingSizes($locker, $parcel, $weight);

        if ($sizes === []) {
            return self::VERDICT_NO_FIT;
        }

        foreach ($sizes as $size) {
            if ((int) ($locker['tomorrow'][$size] ?? 0) > 0) {
                return self::VERDICT_FREE;
            }
        }

        return self::VERDICT_NONE;
    }

    /**
     * The parcel the verdict is for: one package of known size, else null (a
     * locker takes one parcel; with several, or a side missing, the boxes are
     * listed without a verdict).
     *
     * @param array<int, float> $sides Length, width, height in cm
     * @return array<int, float>|null
     */
    public static function parcel(int $packages, array $sides): ?array
    {
        $sides = array_values(array_map('floatval', $sides));

        return $packages === 1 && count($sides) === 3 && min($sides) > 0.0 ? $sides : null;
    }

    /**
     * Everything the form prints, already worded.
     *
     * @param array<string, mixed> $locker clean() output
     * @param array<int, float|int>|null $parcel Three sides, cm; null when not known
     * @return array{verdict: string, message: string, detail: string, rows: array<int, array{size: string, inside: string, today: int, tomorrow: int, fits: bool}>}
     */
    public function view(array $locker, ?array $parcel, float $weight): array
    {
        $verdict = self::verdict($locker, $parcel, $weight);
        $fitting = self::fittingSizes($locker, $parcel, $weight);

        switch ($verdict) {
            case self::VERDICT_BLOCKED:
                $until = $this->formatDate((string) $locker['blocked_until']);
                $message = $until !== ''
                    ? (string) __('This parcel locker is out of service until %1.', $until)
                    : (string) __('This parcel locker is out of service for now.');
                break;
            case self::VERDICT_FREE:
                $message = (string) __('A free box is expected tomorrow');
                break;
            case self::VERDICT_NONE:
                $message = (string) __('No free box is expected tomorrow. Consider an office or an address.');
                break;
            case self::VERDICT_NO_FIT:
                $message = (string) __(
                    'The parcel does not fit any box of this parcel locker. Consider an office or an address.'
                );
                break;
            default:
                $message = '';
        }

        // One short line under the verdict: which boxes take the parcel, or,
        // when none does, how big the biggest one is.
        $detail = '';

        if ($verdict === self::VERDICT_FREE || $verdict === self::VERDICT_NONE) {
            $names = array_map([self::class, 'sizeLabel'], $fitting);
            $detail = count($names) === 1
                ? (string) __('Suitable box: %1', $names[0])
                : (string) __('Suitable boxes: %1', implode(', ', $names));
        } elseif ($verdict === self::VERDICT_NO_FIT) {
            $largest = $this->largestBox($locker);

            if ($largest !== '') {
                $detail = (string) __('The largest box is %1.', $largest);
            }
        }

        $rows = [];

        // A blocked locker has no free boxes to list.
        if ($verdict !== self::VERDICT_BLOCKED) {
            foreach ((array) $locker['box_sizes'] as $row) {
                $size = (string) $row['size'];

                $rows[] = [
                    'size'     => self::sizeLabel($size),
                    'inside'   => $this->insideText($row),
                    'today'    => (int) ($locker['today'][$size] ?? 0),
                    'tomorrow' => (int) ($locker['tomorrow'][$size] ?? 0),
                    'fits'     => in_array($size, $fitting, true),
                ];
            }
        }

        return [
            'verdict' => $verdict,
            'message' => $message,
            'detail'  => $detail,
            'rows'    => $rows,
        ];
    }

    /**
     * Whether the view knows the parcel's size, so each box can be told apart
     * as one it fits in (bold) or not (grey).
     *
     * @param array{verdict: string} $view
     */
    public static function isSized(array $view): bool
    {
        return in_array($view['verdict'], [self::VERDICT_FREE, self::VERDICT_NONE, self::VERDICT_NO_FIT], true);
    }

    /** A box size as the merchant reads it. */
    public static function sizeLabel(string $size): string
    {
        switch ($size) {
            case 'small':
                return (string) __('Small box');
            case 'medium':
                return (string) __('Medium box');
            case 'large':
                return (string) __('Large box');
            default:
                return ucfirst(str_replace('_', ' ', $size));
        }
    }

    /**
     * The inner size of a box as the merchant reads it.
     *
     * @param array<string, mixed> $row One of clean()'s box_sizes
     */
    private function insideText(array $row): string
    {
        $inside = (string) __(
            '%1 x %2 x %3 cm',
            (string) (int) $row['length'],
            (string) (int) $row['width'],
            (string) (int) $row['height']
        );

        if ($row['max_weight'] !== null) {
            $inside = (string) __('%1, up to %2 kg', $inside, $this->weight((float) $row['max_weight']));
        }

        return $inside;
    }

    /**
     * The inner size of the biggest box of the locker (by volume), or '' when
     * it lists none.
     *
     * @param array<string, mixed> $locker clean() output
     */
    private function largestBox(array $locker): string
    {
        $best = null;

        foreach ((array) $locker['box_sizes'] as $row) {
            $volume = (int) $row['length'] * (int) $row['width'] * (int) $row['height'];

            if ($best === null || $volume > $best[0]) {
                $best = [$volume, $row];
            }
        }

        return $best === null ? '' : $this->insideText($best[1]);
    }

    /** A weight in the admin's locale: no decimals when whole, else one. */
    private function weight(float $value): string
    {
        $decimals = floor($value) === $value ? 0 : 1;

        try {
            $formatter = new \NumberFormatter((string) $this->localeResolver->getLocale(), \NumberFormatter::DECIMAL);
            $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
            $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
            $text = $formatter->format($value);
            if (is_string($text) && $text !== '') {
                return $text;
            }
        } catch (\Throwable $e) {
            // Plain digits below.
        }

        return number_format($value, $decimals, '.', '');
    }

    private function formatDate(string $value): string
    {
        $timestamp = $value !== '' ? strtotime($value) : false;
        if ($timestamp === false) {
            return '';
        }

        try {
            return (string) $this->timezone->formatDateTime(
                (new \DateTime('@' . $timestamp)),
                \IntlDateFormatter::MEDIUM,
                \IntlDateFormatter::SHORT
            );
        } catch (\Throwable $e) {
            return $value;
        }
    }
}
