<?php
/**
 * The two return receipt toggles became one select.
 *
 * "Digital Return Receipt" (service_return_receipt) was the electronic
 * receipt, and "Paper Return Receipt" was saved under the misleading path
 * service_return_at_my_expense. Both now live in return_receipt_mode as
 * none, electronic, paper or both. Every scope that set either toggle gets
 * the choice the pair amounts to there, reading what it inherits for the
 * one it did not set. The old rows are left in place and no longer read.
 *
 * return_at_my_expense, the real top level flag, is a new setting and is
 * not filled from the paper receipt.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Setup\Patch\Data;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\Config\Source\ReturnReceiptMode;

class MigrateReturnReceiptMode implements DataPatchInterface
{
    public const PATH_ELECTRONIC = 'carriers/pigeonexpress/service_return_receipt';
    public const PATH_PAPER = 'carriers/pigeonexpress/service_return_at_my_expense';

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

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($table, ['scope', 'scope_id', 'path', 'value'])
                ->where('path IN (?)', [self::PATH_ELECTRONIC, self::PATH_PAPER, ConfigInterface::XML_PATH_RETURN_RECEIPT_MODE])
        );

        $values = [];
        foreach ($rows as $row) {
            $values[$row['scope'] . ':' . (int) $row['scope_id']][$row['path']] = (string) $row['value'];
        }

        $storeWebsites = $connection->fetchPairs(
            $connection->select()->from($this->moduleDataSetup->getTable('store'), ['store_id', 'website_id'])
        );

        foreach ($values as $key => $paths) {
            if (isset($paths[ConfigInterface::XML_PATH_RETURN_RECEIPT_MODE])) {
                continue;
            }
            [$scope, $scopeId] = explode(':', $key);
            $scopeId = (int) $scopeId;

            $chain = [$key];
            if ($scope === 'stores') {
                $chain[] = 'websites:' . (int) ($storeWebsites[$scopeId] ?? 0);
            }
            $chain[] = ScopeConfigInterface::SCOPE_TYPE_DEFAULT . ':0';

            $mode = ReturnReceiptMode::modeFor(
                $this->inherited($values, $chain, self::PATH_ELECTRONIC),
                $this->inherited($values, $chain, self::PATH_PAPER)
            );

            $connection->insertOnDuplicate($table, [
                'scope'    => $scope,
                'scope_id' => $scopeId,
                'path'     => ConfigInterface::XML_PATH_RETURN_RECEIPT_MODE,
                'value'    => $mode,
            ], ['value']);
        }

        return $this;
    }

    /**
     * A Yes/No value as the scope sees it, from the first scope in the chain
     * that holds it.
     *
     * @param array<string, array<string, string>> $values
     * @param string[] $chain
     */
    private function inherited(array $values, array $chain, string $path): bool
    {
        foreach ($chain as $key) {
            if (isset($values[$key][$path])) {
                return $values[$key][$path] === '1';
            }
        }

        return false;
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
