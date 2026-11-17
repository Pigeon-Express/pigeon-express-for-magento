<?php
/**
 * Where refused or uncollected shipments go back to.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use PigeonExpress\Shipping\Api\ConfigInterface;

class ReturnTo implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => ConfigInterface::RETURN_TO_PROFILE, 'label' => __('As set in your Pigeon Express account')],
            ['value' => ConfigInterface::RETURN_TO_OFFICE, 'label' => __('To a chosen office')],
        ];
    }
}
