<?php
/**
 * Office working hours as a short week summary, as WooCommerce shows them.
 *
 * Input is what the location sync stores per office: a list of
 * {day, open, close, drop_off_until?} with English lowercase day names and
 * "HH:MM" times.
 *
 * - Days next to each other with the same hours are one group:
 *   "Mon-Fri 09:00-18:00, Sat 09:00-13:00, Sun closed".
 * - A day listed more than once (a lunch break) shows each stretch in time
 *   order: "Mon-Fri 09:00-12:00, 13:00-18:00".
 * - A day the data does not list is closed, as long as at least one day is
 *   listed. No usable day at all gives an empty string, so nothing is shown
 *   rather than "closed every day".
 * - A close of "00:00" after a later open is midnight and reads "24:00".
 * - Open "00:00" with a close of "00:00", "23:59" or "24:00" is open all day.
 * - An entry whose time is not HH:MM is skipped.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Location;

class WorkingHours
{
    /** Monday = 1 ... Sunday = 7. */
    private const DAYS = [
        'monday'    => 1,
        'tuesday'   => 2,
        'wednesday' => 3,
        'thursday'  => 4,
        'friday'    => 5,
        'saturday'  => 6,
        'sunday'    => 7,
    ];

    /**
     * Week summary for a stored hours value, or an empty string.
     *
     * @param mixed $raw JSON string or decoded list, as stored
     */
    public function summary($raw): string
    {
        $days = $this->parse($raw);
        if ($days === []) {
            return '';
        }

        $labels = [];
        for ($d = 1; $d <= 7; $d++) {
            $labels[$d] = isset($days[$d]) ? $this->dayLabel($days[$d]['hours']) : (string) __('closed');
        }

        $parts = [];
        $start = 1;
        for ($d = 2; $d <= 8; $d++) {
            if ($d <= 7 && $labels[$d] === $labels[$start]) {
                continue;
            }
            $parts[] = $this->dayRange($start, $d - 1) . ' ' . $labels[$start];
            $start = $d;
        }

        return implode(', ', $parts);
    }

    /**
     * The list to store, from what the API sends: a list of day entries, or
     * an object keyed by day name. Null when there is nothing usable.
     *
     * @param mixed $raw
     * @return array<int, array{day:string, open:string, close:string, drop_off_until?:string}>|null
     */
    public function normalise($raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $entries = [];
        foreach ($raw as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            // Keyed by day: {"monday": {"open": .., "close": ..}}.
            if (is_string($key) && isset(self::DAYS[strtolower($key)])) {
                $value = ['day' => strtolower($key)] + $value;
            }
            $day = strtolower(trim((string) ($value['day'] ?? '')));
            $open = $this->clock($value['open'] ?? null);
            $close = $this->clock($value['close'] ?? null);
            if (!isset(self::DAYS[$day]) || $open === '' || $close === '') {
                continue;
            }
            $entry = ['day' => $day, 'open' => $open, 'close' => $close];
            $dropOff = $this->clock($value['drop_off_until'] ?? null);
            if ($dropOff !== '') {
                $entry['drop_off_until'] = $dropOff;
            }
            $entries[] = $entry;
        }

        return $entries !== [] ? $entries : null;
    }

    /**
     * @param mixed $raw
     * @return array<int, array{hours: array<int, array{0: string, 1: string}>}>
     */
    private function parse($raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        $entries = $this->normalise($raw);
        if ($entries === null) {
            return [];
        }

        $days = [];
        foreach ($entries as $entry) {
            $day = self::DAYS[$entry['day']];
            // Keyed by the stretch, so one listed twice shows once and the
            // keys sort in time order.
            $days[$day]['hours'][$entry['open'] . '-' . $entry['close']] = [$entry['open'], $entry['close']];
        }

        foreach ($days as $day => $data) {
            ksort($data['hours']);
            $days[$day]['hours'] = array_values($data['hours']);
        }

        return $days;
    }

    /**
     * @param mixed $value
     */
    private function clock($value): string
    {
        $value = is_string($value) ? trim($value) : '';
        if (!preg_match('/^([01]?\d|2[0-4]):([0-5]\d)(:\d\d)?$/', $value, $m)) {
            return '';
        }

        return sprintf('%02d:%s', (int) $m[1], $m[2]);
    }

    /**
     * @param array<int, array{0: string, 1: string}> $hours
     */
    private function dayLabel(array $hours): string
    {
        $parts = [];
        foreach ($hours as $stretch) {
            if ($this->isAllDay($stretch[0], $stretch[1])) {
                return (string) __('open 24 hours');
            }
            $parts[] = $stretch[0] . '-' . ($stretch[1] === '00:00' ? '24:00' : $stretch[1]);
        }

        return implode(', ', $parts);
    }

    private function isAllDay(string $open, string $close): bool
    {
        return $open === '00:00' && in_array($close, ['00:00', '23:59', '24:00'], true);
    }

    private function dayRange(int $from, int $to): string
    {
        return $from === $to ? $this->dayName($from) : $this->dayName($from) . '-' . $this->dayName($to);
    }

    private function dayName(int $day): string
    {
        $names = [1 => __('Mon'), 2 => __('Tue'), 3 => __('Wed'), 4 => __('Thu'), 5 => __('Fri'), 6 => __('Sat')];

        return (string) ($names[$day] ?? __('Sun'));
    }
}
