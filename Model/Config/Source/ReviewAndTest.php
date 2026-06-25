<?php
/**
 * Review and Test source for per-delivery-type option.
 *
 * Maps to PE API field: shipment_test_before_payment: true
 * Not available for APS (locker) delivery.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ReviewAndTest implements OptionSourceInterface
{
    public const NO = 'no';
    public const TEST = 'test';

    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::NO, 'label' => __('No')],
            ['value' => self::TEST, 'label' => __('Yes (review and test before payment)')],
        ];
    }
}
