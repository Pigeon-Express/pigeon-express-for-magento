<?php
/**
 * Source model: Place of shipment (pickup) type — Office or Address.
 *
 * @copyright Copyright (c). All rights reserved.
 */
declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class PickupType implements OptionSourceInterface
{
    public const OFFICE = 'office';
    public const ADDRESS = 'address';

    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::OFFICE, 'label' => __('Office (Pigeon Express office)')],
            ['value' => self::ADDRESS, 'label' => __('Address (pickup from your address)')],
        ];
    }
}
