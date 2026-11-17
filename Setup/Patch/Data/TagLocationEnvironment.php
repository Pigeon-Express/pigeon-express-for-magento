<?php
/**
 * Mark the offices, lockers and cities already stored with the environment
 * they were synced from.
 *
 * The environment column arrives with default "live", but these rows came from
 * whichever environment the store was on at the time. That is the current test
 * mode setting, read after MoveApiSettingsToDefault has settled it.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Setup\Patch\Data;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use PigeonExpress\Shipping\Model\Environment;

class TagLocationEnvironment implements DataPatchInterface
{
    private const TEST_MODE_PATH = 'carriers/pigeonexpress/test_mode';

    private const TABLES = ['pigeonexpress_office', 'pigeonexpress_aps', 'pigeonexpress_city'];

    /** @var ModuleDataSetupInterface */
    private $moduleDataSetup;

    /** @var ScopeConfigInterface */
    private $scopeConfig;

    public function __construct(ModuleDataSetupInterface $moduleDataSetup, ScopeConfigInterface $scopeConfig)
    {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->scopeConfig = $scopeConfig;
    }

    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();

        // Straight from the table, because the previous patch may have just
        // written it and the config cache would not know yet. Without a row the
        // module's own default applies.
        $value = $connection->fetchOne(
            $connection->select()
                ->from($this->moduleDataSetup->getTable('core_config_data'), ['value'])
                ->where('path = ?', self::TEST_MODE_PATH)
                ->where('scope = ?', ScopeConfigInterface::SCOPE_TYPE_DEFAULT)
        );
        $testMode = $value !== false
            ? (bool) $value
            : $this->scopeConfig->isSetFlag(self::TEST_MODE_PATH);

        if (!$testMode) {
            return $this;
        }

        foreach (self::TABLES as $table) {
            $connection->update(
                $this->moduleDataSetup->getTable($table),
                ['environment' => Environment::TEST]
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
