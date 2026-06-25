/**
 * Pigeon Express: delivery instructions field. Visible when Pigeon Express is selected.
 * Updates window.pigeonexpressCheckoutPayload.instructions.
 */
define([
    'ko',
    'jquery',
    'Magento_Checkout/js/model/quote',
    'uiComponent',
    'mage/translate'
], function (ko, $, quote, Component, $t) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'PigeonExpress_Shipping/checkout/shipping/instructions',
            carrierCode: 'pigeonexpress'
        },

        instructions: ko.observable(''),

        initialize: function () {
            this._super();
            window.pigeonexpressCheckoutPayload = window.pigeonexpressCheckoutPayload || {};
            this.instructions.subscribe(this.updatePayload, this);
            return this;
        },

        isVisible: function () {
            var method = quote.shippingMethod();
            return method && method.carrier_code === this.carrierCode;
        },

        updatePayload: function (value) {
            window.pigeonexpressCheckoutPayload = window.pigeonexpressCheckoutPayload || {};
            window.pigeonexpressCheckoutPayload.instructions = value || '';
        }
    });
});
