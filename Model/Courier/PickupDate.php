<?php
/**
 * The pickup date a new courier request proposes: today while same-day
 * requests are open, otherwise the next working day (Monday to Friday;
 * Pigeon Express refuses a public holiday itself). The cutoff only chooses
 * what is proposed; Pigeon Express decides what it can serve.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Courier;

class PickupDate
{
    public static function isWorkingDay(\DateTimeImmutable $day): bool
    {
        return (int) $day->format('N') <= 5;
    }

    /**
     * @return array{date:string, today:string, today_open:bool, today_working:bool, minutes_left:int, cutoff:string}
     */
    public static function propose(\DateTimeImmutable $now, string $cutoff): array
    {
        $minutes = (int) $now->format('G') * 60 + (int) $now->format('i');
        $working = self::isWorkingDay($now);
        $left = CourierSettings::minutes($cutoff) - $minutes;
        $open = $working && $left > 0;
        $date = $now;

        if (!$open) {
            do {
                $date = $date->modify('+1 day');
            } while (!self::isWorkingDay($date));
        }

        return [
            'date'          => $date->format('Y-m-d'),
            'today'         => $now->format('Y-m-d'),
            'today_open'    => $open,
            'today_working' => $working,
            'minutes_left'  => $open ? $left : 0,
            'cutoff'        => $cutoff,
        ];
    }
}
