<?php
/**
 * The working hours of the saved pickup office, under the office picker.
 *
 * Read only: nothing is saved from this row. It shows the office as saved,
 * so after choosing another office it updates once the settings are saved.
 *
 * @copyright Copyright (c). All rights reserved.
 */
declare(strict_types=1);

namespace PigeonExpress\Shipping\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\Location\WorkingHours;
use PigeonExpress\Shipping\Model\ResourceModel\Office\CollectionFactory as OfficeCollectionFactory;

class OfficeHours extends Field
{
    /** @var ConfigInterface */
    private $config;

    /** @var OfficeCollectionFactory */
    private $officeCollectionFactory;

    /** @var WorkingHours */
    private $hours;

    public function __construct(
        Context $context,
        ConfigInterface $config,
        OfficeCollectionFactory $officeCollectionFactory,
        WorkingHours $hours,
        array $data = []
    ) {
        $this->config = $config;
        $this->officeCollectionFactory = $officeCollectionFactory;
        $this->hours = $hours;
        parent::__construct($context, $data);
    }

    public function render(AbstractElement $element): string
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();

        return parent::render($element);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $store = $this->getRequest()->getParam('store');
        $officeId = $this->config->getPickupOfficeId($store !== null ? (int) $store : null);
        if ($officeId === null) {
            return '<span class="pe-office-hours">' . $this->escapeHtml(__('Choose the pickup office first.')) . '</span>';
        }

        $collection = $this->officeCollectionFactory->create();
        $collection->addFieldToFilter('api_id', $officeId);
        $collection->setPageSize(1);
        $office = $collection->getFirstItem();

        $summary = $office->getId() ? $this->hours->summary($office->getData('working_hours')) : '';
        if ($summary === '') {
            return '<span class="pe-office-hours">'
                . $this->escapeHtml(__('No working hours listed for this office.'))
                . '</span>';
        }

        return '<span class="pe-office-hours">' . $this->escapeHtml($summary) . '</span>';
    }
}
