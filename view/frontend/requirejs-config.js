/**
 * Pigeon Express Shipping - frontend RequireJS.
 * Mixin adds our delivery/location to shipping payload; location-autocomplete sets window.pigeonexpressCheckoutPayload.
 */
var config = {
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
        }
    }
};
