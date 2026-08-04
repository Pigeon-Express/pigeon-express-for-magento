/**
 * Pigeon Express: when any Pigeon Express shipping method is selected,
 * force the new-address form and hide saved address cards.

 */
define([
    'Magento_Checkout/js/model/quote',
    'Magento_Customer/js/model/customer',
    'uiRegistry'
], function (quote, customer, registry) {
    'use strict';

    var bodyClass = 'pigeonexpress-shipping-active',
        addressFieldsPath = 'checkout.steps.shipping-step.shippingAddress.pigeonexpress-address';

    return function (Component) {
        return Component.extend({
            initialize: function () {
                this._super();

                var self = this;
                var prevCarrier = null;

                function update(method) {
                    var carrier = method ? method.carrier_code : null;
                    var isPE = carrier === 'pigeonexpress';
                    var wasNotPE = prevCarrier !== 'pigeonexpress';
                    prevCarrier = carrier;

                    if (isPE) {
                        document.body.classList.add(bodyClass);
                        // For logged-in users: open the new address form only when
                        // transitioning TO PE (not on every totals recalculation).
                        if (wasNotPE && customer.isLoggedIn() && typeof self.showFormPopUp === 'function') {
                            self.showFormPopUp();
                        }
                    } else {
                        document.body.classList.remove(bodyClass);
                    }
                }

                quote.shippingMethod.subscribe(update);
                update(quote.shippingMethod());

                return this;
            },

            /**
             * Also check the carrier's own address fields before moving on.
             *
             * They stand in for the stock street, city and postcode, which are
             * hidden while they are shown, and Magento passes a hidden field
             * without looking at it. Without this the step would move on from a
             * form with an empty street on it.
             *
             * Magento's own checks run either way, so a missing email or
             * shipping method is reported in the same pass rather than the
             * customer being sent back a second time for it.
             */
            validateShippingInformation: function () {
                var ours = true,
                    fields = registry.get(addressFieldsPath);

                if (fields && typeof fields.validate === 'function' &&
                    typeof fields.isVisible === 'function' && fields.isVisible()
                ) {
                    ours = fields.validate();
                }

                return this._super() && ours;
            },

            /**
             * Whether a rate of ours costs the customer nothing.
             *
             * Both figures are checked because which one the row would have
             * displayed depends on the store's tax display setting, and a rate
             * that is free is free either way. Read loosely: these arrive as
             * strings over JSON.
             */
            pigeonexpressIsFree: function (method) {
                if (!method) {
                    return false;
                }

                var inclTax = method.price_incl_tax,
                    exclTax = method.price_excl_tax,
                    amount = method.amount;

                return Number(inclTax !== undefined ? inclTax : amount) === 0 &&
                    Number(exclTax !== undefined ? exclTax : amount) === 0;
            }
        });
    };
});
