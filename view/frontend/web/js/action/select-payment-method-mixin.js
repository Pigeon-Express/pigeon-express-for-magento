/**
 * Pigeon Express: when payment method changes, re-send shipping information
 * so Magento totals (including shipping) are recalculated with COD surcharge.
 */
define([
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/action/set-shipping-information'
], function (quote, setShippingInformationAction) {
    'use strict';

    return function (selectPaymentMethodAction) {
        return function (paymentMethod) {
            var result = selectPaymentMethodAction(paymentMethod);

            try {
                var method = quote.shippingMethod && quote.shippingMethod();
                if (window.console && console.log) {
                    console.log('[PigeonExpress] select-payment-method-mixin', {
                        selectedPayment: paymentMethod,
                        shippingMethod: method
                    });
                }
                if (method && method.carrier_code === 'pigeonexpress') {
                    setShippingInformationAction();
                }
            } catch (e) {
                // ignore - should not break checkout
            }

            return result;
        };
    };
});

