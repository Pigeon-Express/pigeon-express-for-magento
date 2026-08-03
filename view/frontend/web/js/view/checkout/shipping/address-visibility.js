/**
 * Which of Magento's address fields apply, given the Pigeon Express method.
 *
 * Office and locker deliveries do not have a street address at all, and with
 * the override on the carrier's own fields collect one. Either way the stock
 * street, city, postcode, region and country have to go.
 *
 * They are hidden through each field's own visible observable rather than with
 * CSS. Luma gives these fields no class to select on, only a name attribute, so
 * there is nothing for a stylesheet to hook; and Magento treats a field whose
 * visible observable is false as valid without checking it, which is what stops
 * an empty box the customer cannot see from blocking a form it cannot explain.
 * The one exception is the street fieldset, which owns a legend and a second
 * line that are not fields, so its wrapper is hidden in the stylesheet too.
 *
 * For office and locker the hidden fields are filled with placeholders, since
 * nothing else will fill them. With the override they are left to the carrier's
 * fields, which write real values into them, and validating them is our own job:
 * see validate() in address-fields.js.
 *
 * For logged-in users the quote is detached from any saved customer address
 * first, so the address book is never modified by placeholder data.
 */
define([
    'ko',
    'Magento_Checkout/js/model/quote',
    'Magento_Customer/js/model/customer',
    'Magento_Checkout/js/action/create-shipping-address',
    'uiComponent',
    'uiRegistry'
], function (ko, quote, customer, createShippingAddressAction, Component, registry) {
    'use strict';

    var bodyClassHideAddress = 'pigeonexpress-office-or-aps-selected',
        bodyClassOverrideAddress = 'pigeonexpress-address-override';
    var bodyClassPhoneRequired = 'pigeonexpress-telephone-required';

    var FIELDSET = 'checkout.steps.shipping-step.shippingAddress.shipping-address-fieldset';

    /**
     * Every stock field the carrier's own address replaces. Name, company,
     * email and telephone are deliberately absent: nothing here collects them,
     * and the telephone least of all, it being how Pigeon Express identifies a
     * recipient.
     */
    var STOCK_FIELDS = ['street.0', 'street.1', 'city', 'postcode', 'region_id', 'region', 'country_id'];

    /**
     * What an office or locker delivery puts in the fields it hides. The parcel
     * is not going to a street address, but Magento still wants one.
     */
    var placeholders = {
        'street.0': 'PigeonExpress Location',
        'city': 'PigeonExpress Location',
        'postcode': '00000'
    };

    /**
     * For logged-in users: if the current shipping address is linked to a customer address book entry
     * (has customerAddressId), replace it with a new temporary address that has no customerAddressId.
     * This prevents placeholder data from being written back to the customer's saved address.
     */
    function detachFromCustomerAddress() {
        if (!customer.isLoggedIn()) {
            return;
        }

        var currentAddress = quote.shippingAddress();
        if (!currentAddress || !currentAddress.customerAddressId) {
            return;
        }

        var defaultCountryId = (window.checkoutConfig && window.checkoutConfig.defaultCountryId)
            ? window.checkoutConfig.defaultCountryId
            : (currentAddress.countryId || 'UA');

        // Keep real address data, only remove customerAddressId so placeholders
        // written later by populatePlaceholders() won't overwrite the saved address.
        createShippingAddressAction({
            firstname: currentAddress.firstname || '',
            lastname: currentAddress.lastname || '',
            telephone: currentAddress.telephone || '',
            street: currentAddress.street || [placeholders.city],
            city: currentAddress.city || placeholders.city,
            postcode: currentAddress.postcode || placeholders.postcode,
            country_id: currentAddress.countryId || defaultCountryId,
            region_id: currentAddress.regionId || null,
            region: currentAddress.region || null,
            region_code: currentAddress.regionCode || null,
            save_in_address_book: 0
        });
    }

    /**
     * Run a callback against every stock address field, once each exists.
     */
    function eachStockField(callback) {
        registry.async(FIELDSET)(function () {
            STOCK_FIELDS.forEach(function (name) {
                registry.get(FIELDSET + '.' + name, function (cmp) {
                    if (cmp) {
                        callback(cmp, name);
                    }
                });
            });
        });
    }

    /**
     * Show or hide the stock address, and with it Magento's checking of it.
     */
    function setStockAddressVisible(visible) {
        eachStockField(function (cmp) {
            if (typeof cmp.visible === 'function') {
                cmp.visible(visible);
            }
        });
    }

    /**
     * Fill the hidden fields for an office or locker delivery, or clear what was
     * filled once the delivery is something else. Only ever the placeholder is
     * cleared, so an address the customer typed is left alone.
     */
    function setPlaceholders(on) {
        eachStockField(function (cmp, name) {
            var placeholder = placeholders[name];

            if (!placeholder || typeof cmp.value !== 'function') {
                return;
            }

            if (on) {
                if (!cmp.value()) {
                    cmp.value(placeholder);
                }
            } else if (cmp.value() === placeholder) {
                cmp.value('');
            }
        });
    }

    return Component.extend({
        defaults: {
            template: 'PigeonExpress_Shipping/checkout/shipping/address-visibility'
        },

        init: function () {
            function update() {
                var method = quote.shippingMethod();
                var isPigeonExpress = method && method.carrier_code === 'pigeonexpress';
                var isOfficeOrAps = isPigeonExpress &&
                    (method.method_code === 'office' || method.method_code === 'aps');
                var isAddress = isPigeonExpress && method.method_code === 'address';
                var settings = (window.checkoutConfig && window.checkoutConfig.pigeonexpress) || {};
                var overrideAddress = isAddress && !!settings.override_address_fields;

                if (isOfficeOrAps) {
                    document.body.classList.add(bodyClassHideAddress);
                    detachFromCustomerAddress();
                } else {
                    document.body.classList.remove(bodyClassHideAddress);
                }

                if (overrideAddress) {
                    document.body.classList.add(bodyClassOverrideAddress);
                } else {
                    document.body.classList.remove(bodyClassOverrideAddress);
                }

                if (isPigeonExpress) {
                    document.body.classList.add(bodyClassPhoneRequired);
                } else {
                    document.body.classList.remove(bodyClassPhoneRequired);
                }

                // Both cases replace the stock address; only one of them has
                // nowhere real to get the values from.
                setStockAddressVisible(!(isOfficeOrAps || overrideAddress));
                setPlaceholders(isOfficeOrAps);
            }
            update();
            quote.shippingMethod.subscribe(update);

        }
    });
});
