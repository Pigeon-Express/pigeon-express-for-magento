<?php
declare(strict_types=1);

namespace PigeonExpress\Shipping\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class SyncButton extends Field
{
    /**
     * Remove scope label — button is global, not store-scoped.
     */
    public function render(AbstractElement $element): string
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    /**
     * Render a link styled as a button. Admin URL includes secret key — CSRF protection built-in.
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        $url = $this->escapeUrl($this->getUrl('pigeonexpress/sync/schedule'));
        return <<<HTML
<a href="{$url}" class="action-default scalable">
    <span>Schedule Sync Now</span>
</a>
HTML;
    }
}
