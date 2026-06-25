<?php
/**
 * Inject Pigeon Express Office/APS location autocomplete into checkout shipping step.
 * Field is visible when shipping method is pigeonexpress_office or pigeonexpress_aps.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Plugin\Checkout;

use Magento\Checkout\Block\Checkout\LayoutProcessor;
use PigeonExpress\Shipping\Api\ConfigInterface;

class LayoutProcessorPlugin
{

    /**
     * Add location autocomplete to shipping step (shippingAdditional region).
     * Only allow selection from backend results (no free text).
     *
     * @param LayoutProcessor $subject
     * @param array $jsLayout
     * @return array
     */
    public function afterProcess(LayoutProcessor $subject, array $jsLayout): array
    {
        $children = &$jsLayout['components']['checkout']['children']['steps']['children']
            ['shipping-step']['children']['shippingAddress']['children'];

        $children['pigeonexpress-address-visibility'] = [
            'component' => 'PigeonExpress_Shipping/js/view/checkout/shipping/address-visibility',
            'config' => [
                'template' => 'PigeonExpress_Shipping/checkout/shipping/address-visibility',
            ],
            'displayArea' => 'shippingAdditional',
            'sortOrder' => 50,
        ];

        $children['pigeonexpress-location'] = [
            'component' => 'PigeonExpress_Shipping/js/view/checkout/shipping/location-autocomplete',
            'config' => [
                'template' => 'PigeonExpress_Shipping/checkout/shipping/location-autocomplete',
                'carrierCode' => ConfigInterface::CARRIER_CODE,
                'officeMethod' => ConfigInterface::DELIVERY_TYPE_OFFICE,
                'apsMethod' => ConfigInterface::DELIVERY_TYPE_APS,
            ],
            'dataScope' => 'pigeonexpress_location',
            'displayArea' => 'shippingAdditional',
            'label' => (string) __('Select delivery location'),
            'provider' => 'checkoutProvider',
            'sortOrder' => 100,
            'visible' => true,
        ];

        $children['pigeonexpress-instructions'] = [
            'component' => 'PigeonExpress_Shipping/js/view/checkout/shipping/instructions',
            'config' => [
                'template' => 'PigeonExpress_Shipping/checkout/shipping/instructions',
                'carrierCode' => ConfigInterface::CARRIER_CODE,
            ],
            'displayArea' => 'shippingAdditional',
            'sortOrder' => 150,
        ];

        return $jsLayout;
    }
}
