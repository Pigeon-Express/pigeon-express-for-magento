<?php
/**
 * A config field that is saved but never shown.
 *
 * The pickup address City ID and Street ID are written by the City and Street
 * pickers, never typed. The row is rendered as usual, with its "Use Default"
 * checkbox and its dependencies, so it saves and inherits like any other field.
 *
 * It is hidden by the `hidden` attribute rather than by a stylesheet alone, so
 * it stays out of sight even before the stylesheet arrives. Magento shows a
 * dependent row by clearing its inline display style, which leaves the
 * attribute, and the class, in force.
 */
declare(strict_types=1);

namespace PigeonExpress\Shipping\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class HiddenField extends Field
{
    /**
     * Read only as well, in case a theme or tool ever shows the row.
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        $element->setReadonly(true);

        return parent::_getElementHtml($element);
    }

    /**
     * @param AbstractElement $element
     * @param string $html
     * @return string
     */
    protected function _decorateRowHtml(AbstractElement $element, $html)
    {
        return '<tr id="row_' . $element->getHtmlId() . '" class="pe-hidden-row" hidden>' . $html . '</tr>';
    }
}
