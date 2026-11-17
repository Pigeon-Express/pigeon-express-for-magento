<?php
/**
 * API key, secret and test mode became global settings.
 *
 * Earlier versions allowed them per website (and test mode per store view).
 * The module now reads them at default scope only, so a value saved lower down
 * would be silently ignored. Where default scope holds nothing, the first value
 * found below is moved up; the lower values are then removed so the database
 * matches what the admin shows.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Setup\Patch\Data;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class MoveApiSettingsToDefault implements DataPatchInterface
{
    private const PATHS = [
        'carriers/pigeonexpress/api_key',
        'carriers/pigeonexpress/api_secret',
        'carriers/pigeonexpress/test_mode',
    ];

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

        $connection->beginTransaction();
        try {
            foreach (self::PATHS as $path) {
                $default = $connection->fetchOne(
                    $connection->select()
                        ->from($table, ['config_id'])
                        ->where('path = ?', $path)
                        ->where('scope = ?', ScopeConfigInterface::SCOPE_TYPE_DEFAULT)
                );

                $overrides = $connection->fetchAll(
                    $connection->select()
                        ->from($table, ['config_id', 'value'])
                        ->where('path = ?', $path)
                        ->where('scope <> ?', ScopeConfigInterface::SCOPE_TYPE_DEFAULT)
                        // Websites before store views, oldest first.
                        ->order(new \Zend_Db_Expr("scope = 'websites' DESC"))
                        ->order('config_id ASC')
                );

                if (!$overrides) {
                    continue;
                }

                if (!$default) {
                    $connection->insert($table, [
                        'scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
                        'scope_id' => 0,
                        'path' => $path,
                        'value' => $overrides[0]['value'],
                    ]);
                }

                $connection->delete($table, [
                    'config_id IN (?)' => array_column($overrides, 'config_id'),
                ]);
            }
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
