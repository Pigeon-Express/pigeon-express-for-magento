<?php
/**
 * How the default weight and size are applied to a shipment.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use PigeonExpress\Shipping\Api\ConfigInterface;

class ParcelDefaultsMode implements OptionSourceInterface
{
    /**
     * @return array<int, array{value:string, label:\Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            [
                'value' => ConfigInterface::PARCEL_DEFAULTS_PER_SHIPMENT,
                'label' => __('Once per shipment, for products without values'),
            ],
            [
                'value' => ConfigInterface::PARCEL_DEFAULTS_FIXED,
                'label' => __('Always one default parcel (ignore product values)'),
            ],
            [
                'value' => ConfigInterface::PARCEL_DEFAULTS_PER_ITEM,
                'label' => __('For every item'),
            ],
        ];
    }
}
