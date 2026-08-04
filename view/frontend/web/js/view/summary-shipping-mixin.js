/**
 * Pigeon Express: the order summary shipping line says Free rather than 0,00.
 *
 * Only for our own rates, and only for a real zero. Magento's own carriers keep
 * their formatting, and the "not yet calculated" message is left alone, because
 * an unknown shipping cost is not a free one.
 */
define([
    'Magento_Checkout/js/model/quote',
    'mage/translate'
], function (quote, $t) {
    'use strict';

    /**
     * A figure that is zero once read as a number. Totals arrive as strings.
     */
    function isZero(value) {
        return value !== null && value !== undefined && value !== '' && Number(value) === 0;
    }

    function isOurs() {
        var method = quote.shippingMethod();

        return !!method && method.carrier_code === 'pigeonexpress';
    }

    return function (Component) {
        return Component.extend({
            getIncludingValue: function () {
                if (this.isCalculated() && isOurs() && isZero(this.totals()['shipping_incl_tax'])) {
                    return $t('Free');
                }

                return this._super();
            },

            getExcludingValue: function () {
                if (this.isCalculated() && isOurs() && isZero(this.totals()['shipping_amount'])) {
                    return $t('Free');
                }

                return this._super();
            }
        });
    };
});
