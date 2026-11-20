<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Shipment;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Api\LocationSearchInterface;
use PigeonExpress\Shipping\Api\ShipmentClientInterface;
use PigeonExpress\Shipping\Exception\ApiException;
use PigeonExpress\Shipping\Model\Environment;
use PigeonExpress\Shipping\Model\Shipment\LockerBoxes;

/**
 * Free boxes of a locker for one parcel: which boxes take it (turned any way,
 * within their weight), the verdict, and what the form prints.
 */
class LockerBoxesTest extends TestCase
{
    /**
     * Three box sizes as the client answers them; tomorrow only a large one
     * is free.
     *
     * @return array<string, mixed>
     */
    private function locker(array $tomorrow = ['small' => 0, 'medium' => 0, 'large' => 2], bool $blocked = false): array
    {
        return LockerBoxes::clean([
            'code'          => '1132',
            'is_blocked'    => $blocked,
            'blocked_until' => $blocked ? '2026-11-21T10:00:00+02:00' : '',
            'box_sizes'     => [
                ['size' => 'small', 'length' => 40, 'width' => 30, 'height' => 10, 'max_weight' => 5, 'total' => 4],
                ['size' => 'medium', 'length' => 40, 'width' => 30, 'height' => 20, 'max_weight' => 10, 'total' => 4],
                ['size' => 'large', 'length' => 60, 'width' => 40, 'height' => 40, 'max_weight' => 20.5, 'total' => 2],
            ],
            'today'         => ['small' => 1, 'medium' => 0, 'large' => 1],
            'tomorrow'      => $tomorrow,
        ]);
    }

    private function boxes(?ShipmentClientInterface $client = null, ?CacheInterface $cache = null, ?string $code = '1132'): LockerBoxes
    {
        $locations = $this->createMock(LocationSearchInterface::class);
        $locations->method('getApsCode')->willReturn($code);
        $environment = $this->createMock(Environment::class);
        $environment->method('current')->willReturn('test');
        $locale = $this->createMock(ResolverInterface::class);
        $locale->method('getLocale')->willReturn('en_US');

        return new LockerBoxes(
            $client ?? $this->createMock(ShipmentClientInterface::class),
            $locations,
            $cache ?? $this->createMock(CacheInterface::class),
            $environment,
            $this->createMock(TimezoneInterface::class),
            $locale
        );
    }

    public function testAParcelFitsTurnedAnyWayRound(): void
    {
        self::assertTrue(LockerBoxes::fits([10, 40, 30], [40, 30, 10]));
        self::assertTrue(LockerBoxes::fits([30, 10, 40], [40, 30, 10]));
        self::assertFalse(LockerBoxes::fits([30.5, 10, 40], [40, 30, 10]));
        self::assertFalse(LockerBoxes::fits([41, 1, 1], [40, 30, 10]));
        self::assertFalse(LockerBoxes::fits([10, 10], [40, 30, 10]));
    }

    public function testBoxesAreChosenBySizeAndWeight(): void
    {
        $locker = $this->locker();

        self::assertSame(['small', 'medium', 'large'], LockerBoxes::fittingSizes($locker, [10, 10, 5], 0.0));
        self::assertSame(['medium', 'large'], LockerBoxes::fittingSizes($locker, [11, 11, 15], 2.0));
        // Too heavy for the small and the medium box.
        self::assertSame(['large'], LockerBoxes::fittingSizes($locker, [10, 10, 5], 12.0));
        self::assertSame([], LockerBoxes::fittingSizes($locker, [70, 10, 5], 1.0));
        self::assertSame([], LockerBoxes::fittingSizes($locker, null, 1.0));
    }

    public function testTheVerdictFollowsTomorrowsForecast(): void
    {
        self::assertSame(LockerBoxes::VERDICT_FREE, LockerBoxes::verdict($this->locker(), [50, 30, 30], 1.0));
        self::assertSame(
            LockerBoxes::VERDICT_NONE,
            LockerBoxes::verdict($this->locker(['small' => 3, 'medium' => 3, 'large' => 0]), [50, 30, 30], 1.0)
        );
        self::assertSame(LockerBoxes::VERDICT_NO_FIT, LockerBoxes::verdict($this->locker(), [80, 30, 30], 1.0));
        self::assertSame(LockerBoxes::VERDICT_UNKNOWN, LockerBoxes::verdict($this->locker(), null, 1.0));
        self::assertSame(LockerBoxes::VERDICT_BLOCKED, LockerBoxes::verdict($this->locker([], true), [10, 10, 10], 1.0));
    }

