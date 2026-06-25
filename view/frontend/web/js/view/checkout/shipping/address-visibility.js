/**
 * When Pigeon Express Office or APS is selected, add class to body so address fields can be hidden via CSS.
 * For logged-in users: detaches quote from saved customer address (creates a new temporary address)
 * so the customer's address book is never modified by placeholder data.
 * For guests: populates hidden address fields with placeholders so checkout validation passes.
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

    var bodyClassHideAddress = 'pigeonexpress-office-or-aps-selected';
    var bodyClassPhoneRequired = 'pigeonexpress-telephone-required';

    var placeholders = {
        'street': ['PigeonExpress Location', 'Office/APS Delivery'],
        'city': 'PigeonExpress Location',
        'postcode': '00000',
        'telephone': '' // We don't populate phone; user must enter it.
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

        // Keep real address data — only remove customerAddressId so placeholders
        // written later by populatePlaceholders() won't overwrite the saved address.
        var newAddress = createShippingAddressAction({
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

        console.log('PigeonExpress: detached from customer address, created temp address', newAddress);
    }

    function populatePlaceholders(isOfficeOrAps) {
        console.log('PigeonExpress: populatePlaceholders called, isOfficeOrAps =', isOfficeOrAps);

        registry.async('checkout.steps.shipping-step.shippingAddress.shipping-address-fieldset')(function (fieldset) {
            ['street', 'city', 'postcode', 'region_id', 'country_id'].forEach(function (fieldName) {
                var path = 'checkout.steps.shipping-step.shippingAddress.shipping-address-fieldset.' + fieldName;

                // Для улицы заполняем первый элемент street.0
                if (fieldName === 'street') {
                    path += '.0';
                }

                registry.get(path, function (cmp) {
                    if (!cmp) {
                        console.log('PigeonExpress: component not found', fieldName, path);
                        return;
                    }

                    var hasValue = typeof cmp.value === 'function';
                    var hasVisible = typeof cmp.visible === 'function';

                    if (!hasValue && !hasVisible) {
                        console.log('PigeonExpress: component has neither value nor visible observable', fieldName, path, cmp);
                        return;
                    }

                    console.log(
                        'PigeonExpress: processing field',
                        fieldName,
                        'path',
                        path,
                        'current value',
                        hasValue ? cmp.value() : undefined,
                        'visible',
                        hasVisible ? cmp.visible() : undefined
                    );

                    if (isOfficeOrAps) {
                        // Прячем поле через observable visible, если он есть
                        if (hasVisible) {
                            cmp.visible(false);
                        }

                        // Populate if empty
                        if (hasValue) {
                            if (fieldName === 'street') {
                                var streetVal = cmp.value();
                                if (!streetVal) {
                                    // Пишем то же, что и в город, чтобы валидация прошла
                                    console.log('PigeonExpress: setting street to', placeholders.city);
                                    cmp.value(placeholders.city);
                                }
                            } else if (placeholders[fieldName]) {
                                if (!cmp.value()) {
                                    console.log('PigeonExpress: setting', fieldName, 'to', placeholders[fieldName]);
                                    cmp.value(placeholders[fieldName]);
                                }
                            }
                        }
                    } else {
                        // Возвращаем поля, если есть observable visible
                        if (hasVisible) {
                            cmp.visible(true);
                        }

                        // Clear if matches placeholder so user doesn't see dummy data
                        if (hasValue) {
                            if (fieldName === 'street') {
                                var currentStreet = cmp.value();
                                if (currentStreet === placeholders.city) {
                                    console.log('PigeonExpress: clearing street placeholder');
                                    cmp.value('');
                                }
                            } else if (placeholders[fieldName]) {
                                if (cmp.value() === placeholders[fieldName]) {
                                    console.log('PigeonExpress: clearing', fieldName, 'placeholder');
                                    cmp.value('');
                                }
                            }
                        }
                    }
                });
            });
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

                console.log('PigeonExpress: shipping method changed', method, 'isOfficeOrAps =', isOfficeOrAps, 'isAddress =', isAddress);

                if (isOfficeOrAps) {
                    document.body.classList.add(bodyClassHideAddress);
                    detachFromCustomerAddress();
                } else {
                    document.body.classList.remove(bodyClassHideAddress);
                }

                if (isPigeonExpress) {
                    document.body.classList.add(bodyClassPhoneRequired);
                } else {
                    document.body.classList.remove(bodyClassPhoneRequired);
                }

                populatePlaceholders(isOfficeOrAps);
            }
            update();
            quote.shippingMethod.subscribe(update);

        }
    });
});
