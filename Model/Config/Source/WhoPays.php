<?php
/**
 * Who Pigeon Express bills for the shipping.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use PigeonExpress\Shipping\Api\ConfigInterface;

class WhoPays implements OptionSourceInterface
{
    /**
     * @return array<int, array{value:string, label:\Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            [
                'value' => ConfigInterface::WHO_PAYS_SENDER,
                'label' => __('Sender (you are billed)'),
            ],
            [
                'value' => ConfigInterface::WHO_PAYS_RECEIVER,
                'label' => __('Receiver (the courier collects it on delivery)'),
            ],
        ];
    }
}
