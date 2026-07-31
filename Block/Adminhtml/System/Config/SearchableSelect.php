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
    /**
     * The filter behaviour is a RequireJS component wired up with data-mage-init.
     * An inline script here would violate the admin Content Security Policy.
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        $selectHtml = $element->getElementHtml();
        $elementId  = $element->getHtmlId();
        $inputId    = $elementId . '_search';

        $escaper = $this->getEscaper();
        $placeholder = $escaper->escapeHtmlAttr((string) __('Search...'));

        $init = $escaper->escapeHtmlAttr((string) json_encode([
            'PigeonExpress_Shipping/js/searchable-select' => ['selectId' => $elementId],
        ]));

        return <<<HTML
<div class="pe-searchable-select" data-mage-init="{$init}">
    <input
        type="text"
        id="{$escaper->escapeHtmlAttr($inputId)}"
        class="pe-searchable-select-input"
        data-role="pe-select-search"
        placeholder="{$placeholder}"
        autocomplete="off"
    />
    {$selectHtml}
</div>
HTML;
    }

    /**
     * Escaper, resolved lazily so the constructor signature stays untouched.
     */
    private function getEscaper(): \Magento\Framework\Escaper
    {
        return $this->_escaper;
    }
}
