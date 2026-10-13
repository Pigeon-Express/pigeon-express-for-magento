<?php
/**
 * Renders the pickup office select as a searchable picker.
 *
 * The office names alone do not say where an office is ("Автогарата" exists in
 * more than one town), so the picker searches and shows the address as well.
 * The select stays in the page, hidden, and remains the field that is saved:
 * "Use Default", dependencies and validation all keep working on it unchanged.
 *
 * The list is a few hundred rows at most and comes from the local table, so it
 * travels with the page and is filtered in the browser, with no round trip.
 */
declare(strict_types=1);

namespace PigeonExpress\Shipping\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use PigeonExpress\Shipping\Model\ResourceModel\Office\CollectionFactory as OfficeCollectionFactory;

class OfficePicker extends Field
{
    /** @var OfficeCollectionFactory */
    private $officeCollectionFactory;

    public function __construct(
        Context $context,
        OfficeCollectionFactory $officeCollectionFactory,
        array $data = []
    ) {
        $this->officeCollectionFactory = $officeCollectionFactory;
        parent::__construct($context, $data);
    }

    /**
     * The behaviour is a RequireJS component wired up with data-mage-init.
     * An inline script here would violate the admin Content Security Policy.
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        $element->addClass('pe-picker__native');

        $init = $this->_escaper->escapeHtmlAttr((string) json_encode([
            'PigeonExpress_Shipping/js/office-picker' => [
                'selectId' => $element->getHtmlId(),
                'offices'  => $this->offices(),
                'text'     => [
                    'placeholder' => (string) __('Search by office name, town or street'),
                    'clear'       => (string) __('Clear'),
                    'noResults'   => (string) __('Nothing found'),
                    'noOffices'   => (string) __('No offices yet. Save your API credentials and run the location sync.'),
                    'linked'      => (string) __('Pigeon Express ID %1'),
                    'missing'     => (string) __('Office %1 is no longer in the Pigeon Express list. Choose another.'),
                ],
            ],
        ]));

        return '<div class="pe-picker pe-picker--office" data-mage-init="' . $init . '">'
            . '<div class="pe-picker__box">'
            . '<input type="text" class="admin__control-text input-text pe-picker__input" data-role="pe-office-search"/>'
            . '</div>'
            . $element->getElementHtml()
            . '<div class="pe-picker__status" data-role="pe-status"></div>'
            . '</div>';
    }

    /**
     * Active offices as {id, name, address}, by name.
     *
     * @return array<int, array{id:string, name:string, address:string}>
     */
    private function offices(): array
    {
        $collection = $this->officeCollectionFactory->create();
        $collection->addFieldToFilter('is_active', 1);
        $collection->setOrder('name', 'ASC');

        $rows = [];
        foreach ($collection as $office) {
            if ($office->getApiId() === null) {
                continue;
            }
            $rows[] = [
                'id'      => (string) $office->getApiId(),
                'name'    => (string) ($office->getName() ?? ''),
                'address' => (string) ($office->getAddress() ?? $office->getCity() ?? ''),
            ];
        }

        return $rows;
    }
}
