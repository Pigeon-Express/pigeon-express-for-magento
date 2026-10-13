<?php
/**
 * Renders the pickup address City and Street fields as pickers.
 *
 * Typing searches the carrier's own lists; choosing a result writes its id into
 * a hidden field beside it, so the origin used for rating and waybills is one
 * the carrier recognises rather than a name it has to guess at. Laid out and
 * behaving as the checkout's address fields do, so a merchant who has seen one
 * recognises the other.
 */
declare(strict_types=1);

namespace PigeonExpress\Shipping\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class PickupAddressPicker extends Field
{
    /**
     * Fields that make up the address. They inherit as one: a city saved for a
     * store view with the street still taken from the default scope would be an
     * address that exists nowhere.
     */
    private const ADDRESS_FIELDS = [
        'city_label', 'city_id', 'postal_code', 'street_label', 'street_id', 'street_number',
    ];

    /**
     * The behaviour is a RequireJS component wired up with data-mage-init.
     * An inline script here would violate the admin Content Security Policy.
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        $elementId = $element->getHtmlId();
        $role = substr($elementId, -strlen('_street_label')) === '_street_label' ? 'street' : 'city';

        // Sibling fields share the group prefix, e.g.
        // carriers_pigeonexpress_shipment_origin_pickup_address_city_label.
        $prefix = substr($elementId, 0, (int) strrpos($elementId, '_pickup_address_')) . '_pickup_address_';

        $ids = [];
        foreach (self::ADDRESS_FIELDS as $field) {
            $ids[$field] = $prefix . $field;
        }

        $config = [
            'role'      => $role,
            'ids'       => $ids,
            'searchUrl' => $role === 'street'
                ? $this->getUrl('pigeonexpress/pickup/streetsearch', ['store_id' => $this->resolveStoreId()])
                : $this->getUrl('pigeonexpress/pickup/citysearch'),
            'text'      => [
                'placeholder'        => (string) ($role === 'street' ? __('Search for a street') : __('Search for a city or postal code')),
                'clear'              => (string) __('Clear'),
                'noResults'          => (string) __('Nothing found'),
                'searching'          => (string) __('Searching...'),
                'chooseCityFirst'    => (string) __('Choose a city first'),
                'streetsUnavailable' => (string) __('The Pigeon Express street list is unavailable. Type the street by hand.'),
                'linked'             => (string) __('Pigeon Express ID %1'),
                'cityNotLinked'      => (string) __('Not chosen from the list. Rates and shipments need a city from the Pigeon Express list.'),
                'streetNotLinked'    => (string) __('Not on the Pigeon Express list. It is sent as written.'),
                'cityRequired'       => (string) __('Choose the city from the list.'),
            ],
        ];

        $init = $this->_escaper->escapeHtmlAttr((string) json_encode([
            'PigeonExpress_Shipping/js/pickup-address-picker' => $config,
        ]));

        return '<div class="pe-picker" data-mage-init="' . $init . '">'
            . '<div class="pe-picker__box">' . $element->getElementHtml() . '</div>'
            . '<div class="pe-picker__status" data-role="pe-status"></div>'
            . '</div>';
    }

    /**
     * Store whose credentials the street search should use: the store view or
     * website open in the scope switcher, or the default store otherwise.
     */
    private function resolveStoreId(): int
    {
        $request = $this->getRequest();

        try {
            if ($request->getParam('store')) {
                return (int) $this->_storeManager->getStore($request->getParam('store'))->getId();
            }
            if ($request->getParam('website')) {
                $store = $this->_storeManager->getWebsite($request->getParam('website'))->getDefaultStore();

                return $store ? (int) $store->getId() : 0;
            }
        } catch (\Throwable $e) {
            return 0;
        }

        return 0;
    }
}
