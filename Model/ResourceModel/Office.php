<?php
/**
 * Pigeon Express Office resource model.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Office extends AbstractDb
{
    protected function _construct()
    {
        $this->_init('pigeonexpress_office', 'entity_id');
    }
}
