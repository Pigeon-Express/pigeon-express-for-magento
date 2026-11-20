<?php
/**
 * The office return documents go to, picked like the return office. Not
 * drawn while ConfigInterface::RETURN_DOCUMENTS_CHOICE is off, see
 * ReturnDocumentsField.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Block\Adminhtml\System\Config;

use Magento\Framework\Data\Form\Element\AbstractElement;

class ReturnDocumentsOfficePicker extends OfficePicker
{
    /**
     * @param AbstractElement $element
     * @return string
     */
    public function render(AbstractElement $element)
    {
        return ReturnDocumentsField::isShown() ? parent::render($element) : '';
    }
}
