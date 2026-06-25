<?php
/**
 * Pigeon Express City collection.
 *
 * @copyright Copyright (c). All rights reserved.
 */
declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\ResourceModel\City;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use PigeonExpress\Shipping\Model\City as CityModel;
use PigeonExpress\Shipping\Model\ResourceModel\City as CityResource;

class Collection extends AbstractCollection
{
    protected function _construct()
    {
        $this->_init(CityModel::class, CityResource::class);
    }
}
