/**
 * Add clear(key) to shipping rate registry so we can invalidate cache after PE location selection
 * and force a fresh estimate (with new price).
 */
define([], function () {
    'use strict';

    var clearedKeys = {};

    return function (registry) {
        registry.clear = function (key) {
            if (key) {
                clearedKeys[key] = true;
            } else {
                clearedKeys = {};
            }
        };

        var originalGet = registry.get.bind(registry);
        registry.get = function (addressKey) {
            if (clearedKeys[addressKey]) {
                return false;
            }
            return originalGet(addressKey);
        };

        var originalSet = registry.set.bind(registry);
        registry.set = function (addressKey, data) {
            delete clearedKeys[addressKey];
            return originalSet(addressKey, data);
        };

        return registry;
    };
});
