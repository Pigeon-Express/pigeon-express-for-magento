<?php
/**
 * Marks the checkout page as one where the carrier collects the address.
 *
 * Renders nothing. It exists to put a class on the body while the layout is
 * being generated, which is the only moment early enough: the stylesheet hides
 * the stock street wrapper off that class, and doing it from script instead
 * meant the wrapper was drawn and then taken away in front of the customer.
 *
 * The class is only the page's opening state. Once the checkout is running, the
 * component that watches the chosen shipping method owns it, and takes it off
 * again if the customer picks a carrier that is not this one.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Block\Checkout;

use Magento\Framework\View\Element\AbstractBlock;
use Magento\Framework\View\Element\Context;
use Magento\Framework\View\Page\Config as PageConfig;
use PigeonExpress\Shipping\Api\ConfigInterface;

class BodyClass extends AbstractBlock
{
    /** Kept in step with the same name in address-visibility.js. */
    public const BODY_CLASS = 'pigeonexpress-address-override';

    /** @var ConfigInterface */
    private $config;

    /** @var PageConfig */
    private $pageConfig;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        ConfigInterface $config,
        PageConfig $pageConfig,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->config = $config;
        $this->pageConfig = $pageConfig;
    }

    protected function _prepareLayout()
    {
        if ($this->config->isOverrideAddressFields()) {
            $this->pageConfig->addBodyClass(self::BODY_CLASS);
        }

        return parent::_prepareLayout();
    }

    /**
     * Nothing to draw: the whole of this block is the class it sets above.
     */
    protected function _toHtml()
    {
        return '';
    }
}
