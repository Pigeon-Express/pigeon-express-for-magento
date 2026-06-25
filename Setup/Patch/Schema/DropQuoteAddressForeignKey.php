<?php
/**
 * Drop FK on pigeonexpress_quote_address → quote_address.
 * Our plugin runs during quote save; the address row may not be committed yet, so the FK causes 1452.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Setup\Patch\Schema;

use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\Patch\SchemaPatchInterface;

class DropQuoteAddressForeignKey implements SchemaPatchInterface
{
    private const TABLE = 'pigeonexpress_quote_address';
    private const FK_NAME = 'PIGEONEXPRESS_QUOTE_ADDRESS_ADDRESS_ID_QUOTE_ADDRESS_ADDRESS_ID';

    /**
     * @var SchemaSetupInterface
     */
    private $schemaSetup;

    public function __construct(SchemaSetupInterface $schemaSetup)
    {
        $this->schemaSetup = $schemaSetup;
    }

    /**
     * @inheritdoc
     */
    public function apply(): void
    {
        $this->schemaSetup->startSetup();

        $connection = $this->schemaSetup->getConnection();
        $tableName = $this->schemaSetup->getTable(self::TABLE);

        if (!$this->schemaSetup->tableExists(self::TABLE)) {
            $this->schemaSetup->endSetup();
            return;
        }

        $fkList = $connection->getForeignKeys($tableName);
        $quoteAddressTable = $this->schemaSetup->getTable('quote_address');
        foreach ($fkList as $fk) {
            $name = $fk['FK_NAME'] ?? $fk['constraint_name'] ?? '';
            $refTable = strtolower((string) ($fk['REFERENCE_TABLE_NAME'] ?? $fk['REFERENCED_TABLE_NAME'] ?? ''));
            $refMatch = ($refTable === 'quote_address' || $refTable === strtolower($quoteAddressTable));
            if ($name && ($name === self::FK_NAME || strpos((string) $name, 'PIGEONEXPRESS_QUOTE_ADDRESS') !== false || $refMatch)) {
                $connection->dropForeignKey($tableName, $name);
                break;
            }
        }

        $this->schemaSetup->endSetup();
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
