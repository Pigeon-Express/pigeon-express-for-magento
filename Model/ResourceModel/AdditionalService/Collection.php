<?php
/**
 * Pigeon Express additional service collection.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\ResourceModel\AdditionalService;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use PigeonExpress\Shipping\Model\AdditionalService as AdditionalServiceModel;
use PigeonExpress\Shipping\Model\ResourceModel\AdditionalService as AdditionalServiceResource;

class Collection extends AbstractCollection
{
    protected function _construct()
    {
        $this->_init(AdditionalServiceModel::class, AdditionalServiceResource::class);
    }
}
