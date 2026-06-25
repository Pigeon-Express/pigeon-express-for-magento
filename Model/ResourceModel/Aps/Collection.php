<?php
/**
 * Pigeon Express APS collection.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\ResourceModel\Aps;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use PigeonExpress\Shipping\Model\Aps as ApsModel;
use PigeonExpress\Shipping\Model\ResourceModel\Aps as ApsResource;

class Collection extends AbstractCollection
{
    protected function _construct()
    {
        $this->_init(ApsModel::class, ApsResource::class);
    }
}
