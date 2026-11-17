<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\Config\ConfigProvider;
use PigeonExpress\Shipping\Model\Config\Source\ReturnReceiptMode;

/**
 * The getters the shipment form and the payload read.
 */
class NewSettingsTest extends TestCase
{
    public function testAnEmptyFallbackPriceMeansHide(): void
    {
        $this->assertNull($this->config(['address_fallback_price' => ''])->getFallbackPrice('address'));
        $this->assertNull($this->config(['address_fallback_price' => 'abc'])->getFallbackPrice('address'));
        $this->assertNull($this->config(['address_fallback_price' => '-1'])->getFallbackPrice('address'));
    }

    public function testAFallbackPriceIsAPrice(): void
    {
        $this->assertSame(0.0, $this->config(['office_fallback_price' => '0'])->getFallbackPrice('office'));
        $this->assertSame(5.9, $this->config(['aps_fallback_price' => '5,90'])->getFallbackPrice('aps'));
    }

    public function testAChosenOfficeWithNoOfficeFollowsTheAccount(): void
    {
        $this->assertNull($this->config(['return_to' => 'office', 'return_office_id' => ''])->getReturnOfficeId());
        $this->assertNull($this->config(['return_to' => 'profile', 'return_office_id' => '149'])->getReturnOfficeId());
        $this->assertSame(149, $this->config(['return_to' => 'office', 'return_office_id' => '149'])->getReturnOfficeId());
    }

    public function testEveryPickupTypeIsKnownAndAnythingElseIsTheOffice(): void
    {
        foreach (['office', 'address', 'locker', 'saved_address'] as $type) {
            $this->assertSame($type, $this->config(['pickup_type' => $type])->getPickupType());
        }
        $this->assertSame('office', $this->config(['pickup_type' => 'drone'])->getPickupType());
    }

    public function testFragileAndReceiptDefaults(): void
    {
        $config = $this->config([]);

        $this->assertSame(ConfigInterface::FRAGILE_NEVER, $config->getFragileMode());
        $this->assertSame(ConfigInterface::RECEIPT_NONE, $config->getReturnReceiptMode());
        $this->assertSame(ConfigInterface::RECEIPT_BOTH, $this->config(['return_receipt_mode' => 'both'])->getReturnReceiptMode());
    }

    public function testReviewModeAsTheFormNamesIt(): void
    {
        $this->assertSame('view', $this->config(['office_review_and_test' => 'review'])->getReviewMode('office'));
        $this->assertSame('view_test', $this->config(['address_review_and_test' => 'test'])->getReviewMode('address'));
        $this->assertSame('none', $this->config(['aps_review_and_test' => 'test'])->getReviewMode('aps'));
    }

    public function testTheTwoOldReceiptTogglesMapToOneChoice(): void
    {
        $this->assertSame('none', ReturnReceiptMode::modeFor(false, false));
        $this->assertSame('electronic', ReturnReceiptMode::modeFor(true, false));
        $this->assertSame('paper', ReturnReceiptMode::modeFor(false, true));
        $this->assertSame('both', ReturnReceiptMode::modeFor(true, true));
        $this->assertSame(['return_receipt', 'paper_return_receipt'], ReturnReceiptMode::codesFor('both'));
    }

    /**
     * @param array<string, string> $values Field => value under carriers/pigeonexpress/
     */
    private function config(array $values): ConfigProvider
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(
            static function (string $path) use ($values) {
                return $values[substr($path, strlen('carriers/pigeonexpress/'))] ?? null;
            }
        );
        $scope->method('isSetFlag')->willReturnCallback(
            static function (string $path) use ($values): bool {
                return !empty($values[substr($path, strlen('carriers/pigeonexpress/'))]);
            }
        );

        return new ConfigProvider($scope, $this->createMock(EncryptorInterface::class));
    }
}
