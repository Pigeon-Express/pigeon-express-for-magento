<?php
/**
 * API keys are kept per environment (test and live) since this version.
 *
 * The single key and secret of earlier versions belong to whichever
 * environment Test Mode chose when the patch runs, so they are copied into
 * that environment's pair. Values are copied as stored, still encrypted. A
 * pair already filled in is left alone. The old paths stay where they are:
 * ConfigProvider reads them as a fallback only while neither environment has
 * a key of its own.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Setup\Patch\Data;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use PigeonExpress\Shipping\Api\ConfigInterface;

class CopyApiKeysToEnvironment implements DataPatchInterface
{
    /** @var ModuleDataSetupInterface */
    private $moduleDataSetup;

    public function __construct(ModuleDataSetupInterface $moduleDataSetup)
    {
        $this->moduleDataSetup = $moduleDataSetup;
    }

    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('core_config_data');

        $read = function (string $path) use ($connection, $table) {
            $value = $connection->fetchOne(
                $connection->select()
                    ->from($table, ['value'])
                    ->where('path = ?', $path)
                    ->where('scope = ?', ScopeConfigInterface::SCOPE_TYPE_DEFAULT)
                    ->where('scope_id = ?', 0)
            );

            return $value === false || $value === null ? null : (string) $value;
        };

        // No row means the config.xml default, which is test mode on.
        $testMode = $read(ConfigInterface::XML_PATH_TEST_MODE);
        $isTest = $testMode === null || (bool) (int) $testMode;

        $pairs = $isTest
            ? [
                ConfigInterface::XML_PATH_API_KEY_LEGACY => ConfigInterface::XML_PATH_API_KEY_TEST,
                ConfigInterface::XML_PATH_API_SECRET_LEGACY => ConfigInterface::XML_PATH_API_SECRET_TEST,
            ]
            : [
                ConfigInterface::XML_PATH_API_KEY_LEGACY => ConfigInterface::XML_PATH_API_KEY_LIVE,
                ConfigInterface::XML_PATH_API_SECRET_LEGACY => ConfigInterface::XML_PATH_API_SECRET_LIVE,
            ];

        foreach ($pairs as $from => $to) {
            $value = $read($from);
            if ($value === null || $value === '') {
                continue;
            }

            $existing = $read($to);
            if ($existing !== null && $existing !== '') {
                continue;
            }

            $connection->insertOnDuplicate(
                $table,
                [
                    'scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
                    'scope_id' => 0,
                    'path' => $to,
                    'value' => $value,
                ],
                ['value']
            );
        }

        return $this;
    }

    public static function getDependencies(): array
    {
        return [MoveApiSettingsToDefault::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
