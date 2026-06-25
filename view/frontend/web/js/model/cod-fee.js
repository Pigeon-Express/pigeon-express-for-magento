/**
 * Shared observable for COD fee value — written by cod-fee summary component,
 * read by grand-total mixin to update the displayed total.
 */
define(['ko'], function (ko) {
    'use strict';
    return {
        fee: ko.observable(0)
    };
});
