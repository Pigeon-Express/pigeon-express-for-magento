/**
 * Pigeon Express: recalc shipping when payment method changes (COD vs non-COD)
 * while Pigeon Express is the selected shipping carrier.
 *
 * Runs as a lightweight listener on quote.paymentMethod; does NOT touch
 * select-payment-method action directly, чтобы не ломать инициализацию checkout.
 */
define([
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/action/set-shipping-information'
], function (quote, setShippingInformationAction) {
    'use strict';

    var lastKey = null;
    var initialized = false;

    /**
     * Whether this payment method is one the merchant configured as cash on
     * delivery. Guessing from the method code would disagree with the admin
     * setting the rest of the module relies on.
     */
    function isCodMethod(methodCode) {
        if (!methodCode) {
            return false;
        }

        var config = window.checkoutConfig && window.checkoutConfig.pigeonexpress,
            codMethods = (config && config.cod_payment_methods) || [];

        return codMethods.indexOf(String(methodCode)) !== -1;
    }

    function maybeRecalculate(method) {
        try {
            // Skip the first call on page load — shipping info is already saved,
            // recalculation is only needed when the user actively changes payment method.
            if (!initialized) {
                initialized = true;
                var methodCode0 = method && method.method;
                if (methodCode0) {
                    var cod0 = isCodMethod(methodCode0);
                    lastKey = methodCode0 + ':' + (cod0 ? '1' : '0');
                }
                return;
            }

            var shipping = quote.shippingMethod && quote.shippingMethod();
            if (!shipping || shipping.carrier_code !== 'pigeonexpress') {
                lastKey = null;
                return;
            }

            var methodCode = method && method.method;
            if (!methodCode) {
                lastKey = null;
                return;
            }

            var cod = isCodMethod(methodCode);
            var currentKey = methodCode + ':' + (cod ? '1' : '0');

            if (lastKey === currentKey) {
                return;
            }

            lastKey = currentKey;
            setShippingInformationAction();
        } catch (e) {
            if (window.console && console.warn) {
                console.warn('[PigeonExpress] payment-cod-recalc error', e);
            }
        }
    }

    quote.paymentMethod.subscribe(maybeRecalculate);

    return {};
});

