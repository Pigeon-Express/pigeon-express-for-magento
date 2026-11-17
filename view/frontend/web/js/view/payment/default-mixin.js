/**
 * Pigeon Express: a COD payment method's title carries the fee, "(+ 0,40 €)".
 *
 * Only with Show COD Fee on, only for the methods listed in COD Payment Methods,
 * and only while the totals report a fee. The figure comes from the
 * pigeonexpress_cod_fee total segment, which the server fills from the API, so
 * nothing is computed here. Reading quote.totals() makes the title follow every
 * totals refresh, including a switch of delivery type or a free shipping waiver.
 */
define([
    'Magento_Checkout/js/model/quote',
    'Magento_Catalog/js/price-utils'
], function (quote, priceUtils) {
    'use strict';

    var config = (window.checkoutConfig && window.checkoutConfig.pigeonexpress) || {};

    function codFee() {
        var totals = quote.totals(),
            segments = totals && totals['total_segments'],
            i;

        if (!segments) {
            return 0;
        }

        for (i = 0; i < segments.length; i++) {
            if (segments[i].code === 'pigeonexpress_cod_fee') {
                return Number(segments[i].value) || 0;
            }
        }

        return 0;
    }

    return function (Component) {
        return Component.extend({
            getTitle: function () {
                var title = this._super(),
                    fee;

                if (!config['show_cod_fee'] || (config['cod_payment_methods'] || []).indexOf(this.getCode()) === -1) {
                    return title;
                }

                fee = codFee();

                if (fee <= 0) {
                    return title;
                }

                return title + ' (+ ' + priceUtils.formatPrice(fee, quote.getPriceFormat()) + ')';
            }
        });
    };
});
