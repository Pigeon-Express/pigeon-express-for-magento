<?php
/**
 * The office a shipment goes to when it does not fit the hand-in locker,
 * with the rule that decides it written out under the picker.
 *
 * @copyright Copyright (c). All rights reserved.
 */
declare(strict_types=1);

namespace PigeonExpress\Shipping\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form\Element\AbstractElement;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\ResourceModel\Office\CollectionFactory as OfficeCollectionFactory;
use PigeonExpress\Shipping\Model\Service\CarrierLimits;

class FallbackOfficePicker extends OfficePicker
{
    /** @var ConfigInterface */
    private $config;

    /** @var CarrierLimits */
    private $limits;

    public function __construct(
        Context $context,
        OfficeCollectionFactory $officeCollectionFactory,
        ConfigInterface $config,
        CarrierLimits $limits,
        array $data = []
    ) {
        $this->config = $config;
        $this->limits = $limits;
        parent::__construct($context, $officeCollectionFactory, $data);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $element->setComment($this->rulesText());

        return parent::_getElementHtml($element);
    }

    /**
     * Which shipments go where, in full: that is the whole point of this
     * pickup type. As WooCommerce says it.
     */
    private function rulesText(): string
    {
        $box = $this->config->getLockerCompartment();
        $sides = [(float) $box['width'], (float) $box['height'], (float) $box['length']];

        return (string) __(
            'A shipment goes to the parcel locker when it fits one: a single package of up to %1 kg that fits in '
            . '%2 cm turned any way round, with no service other than %3. Every other shipment is handed in at this '
            . 'office: a heavier or larger one, more than one package, or any other service, declared value and '
            . 'return receipts included. The order panel shows for each shipment where it is handed in, and why.',
            $this->limits->maxWeight(ConfigInterface::DELIVERY_TYPE_APS),
            implode(' x ', array_map('round', $sides)),
            (string) __('cash on delivery and SMS notification')
        );
    }
}
