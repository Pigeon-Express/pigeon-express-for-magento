<?php
/**
 * A config text field the merchant does not type into.
 *
 * Used for the pickup postal code, which comes with the city chosen above it.
 * Locked here, on the server, so it is locked from the first paint; the picker
 * script opens it only for a city the carrier holds no postal code for.
 */
declare(strict_types=1);

namespace PigeonExpress\Shipping\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class ReadonlyField extends Field
{
    protected function _getElementHtml(AbstractElement $element): string
    {
        $element->setReadonly(true);
        $element->setTabindex(-1);
        $element->addClass('pe-readonly');

        return parent::_getElementHtml($element);
    }
}
