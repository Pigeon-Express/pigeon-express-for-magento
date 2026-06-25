/**
 * Add Pigeon Express location and instructions to shipping method summary in checkout sidebar.
 * Reads from window.pigeonexpressCheckoutPayload (set by location-autocomplete / instructions).
 */
define([
    'Magento_Checkout/js/model/quote'
], function (quote) {
    'use strict';

    var carrierCode = 'pigeonexpress';

    return function (Component) {
        return Component.extend({
            defaults: {
                template: 'PigeonExpress_Shipping/shipping-information'
            },

            /**
             * @return {Boolean}
             */
            isPigeonExpressShipping: function () {
                var method = quote.shippingMethod();
                return !!(method && method.carrier_code === carrierCode);
            },

            /**
             * Location line: name + address (for office/APS).
             * @return {String}
             */
            getPigeonExpressLocation: function () {
                if (!this.isPigeonExpressShipping()) {
                    return '';
                }
                var pe = window.pigeonexpressCheckoutPayload || {};
                var name = (pe.location_name || '').trim();
                var addr = (pe.location_address || '').trim();
                if (name && addr) {
                    return name + ', ' + addr;
                }
                return name || addr;
            },

            /**
             * Comment/instructions for Pigeon Express delivery.
             * @return {String}
             */
            getPigeonExpressInstructions: function () {
                if (!this.isPigeonExpressShipping()) {
                    return '';
                }
                return (window.pigeonexpressCheckoutPayload || {}).instructions || '';
            }
        });
    };
});
