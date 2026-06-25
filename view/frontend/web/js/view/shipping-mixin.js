/**
 * Pigeon Express: when any Pigeon Express shipping method is selected,
 * force the new-address form and hide saved address cards.
 */
define([
    'Magento_Checkout/js/model/quote',
    'Magento_Customer/js/model/customer'
], function (quote, customer) {
    'use strict';

    var bodyClass = 'pigeonexpress-shipping-active';

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
            }
        });
    };
});
