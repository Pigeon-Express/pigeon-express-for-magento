<?php
/**
 * Choices for "When the shipment comes back": leave the order, put it on hold
 * or cancel it, both the way Magento's own Hold and Cancel buttons do. See
 * Model\Status\OrderStatusApplier.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use PigeonExpress\Shipping\Model\Status\StatusConfig;

class OrderOnReturn implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => StatusConfig::ACTION_NONE, 'label' => __("Don't change the order")],
            ['value' => StatusConfig::ACTION_HOLD, 'label' => __('Put the order on hold')],
            ['value' => StatusConfig::ACTION_CANCEL, 'label' => __('Cancel the order')],
        ];
    }
}
