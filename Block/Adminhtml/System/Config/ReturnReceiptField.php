<?php
/**
 * The return receipt select, hidden when the account offers neither receipt.
 *
 * Until the service catalogue has synced every choice is shown, as for the
 * other services: an empty catalogue means "not yet known".
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use PigeonExpress\Shipping\Model\Config\Source\ReturnReceiptMode;

class ReturnReceiptField extends Field
{
    /** @var ReturnReceiptMode */
    private $modes;

    public function __construct(Context $context, ReturnReceiptMode $modes, array $data = [])
    {
        parent::__construct($context, $data);
        $this->modes = $modes;
    }

    public function render(AbstractElement $element): string
    {
        $offered = $this->modes->offeredCodes();
        if ($offered === []) {
            return '';
        }

        return parent::render($element);
    }
}
