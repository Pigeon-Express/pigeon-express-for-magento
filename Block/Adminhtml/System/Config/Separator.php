<?php
declare(strict_types=1);

namespace PigeonExpress\Shipping\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class Separator extends Field
{
    protected function _getElementHtml(AbstractElement $element): string
    {
        return '<hr style="border:0;border-top:1px solid #d6d6d6;margin:8px 0;"/>';
    }

    // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod -- return type omitted for parent compatibility (PHP 8.1)
    protected function _decorateRowHtml(AbstractElement $element, $html)
    {
        return '<tr><td colspan="4" style="padding:0;">' . $html . '</td></tr>';
    }
}
