/**
 * Pigeon Express: add COD fee to grand total display.
 */
define([
    'PigeonExpress_Shipping/js/model/cod-fee'
], function (codFeeModel) {
    'use strict';

    return function (GrandTotal) {
        return GrandTotal.extend({
            getPureValue: function () {
                return this._super() + codFeeModel.fee();
            }
        });
    };
});
