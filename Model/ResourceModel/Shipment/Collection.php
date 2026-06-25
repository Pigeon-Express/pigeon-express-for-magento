<?php
/**
 * Pigeon Express Shipment collection.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\ResourceModel\Shipment;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use PigeonExpress\Shipping\Model\Shipment as ShipmentModel;
use PigeonExpress\Shipping\Model\ResourceModel\Shipment as ShipmentResource;

class Collection extends AbstractCollection
{
    protected function _construct()
    {
        $this->_init(ShipmentModel::class, ShipmentResource::class);
    }
}
