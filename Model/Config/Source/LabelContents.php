<?php
/**
 * What the waybill says the parcel contains.
 *
 * The carrier prints this on the label, so anyone who handles the parcel can
 * read it: the courier, the counter staff, anyone standing behind the recipient
 * at the office. Product names are useful to the merchant and to nobody else.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class LabelContents implements OptionSourceInterface
{
    /** The product names as they appear in the order. */
    public const PRODUCT_NAMES = 'product_names';

    /** One wording the merchant chooses, on every parcel. */
    public const FIXED_TEXT = 'fixed_text';

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            [
                'value' => self::PRODUCT_NAMES,
                'label' => __('Product names'),
            ],
            [
                'value' => self::FIXED_TEXT,
                'label' => __('A fixed description'),
            ],
        ];
    }
}
