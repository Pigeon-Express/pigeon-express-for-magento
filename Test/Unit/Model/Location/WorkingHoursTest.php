<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Location;

use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Model\Location\WorkingHours;

class WorkingHoursTest extends TestCase
{
    /** @var WorkingHours */
    private $hours;

    protected function setUp(): void
    {
        $this->hours = new WorkingHours();
    }

    public function testEqualDaysNextToEachOtherAreOneGroup(): void
    {
        $raw = $this->week(['09:00', '18:00'], 5);
        $raw[] = ['day' => 'saturday', 'open' => '09:00', 'close' => '13:00'];

        $this->assertSame('Mon-Fri 09:00-18:00, Sat 09:00-13:00, Sun closed', $this->hours->summary($raw));
    }

    public function testALunchBreakShowsBothStretchesInTimeOrder(): void
    {
        $raw = [
            ['day' => 'monday', 'open' => '13:00', 'close' => '18:00'],
            ['day' => 'monday', 'open' => '09:00', 'close' => '12:00'],
        ];

        $this->assertSame('Mon 09:00-12:00, 13:00-18:00, Tue-Sun closed', $this->hours->summary($raw));
    }

    public function testMidnightCloseReadsTwentyFour(): void
    {
        $this->assertSame('Mon-Sun 09:00-24:00', $this->hours->summary($this->week(['09:00', '00:00'], 7)));
    }

    public function testOpenAllDay(): void
    {
        $this->assertSame('Mon-Sun open 24 hours', $this->hours->summary($this->week(['00:00', '23:59'], 7)));
    }

    public function testStoredJsonIsRead(): void
    {
        $json = (string) json_encode($this->week(['08:00', '17:00'], 7));

        $this->assertSame('Mon-Sun 08:00-17:00', $this->hours->summary($json));
    }

    public function testNothingUsableShowsNothing(): void
    {
        $this->assertSame('', $this->hours->summary(null));
        $this->assertSame('', $this->hours->summary('[]'));
        $this->assertSame('', $this->hours->summary([['day' => 'monday', 'open' => 'soon', 'close' => '18:00']]));
    }

    public function testAKeyedAnswerIsNormalisedToAList(): void
    {
        $list = $this->hours->normalise(['monday' => ['open' => '9:00', 'close' => '18:00'], 'tuesday' => null]);

        $this->assertSame([['day' => 'monday', 'open' => '09:00', 'close' => '18:00']], $list);
    }

    /**
     * @param string[] $times
     * @return array<int, array<string, string>>
     */
    private function week(array $times, int $days): array
    {
        $names = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        $out = [];
        foreach (array_slice($names, 0, $days) as $day) {
            $out[] = ['day' => $day, 'open' => $times[0], 'close' => $times[1]];
        }

        return $out;
    }
}
