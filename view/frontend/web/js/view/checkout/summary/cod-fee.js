/**
 * Pigeon Express: show the cash on delivery fee in the checkout summary when the
 * selected payment method is in the configured COD methods list.
 *
 * The amount is whatever the Pigeon Express API charged, delivered as the
 * pigeonexpress_cod_fee total segment. Nothing is calculated here: a rate
 * computed in the browser would go stale the moment the carrier changed it.
 */
define([
    'ko',
    'uiComponent',
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/model/totals',
    'Magento_Catalog/js/price-utils',
    'PigeonExpress_Shipping/js/model/cod-fee'
], function (ko, Component, quote, totals, priceUtils, codFeeModel) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'PigeonExpress_Shipping/checkout/summary/cod-fee'
        },

        isDisplayed: ko.observable(false),
        feeValue: ko.observable(0),

        initialize: function () {
            this._super();

            var self = this;
            // Config is injected via jsLayout component config by LayoutProcessorPlugin.
            var codMethods = this.cod_payment_methods || [];

            function update(method) {
                if (!method) {
                    self.isDisplayed(false);
                    self.feeValue(0);
                    return;
                }

                var shippingMethod = quote.shippingMethod();
                if (!shippingMethod || shippingMethod.carrier_code !== 'pigeonexpress') {
                    self.isDisplayed(false);
                    self.feeValue(0);
                    return;
                }

                var methodCode = method.method || method;
                if (codMethods.indexOf(methodCode) !== -1) {
                    // Server side value only. If the segment is missing the API did
                    // not charge a fee, so nothing is shown.
                    var segment = totals.getSegment('pigeonexpress_cod_fee');
                    var fee = segment && segment.value > 0 ? parseFloat(segment.value) : 0;

                    self.feeValue(fee);
                    codFeeModel.fee(fee);
                    self.isDisplayed(fee > 0);
                } else {
                    self.feeValue(0);
                    codFeeModel.fee(0);
                    self.isDisplayed(false);
                }
            }

            quote.paymentMethod.subscribe(update);
            quote.shippingMethod.subscribe(function () {
                update(quote.paymentMethod());
            });

            update(quote.paymentMethod());

            return this;
        },

        getFormattedFee: function () {
            var format = window.checkoutConfig.priceFormat;
            return priceUtils.formatPrice(this.feeValue(), format);
        }
    });
});
