<?php
/**
 * The note under "Return shipments" in the Returns settings, shown only
 * while Pigeon Express is known to refuse them for the account in the
 * current environment. Saving the settings checks again.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use PigeonExpress\Shipping\Model\ReturnShipment\Availability;

class ReverseNotice extends Field
{
    /** @var Availability */
    private $availability;

    public function __construct(Context $context, Availability $availability, array $data = [])
    {
        $this->availability = $availability;
        parent::__construct($context, $data);
    }

    public function render(AbstractElement $element)
    {
        if (!$this->availability->isUnavailable()) {
            return '';
        }

        return '<tr id="row_' . $this->escapeHtmlAttr($element->getHtmlId()) . '"><td class="label"></td><td class="value">'
            . '<div class="message message-warning"><div>'
            . $this->escapeHtml(__(
                'Return shipments are not enabled in your Pigeon Express account. To enable them, contact your Pigeon Express representative. Saving these settings checks again with the next return shipment.'
            ))
            . '</div></div></td><td></td></tr>';
    }
}