    public function testAFreeBoxSaysWhichBoxesTakeTheParcel(): void
    {
        $view = $this->boxes()->view($this->locker(), [20, 20, 15], 1.0);

        self::assertSame(LockerBoxes::VERDICT_FREE, $view['verdict']);
        self::assertSame('A free box is expected tomorrow', $view['message']);
        self::assertSame('Suitable boxes: Medium box, Large box', $view['detail']);
        self::assertSame([false, true, true], array_column($view['rows'], 'fits'));
        self::assertSame([0, 0, 2], array_column($view['rows'], 'tomorrow'));
        self::assertSame('40 x 30 x 10 cm, up to 5 kg', $view['rows'][0]['inside']);
        self::assertSame('60 x 40 x 40 cm, up to 20.5 kg', $view['rows'][2]['inside']);
        self::assertTrue(LockerBoxes::isSized($view));
    }

    public function testOneSuitableBoxIsNamedAlone(): void
    {
        $view = $this->boxes()->view($this->locker(['small' => 0, 'medium' => 0, 'large' => 0]), [50, 30, 30], 1.0);

        self::assertSame(LockerBoxes::VERDICT_NONE, $view['verdict']);
        self::assertSame('No free box is expected tomorrow. Consider an office or an address.', $view['message']);
        self::assertSame('Suitable box: Large box', $view['detail']);
    }

    public function testAParcelTooBigNamesTheLargestBox(): void
    {
        $view = $this->boxes()->view($this->locker(), [90, 30, 30], 1.0);

        self::assertSame(LockerBoxes::VERDICT_NO_FIT, $view['verdict']);
        self::assertStringContainsString('does not fit any box', $view['message']);
        self::assertSame('The largest box is 60 x 40 x 40 cm, up to 20.5 kg.', $view['detail']);
        self::assertSame([false, false, false], array_column($view['rows'], 'fits'));
    }

    public function testAnUnknownSizeListsTheBoxesWithoutAVerdict(): void
    {
        $view = $this->boxes()->view($this->locker(), null, 0.0);

        self::assertSame('', $view['message']);
        self::assertSame('', $view['detail']);
        self::assertCount(3, $view['rows']);
        self::assertFalse(LockerBoxes::isSized($view));
    }

    public function testABlockedLockerListsNoBoxes(): void
    {
        $view = $this->boxes()->view($this->locker([], true), [10, 10, 10], 1.0);

        self::assertSame(LockerBoxes::VERDICT_BLOCKED, $view['verdict']);
        self::assertSame([], $view['rows']);
        self::assertStringStartsWith('This parcel locker is out of service', $view['message']);
    }

    public function testTheVerdictIsForOneParcelOfKnownSizeOnly(): void
    {
        self::assertSame([30.0, 20.0, 10.5], LockerBoxes::parcel(1, [30, 20, 10.5]));
        self::assertNull(LockerBoxes::parcel(2, [30, 20, 10]));
        self::assertNull(LockerBoxes::parcel(1, [30, 0, 10]));
    }

    public function testTheCarriersOwnShapeIsReadToo(): void
    {
        $clean = LockerBoxes::clean([
            'box_sizes'    => [['size' => 'Medium', 'length' => '40', 'width' => 30, 'height' => 20], ['size' => '']],
            'availability' => ['tomorrow' => ['medium' => '3'], 'today' => ['medium' => -1]],
        ]);

        self::assertSame([['size' => 'medium', 'length' => 40, 'width' => 30, 'height' => 20, 'max_weight' => null]], $clean['box_sizes']);
        self::assertSame(['medium' => 3], $clean['tomorrow']);
        self::assertSame(['medium' => 0], $clean['today']);
        self::assertFalse($clean['is_blocked']);
    }

    public function testTheAnswerIsKeptPerEnvironmentAndLocker(): void
    {
        $stored = [];
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(static function (string $key) use (&$stored) {
            return $stored[$key] ?? false;
        });
        $cache->expects(self::once())->method('save')->willReturnCallback(
            static function (string $data, string $key, array $tags, int $ttl) use (&$stored): bool {
                self::assertSame(LockerBoxes::CACHE_TTL, $ttl);
                $stored[$key] = $data;

                return true;
            }
        );

        $client = $this->createMock(ShipmentClientInterface::class);
        $client->expects(self::once())->method('lockerAvailability')->with('1132', 1)->willReturn([
            'box_sizes' => [['size' => 'small', 'length' => 40, 'width' => 30, 'height' => 10, 'max_weight' => null]],
            'tomorrow'  => ['small' => 1],
        ]);

        $boxes = $this->boxes($client, $cache);
        $first = $boxes->availability(7, 1);
        $second = $boxes->availability(7, 1);

        self::assertSame($first, $second);
        self::assertSame(['small' => 1], $second['tomorrow']);
    }

    public function testALockerWithoutACodeAsksNothing(): void
    {
        $client = $this->createMock(ShipmentClientInterface::class);
        $client->expects(self::never())->method('lockerAvailability');

        $this->expectException(ApiException::class);
        $this->boxes($client, null, null)->availability(7);
    }
}
