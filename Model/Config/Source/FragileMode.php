<?php
/**
 * Which packages are marked fragile for the courier.
 *
 * A handling mark only, not insurance: that is the declared value service.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use PigeonExpress\Shipping\Api\ConfigInterface;

class FragileMode implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => ConfigInterface::FRAGILE_NEVER, 'label' => __('No')],
            ['value' => ConfigInterface::FRAGILE_ALWAYS, 'label' => __('All packages')],
            ['value' => ConfigInterface::FRAGILE_PER_PACKAGE, 'label' => __('Chosen for each package on the order')],
        ];
    }
}
