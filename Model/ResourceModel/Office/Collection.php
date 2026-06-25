<?php
/**
 * Pigeon Express Office collection.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\ResourceModel\Office;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use PigeonExpress\Shipping\Model\Office as OfficeModel;
use PigeonExpress\Shipping\Model\ResourceModel\Office as OfficeResource;

class Collection extends AbstractCollection
{
    protected function _construct()
    {
        $this->_init(OfficeModel::class, OfficeResource::class);
    }
}
