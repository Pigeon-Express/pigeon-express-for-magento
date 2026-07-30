<?php
/**
 * Pigeon Express additional service resource model.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class AdditionalService extends AbstractDb
{
    protected function _construct()
    {
        $this->_init('pigeonexpress_additional_service', 'entity_id');
    }
}
