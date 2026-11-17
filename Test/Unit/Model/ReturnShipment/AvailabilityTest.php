<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\ReturnShipment;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\FlagManager;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Exceptions\ValidationException;
use PigeonExpress\Shipping\Model\Environment;
use PigeonExpress\Shipping\Model\ReturnShipment\Availability;

/**
 * Return shipments are offered until Pigeon Express refuses one for the
 * account, and the refusal counts only for the environment it came from.
 */
class AvailabilityTest extends TestCase
{
    /** @var array<string, mixed> */
    private $flags = [];

    public function testOfferedWhenSwitchedOnAndNeverRefused(): void
    {
        self::assertTrue($this->availability(true, 'test')->isOffered());
    }

    public function testNotOfferedWhenSwitchedOff(): void
    {
        self::assertFalse($this->availability(false, 'test')->isOffered());
    }

    public function testARefusalHidesThemInItsEnvironmentOnly(): void
    {
        $this->availability(true, 'test')->markUnavailable();

        self::assertFalse($this->availability(true, 'test')->isOffered());
        self::assertTrue($this->availability(true, 'live')->isOffered());
    }

    public function testClearingOffersThemAgain(): void
    {
        $availability = $this->availability(true, 'test');
        $availability->markUnavailable();
        $availability->clear();

        self::assertTrue($availability->isOffered());
    }

    public function testThePermissionMessageIsANotEnabledRefusal(): void
    {
        $sdk = new ValidationException('Нямате разрешение за създаване на обратни пратки. Обърнете се към администратор.', [], 422);

        self::assertTrue(Availability::isNotEnabledRefusal(new LocalizedException(__('x'), $sdk)));
    }

    public function testAnErrorUnderIsReverseIsANotEnabledRefusal(): void
    {
        $sdk = new ValidationException('Validation failed', ['is_reverse' => ['Not allowed']], 422);

        self::assertTrue(Availability::isNotEnabledRefusal($sdk));
    }

    public function testOtherRefusalsAreNot(): void
    {
        $field = new ValidationException('Validation failed', ['sender_phone' => ['Invalid']], 422);
        $receipt = new ValidationException('Нямате разрешение за обратна разписка.', [], 422);

        self::assertFalse(Availability::isNotEnabledRefusal($field));
        self::assertFalse(Availability::isNotEnabledRefusal($receipt));
        self::assertFalse(Availability::isNotEnabledRefusal(new \RuntimeException('down')));
    }

    private function availability(bool $enabled, string $environment): Availability
    {
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('isSetFlag')->willReturn($enabled);

        $flags = $this->createMock(FlagManager::class);
        $flags->method('getFlagData')->willReturnCallback(function (string $code) {
            return $this->flags[$code] ?? null;
        });
        $flags->method('saveFlag')->willReturnCallback(function (string $code, $value) {
            $this->flags[$code] = $value;

            return true;
        });
        $flags->method('deleteFlag')->willReturnCallback(function (string $code) {
            unset($this->flags[$code]);

            return true;
        });

        $env = $this->createMock(Environment::class);
        $env->method('current')->willReturn($environment);

        return new Availability($config, $flags, $env);
    }
}
