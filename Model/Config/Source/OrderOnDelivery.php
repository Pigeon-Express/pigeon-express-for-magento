<?php
/**
 * Choices for "When the shipment is delivered": leave the order, or complete
 * it. Completing never sets a state by hand: the order is shipped and, for
 * cash on delivery, invoiced, and Magento itself then marks it Complete. See
 * Model\Magento\CompleteOnDelivery.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use PigeonExpress\Shipping\Model\Status\StatusConfig;

class OrderOnDelivery implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => StatusConfig::ACTION_NONE, 'label' => __("Don't change the order")],
            ['value' => StatusConfig::ACTION_COMPLETE, 'label' => __('Complete the order')],
        ];
    }
}
