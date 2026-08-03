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
use Magento\Framework\View\Asset\Repository as AssetRepository;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\Config\Source\CheckoutLogo;

class LayoutProcessorPlugin
{
    /** @var ConfigInterface */
    private $config;

    /** @var AssetRepository */
    private $assetRepository;

    /** @var CheckoutLogo */
    private $logos;

    public function __construct(
        ConfigInterface $config,
        AssetRepository $assetRepository,
        CheckoutLogo $logos
    ) {
        $this->config = $config;
        $this->assetRepository = $assetRepository;
        $this->logos = $logos;
    }

    /**
     * Point the shipping component at our method row, and hand it the logo.
     *
     * Done here rather than from a JavaScript mixin. The component reads both of
     * these out of its own config, which is this array, so setting them server
     * side is the one place the value cannot be missed, and it can be checked
     * without loading a browser. Only when a logo is actually configured: with
     * none, Magento's own row template is left entirely alone.
     *
     * @param array<string, mixed> $shippingAddress
     */
    private function applyCarrierLogo(array &$shippingAddress): void
    {
        $logo = $this->config->getCheckoutLogo();
        $file = $this->logos->fileFor($logo);

        if ($file === '') {
            return;
        }

        try {
            $url = $this->assetRepository->getUrlWithParams(
                'PigeonExpress_Shipping::images/' . $file,
                ['_secure' => true]
            );
        } catch (\Throwable $e) {
            return;
        }

        $shippingAddress['config'] = ($shippingAddress['config'] ?? []) + [];
        $shippingAddress['config']['shippingMethodItemTemplate'] =
            'PigeonExpress_Shipping/shipping-address/shipping-method-item';
        $shippingAddress['config']['pigeonexpressLogoUrl'] = $url;
        $shippingAddress['config']['pigeonexpressLogoDark'] = $this->logos->needsDarkBackdrop($logo);
    }

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
        $shippingAddress = &$jsLayout['components']['checkout']['children']['steps']['children']
            ['shipping-step']['children']['shippingAddress'];

        $this->applyCarrierLogo($shippingAddress);

        $children = &$shippingAddress['children'];

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

        $children['pigeonexpress-address'] = [
            'component' => 'PigeonExpress_Shipping/js/view/checkout/shipping/address-fields',
            'config' => [
                'template' => 'PigeonExpress_Shipping/checkout/shipping/address-fields',
                'carrierCode' => ConfigInterface::CARRIER_CODE,
                'addressMethod' => ConfigInterface::DELIVERY_TYPE_ADDRESS,
            ],
            'displayArea' => 'shippingAdditional',
            'sortOrder' => 120,
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
