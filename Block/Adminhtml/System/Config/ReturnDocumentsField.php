<?php
/**
 * A return documents setting: not drawn at all while
 * ConfigInterface::RETURN_DOCUMENTS_CHOICE is off.
 *
 * Not drawn means not posted either, so Magento keeps the stored value as it
 * is, the way WooCommerce leaves these fields out of its section.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use PigeonExpress\Shipping\Api\ConfigInterface;

class ReturnDocumentsField extends Field
{
    /**
     * Whether the return documents settings are shown at all.
     */
    public static function isShown(): bool
    {
        return (bool) ConfigInterface::RETURN_DOCUMENTS_CHOICE;
    }

    /**
     * @param AbstractElement $element
     * @return string
     */
    public function render(AbstractElement $element)
    {
        return self::isShown() ? parent::render($element) : '';
    }
}
