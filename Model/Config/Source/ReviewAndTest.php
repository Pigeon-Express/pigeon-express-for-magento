<?php
/**
 * Inspection tier source for the per-delivery-type option.
 *
 * The API offers two distinct, separately billed services:
 *   shipment_review               inspection by the recipient
 *   shipment_test_before_payment  inspection and testing by the recipient
 *
 * They are a hierarchy, not alternatives: the second contains the first, so only
 * one is ever sent. Neither applies to locker delivery, where nobody is present.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ReviewAndTest implements OptionSourceInterface
{
    public const NO = 'no';

    /** Inspection only, maps to shipment_review. */
    public const REVIEW = 'review';

    /**
     * Inspection and testing, maps to shipment_test_before_payment.
     *
     * Historically the only "yes" value, so existing configuration keeps
     * ordering exactly the service it ordered before.
     */
    public const TEST = 'test';

    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::NO, 'label' => __('No')],
            ['value' => self::REVIEW, 'label' => __('Inspection only')],
            ['value' => self::TEST, 'label' => __('Inspection and testing')],
        ];
    }
}
