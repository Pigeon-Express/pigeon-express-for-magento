/**
 * Pigeon Express: show COD fee (2.5% of subtotal) in checkout summary
 * when the selected payment method is in the configured COD methods list.
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
            var feePercent = this.cod_fee_percent || 2.5;

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
                    var segment = totals.getSegment('pigeonexpress_cod_fee');
                    var fee;
                    if (segment && segment.value > 0) {
                        fee = segment.value;
                    } else {
                        var currentTotals = quote.getTotals()();
                        var subtotal = currentTotals ? parseFloat(currentTotals.subtotal) : 0;
                        fee = Math.round(subtotal * feePercent) / 100;
                    }
                    self.feeValue(fee);
                    codFeeModel.fee(fee);
                    self.isDisplayed(true);
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
