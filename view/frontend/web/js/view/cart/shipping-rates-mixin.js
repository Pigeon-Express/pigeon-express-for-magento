/**
 * Pigeon Express: point the cart page estimator at our row and answer whether a
 * rate of ours is free.
 *
 * The predicate is the same one the checkout row uses, kept here rather than
 * shared through a module of its own because it is four lines and the two
 * components have no other reason to know about each other.
 */
define([], function () {
    'use strict';

    return function (Component) {
        return Component.extend({
            defaults: {
                template: 'PigeonExpress_Shipping/cart/shipping-rates'
            },

            /**
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
