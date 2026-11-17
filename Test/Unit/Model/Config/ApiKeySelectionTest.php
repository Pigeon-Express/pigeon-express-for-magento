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

/**
 * The key pair in use is the one of the environment Test Mode chose. The old
 * single pair is read only while neither environment has a key of its own.
 */
class ApiKeySelectionTest extends TestCase
{
    public function testTestModeUsesTheTestPair(): void
    {
        $config = $this->provider(true, [
            ConfigInterface::XML_PATH_API_KEY_TEST => 'kt',
            ConfigInterface::XML_PATH_API_SECRET_TEST => 'st',
            ConfigInterface::XML_PATH_API_KEY_LIVE => 'kl',
            ConfigInterface::XML_PATH_API_SECRET_LIVE => 'sl',
            ConfigInterface::XML_PATH_API_KEY_LEGACY => 'old',
        ]);

        self::assertSame('kt', $config->getApiKey());
        self::assertSame('st', $config->getApiSecret());
    }

    public function testLiveModeUsesTheLivePair(): void
    {
        $config = $this->provider(false, [
            ConfigInterface::XML_PATH_API_KEY_TEST => 'kt',
            ConfigInterface::XML_PATH_API_SECRET_TEST => 'st',
            ConfigInterface::XML_PATH_API_KEY_LIVE => 'kl',
            ConfigInterface::XML_PATH_API_SECRET_LIVE => 'sl',
        ]);

        self::assertSame('kl', $config->getApiKey(3));
        self::assertSame('sl', $config->getApiSecret(3));
    }

    public function testTheLegacyPairIsUsedWhileNoEnvironmentHasKeys(): void
    {
        $config = $this->provider(true, [
            ConfigInterface::XML_PATH_API_KEY_LEGACY => 'old-key',
            ConfigInterface::XML_PATH_API_SECRET_LEGACY => 'old-secret',
        ]);

        self::assertSame('old-key', $config->getApiKey());
        self::assertSame('old-secret', $config->getApiSecret());
    }

    public function testAMissingLiveKeyNeverBecomesAnotherEnvironmentsKey(): void
    {
        $config = $this->provider(false, [
            ConfigInterface::XML_PATH_API_KEY_TEST => 'kt',
            ConfigInterface::XML_PATH_API_KEY_LEGACY => 'old-key',
        ]);

        self::assertSame('', $config->getApiKey());
        self::assertSame('', $config->getApiSecret());
    }

    public function testAnEmptySecretFallsBackToTheKey(): void
    {
        $config = $this->provider(true, [ConfigInterface::XML_PATH_API_KEY_TEST => 'kt']);

        self::assertSame('kt', $config->getApiSecret());
    }

    public function testValuesAreDecrypted(): void
    {
        $config = $this->provider(true, [ConfigInterface::XML_PATH_API_KEY_TEST => 'enc:abc'], true);

        self::assertSame('abc', $config->getApiKey());
    }

    /**
     * @param array<string, string> $values
     */
    private function provider(bool $testMode, array $values, bool $encrypted = false): ConfigProvider
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(
            static function ($path) use ($values) {
                return $values[$path] ?? null;
            }
        );
        $scope->method('isSetFlag')->willReturnCallback(
            static function ($path) use ($testMode) {
                return $path === ConfigInterface::XML_PATH_TEST_MODE ? $testMode : false;
            }
        );

        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturnCallback(
            static function ($value) use ($encrypted) {
                return $encrypted && strpos($value, 'enc:') === 0 ? substr($value, 4) : $value;
            }
        );

        return new ConfigProvider($scope, $encryptor);
    }
}
