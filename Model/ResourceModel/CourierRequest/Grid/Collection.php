<?php
/**
 * The courier requests listing: the requests of the environment the shop is
 * in now, newest first. The other environment's stay in the table.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\ResourceModel\CourierRequest\Grid;

use Magento\Framework\Data\Collection\Db\FetchStrategyInterface as FetchStrategy;
use Magento\Framework\Data\Collection\EntityFactoryInterface as EntityFactory;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use PigeonExpress\Shipping\Model\Environment;
use PigeonExpress\Shipping\Model\ResourceModel\CourierRequest;
use Psr\Log\LoggerInterface as Logger;

class Collection extends SearchResult
{
    /** @var Environment */
    private $environment;

    public function __construct(
        EntityFactory $entityFactory,
        Logger $logger,
        FetchStrategy $fetchStrategy,
        EventManager $eventManager,
        Environment $environment,
        $mainTable = 'pigeonexpress_courier_request',
        $resourceModel = CourierRequest::class,
        $identifierName = 'entity_id',
        $connectionName = null
    ) {
        $this->environment = $environment;
        parent::__construct(
            $entityFactory,
            $logger,
            $fetchStrategy,
            $eventManager,
            $mainTable,
            $resourceModel,
            $identifierName,
            $connectionName
        );
    }

    protected function _initSelect()
    {
        parent::_initSelect();
        $this->getSelect()->where('main_table.environment = ?', $this->environment->current());

        return $this;
    }
}
