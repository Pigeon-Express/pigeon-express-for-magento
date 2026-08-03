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
     * The stock address fields the carrier's own replace.
     *
     * `region` is left out on purpose. Magento's own region component decides
     * between the dropdown and the free text box from the chosen country, and
     * for Bulgaria, which is everywhere Pigeon Express delivers, it has already
     * settled on the dropdown. Forcing the other one either way fights it.
     */
    private const REPLACED_FIELDS = ['city', 'postcode', 'region_id', 'country_id'];

    /**
     * Take the replaced fields out of the form before it is rendered.
     *
     * Done here rather than from the component that manages them as the chosen
     * method changes, because that one can only run once Knockout has already
     * drawn the form: the fields appeared, then vanished a moment later. Marked
     * invisible in the layout, they are never drawn in the first place.
     *
     * This is the state the page opens in. From there the component takes over,
     * and hands the fields straight back if the customer picks a carrier that
     * is not this one.
     *
     * @param array<string, mixed> $shippingAddress
     */
    private function hideReplacedFields(array &$shippingAddress): void
    {
        if (!$this->config->isOverrideAddressFields()) {
            return;
        }

        $fields = &$shippingAddress['children']['shipping-address-fieldset']['children'];
        if (!is_array($fields)) {
            return;
        }

        foreach (self::REPLACED_FIELDS as $name) {
            if (isset($fields[$name])) {
                $fields[$name]['visible'] = false;
            }
        }

        // Street is a group of two lines rather than a field. Its own wrapper
        // carries a legend and no visibility of its own, so the stylesheet
        // takes that, keyed off a class on the body which is set server side
        // for the same reason this is.
        if (isset($fields['street']['children']) && is_array($fields['street']['children'])) {
            foreach ($fields['street']['children'] as &$line) {
                $line['visible'] = false;
            }
            unset($line);
        }
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
        $this->hideReplacedFields($shippingAddress);

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
