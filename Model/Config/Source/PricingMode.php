<?php
/**
 * Pricing mode source for delivery types: Dynamic (API) or Fixed (flat rate).
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class PricingMode implements OptionSourceInterface
{
    public const DYNAMIC = 'dynamic';
    public const FIXED = 'fixed';

    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::DYNAMIC, 'label' => __('Dynamic (via API)')],
            ['value' => self::FIXED, 'label' => __('Fixed (flat rate override)')],
        ];
    }
}
