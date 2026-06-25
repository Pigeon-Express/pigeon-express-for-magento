<?php
/**
 * Product attribute source model for selecting attribute codes (e.g. length/width/height).
 *
 * Shows product attributes (primarily numeric) in a dropdown so the merchant can
 * map existing attributes to Pigeon Express package dimensions.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Config\Source;

use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory as ProductAttributeCollectionFactory;
use Magento\Framework\Data\OptionSourceInterface;

class ProductAttribute implements OptionSourceInterface
{
    /** @var ProductAttributeCollectionFactory */
    private $attributeCollectionFactory;

    public function __construct(
        ProductAttributeCollectionFactory $attributeCollectionFactory
    ) {
        $this->attributeCollectionFactory = $attributeCollectionFactory;
    }

    public function toOptionArray(): array
    {
        $options = [
            ['value' => '', 'label' => __('-- Not selected --')],
        ];

        $collection = $this->attributeCollectionFactory->create();
        // Only product attributes, visible in forms; keep list reasonably small.
        $collection->addVisibleFilter();
        // Allow decimal/int (numeric) and varchar (text field) so merchant can use custom length/width/height attributes.
        $collection->addFieldToFilter('backend_type', ['in' => ['decimal', 'int', 'varchar']]);
        $collection->addFieldToSelect(['attribute_code', 'frontend_label']);
        $collection->setOrder('frontend_label', 'ASC');

        foreach ($collection as $attribute) {
            $code = (string) $attribute->getAttributeCode();
            if ($code === '') {
                continue;
            }
            $label = (string) $attribute->getFrontendLabel();
            if ($label === '') {
                $label = $code;
            }
            $options[] = [
                'value' => $code,
                'label' => sprintf('%s (%s)', $label, $code),
            ];
        }

        return $options;
    }
}

