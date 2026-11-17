<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Courier;

use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Model\Courier\PickupDate;

/**
 * Today while same-day requests are open, else the next working day.
 */
class PickupDateTest extends TestCase
{
    public function testBeforeTheCutoffTodayIsProposed(): void
    {
        $proposal = PickupDate::propose(new \DateTimeImmutable('2026-11-16 12:20'), '15:00');

        self::assertSame('2026-11-16', $proposal['date']);
        self::assertTrue($proposal['today_open']);
        self::assertSame(160, $proposal['minutes_left']);
    }

    public function testAfterTheCutoffTheNextWorkingDayIsProposed(): void
    {
        $proposal = PickupDate::propose(new \DateTimeImmutable('2026-11-16 15:00'), '15:00');

        self::assertSame('2026-11-17', $proposal['date']);
        self::assertFalse($proposal['today_open']);
        self::assertTrue($proposal['today_working']);
    }

    public function testFridayEveningAndTheWeekendGoToMonday(): void
    {
        self::assertSame('2026-11-23', PickupDate::propose(new \DateTimeImmutable('2026-11-20 16:00'), '15:00')['date']);
        $saturday = PickupDate::propose(new \DateTimeImmutable('2026-11-21 09:00'), '15:00');
        self::assertSame('2026-11-23', $saturday['date']);
        self::assertFalse($saturday['today_working']);
    }
}
