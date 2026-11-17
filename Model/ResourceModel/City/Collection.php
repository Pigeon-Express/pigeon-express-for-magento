<?php
/**
 * Pigeon Express City collection.
 *
 * Holds only the rows of the environment the store is on, see Environment.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\ResourceModel\City;

use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use PigeonExpress\Shipping\Model\Environment;
use PigeonExpress\Shipping\Model\City as CityModel;
use PigeonExpress\Shipping\Model\ResourceModel\City as CityResource;
use Psr\Log\LoggerInterface;

class Collection extends AbstractCollection
{
    /** @var Environment */
    private $environment;

    public function __construct(
        EntityFactoryInterface $entityFactory,
        LoggerInterface $logger,
        FetchStrategyInterface $fetchStrategy,
        ManagerInterface $eventManager,
        Environment $environment,
        ?AdapterInterface $connection = null,
        ?AbstractDb $resource = null
    ) {
        // Set before the parent constructor, which builds the select.
        $this->environment = $environment;
        parent::__construct($entityFactory, $logger, $fetchStrategy, $eventManager, $connection, $resource);
    }

    protected function _construct()
    {
        $this->_init(CityModel::class, CityResource::class);
    }

    protected function _initSelect()
    {
        parent::_initSelect();
        $this->getSelect()->where('main_table.environment = ?', $this->environment->current());

        return $this;
    }
}
