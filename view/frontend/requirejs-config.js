/**
 * Pigeon Express Shipping - frontend RequireJS.
 * Mixin adds our delivery/location to shipping payload; location-autocomplete sets window.pigeonexpressCheckoutPayload.
 */
var config = {
    // Leaflet 1.9.4 (BSD-2-Clause, see js/lib/leaflet/LICENSE), for the checkout
    // map. Its UMD build calls an anonymous define(), so it is only ever loaded
    // through RequireJS under this name, never with a plain script tag. Loaded
    // on the first "Choose on the map", not with the page.
    paths: {
        'pigeonexpress/leaflet': 'PigeonExpress_Shipping/js/lib/leaflet/leaflet'
    },
    config: {
        mixins: {
            'Magento_Checkout/js/model/shipping-rate-registry': {
                'PigeonExpress_Shipping/js/model/shipping-rate-registry-mixin': true
            },
            'Magento_Checkout/js/model/shipping-save-processor/default': {
                'PigeonExpress_Shipping/js/model/shipping-save-processor/default-mixin': true
            },
            'Bss_OneStepCheckout/js/model/shipping-save-processor/validate': {
                'PigeonExpress_Shipping/js/model/shipping-save-processor/bss-validate-mixin': true
            },
            'Bss_OneStepCheckout/js/model/shipping-save-processor/default': {
                'PigeonExpress_Shipping/js/model/shipping-save-processor/bss-validate-mixin': true
            },
            'Magento_Checkout/js/view/shipping-information': {
                'PigeonExpress_Shipping/js/view/shipping-information-mixin': true
            },
            'Magento_Checkout/js/view/shipping': {
                'PigeonExpress_Shipping/js/view/shipping-mixin': true
            },
            // A zero rate of ours reads "Free" rather than 0.00, in the order
            // summary and in the cart page estimator. The checkout method list
            // is handled by our own row template instead, which the layout
            // processor installs.
            'Magento_Tax/js/view/checkout/summary/shipping': {
                'PigeonExpress_Shipping/js/view/summary-shipping-mixin': true
            },
            'Magento_Checkout/js/view/cart/shipping-rates': {
                'PigeonExpress_Shipping/js/view/cart/shipping-rates-mixin': true
            },
            // A COD payment method's title shows the fee, when Show COD Fee is on.
            'Magento_Checkout/js/view/payment/default': {
                'PigeonExpress_Shipping/js/view/payment/default-mixin': true
            },
        }
    }
};
