<?php
/**
 * Renders a config select field with a live search/filter input above it.
 */
declare(strict_types=1);

namespace PigeonExpress\Shipping\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class SearchableSelect extends Field
{
    /**
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        $selectHtml = $element->getElementHtml();
        $elementId  = $element->getHtmlId();
        $inputId    = $elementId . '_search';

        $placeholder = __('Search...');

        return <<<HTML
<div class="pe-searchable-select" style="position:relative;">
    <input
        type="text"
        id="{$inputId}"
        placeholder="{$placeholder}"
        autocomplete="off"
        style="width:100%;margin-bottom:4px;padding:4px 8px;box-sizing:border-box;border:1px solid #adadad;border-radius:1px;"
    />
    {$selectHtml}
</div>
<script>
(function () {
    var input  = document.getElementById('{$inputId}');
    var select = document.getElementById('{$elementId}');
    if (!input || !select) { return; }

    var allOptions = Array.prototype.slice.call(select.options);

    input.addEventListener('input', function () {
        var q = this.value.toLowerCase().trim();
        var currentVal = select.value;

        while (select.options.length) { select.remove(0); }

        allOptions.forEach(function (opt) {
            if (!q || opt.text.toLowerCase().indexOf(q) !== -1) {
                select.add(new Option(opt.text, opt.value, false, opt.value === currentVal));
            }
        });

        if (!select.value && currentVal) {
            select.value = currentVal;
        }
    });
})();
</script>
HTML;
    }
}
