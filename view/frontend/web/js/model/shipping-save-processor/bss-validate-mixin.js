/**
 * Bss OneStepCheckout: add Pigeon Express location to shipping payload.
 * Bss uses its own processor (validate.js); their payloadExtender overwrites extension_attributes.
 * This mixin runs after payloadExtender and adds our data before the request is sent.
 */
define([
    'underscore',
    'Magento_Checkout/js/model/quote',
    'mage/storage',
    'Magento_Checkout/js/model/error-processor',
    'Magento_Checkout/js/action/select-billing-address',
    'Magento_Checkout/js/model/resource-url-manager',
    'Bss_OneStepCheckout/js/model/shipping-save-processor/payload-extender',
    'Magento_Customer/js/model/customer'
], function (_, quote, storage, errorProcessor, selectBillingAddressAction, resourceUrlManager, payloadExtender, customer) {
    'use strict';

    var carrierCode = 'pigeonexpress';

    return function (original) {
        return {
            saveShippingInformation: function () {
                var payload, billingAddress, method, pe;

                console.log('[PE bss-validate-mixin] saveShippingInformation called');

                if (!quote.billingAddress()) {
                    selectBillingAddressAction(quote.shippingAddress());
                }

                billingAddress = quote.billingAddress();
                if (!customer.isLoggedIn() && billingAddress) {
                    if (!_.isUndefined(billingAddress.street) && billingAddress.street.length === 0) {
                        delete billingAddress.street;
                    } else if (_.isUndefined(billingAddress.street)) {
                        delete billingAddress.street;
                    }
                }

                payload = {
                    addressInformation: {
                        shipping_address: quote.shippingAddress(),
                        billing_address: billingAddress,
                        shipping_method_code: quote.shippingMethod() ? quote.shippingMethod()['method_code'] : '',
                        shipping_carrier_code: quote.shippingMethod() ? quote.shippingMethod()['carrier_code'] : ''
                    }
                };

                payloadExtender(payload);

                payload.addressInformation.extension_attributes = payload.addressInformation.extension_attributes || {};
                method = quote.shippingMethod();
                pe = window.pigeonexpressCheckoutPayload || {};
                console.log('[PE bss-validate-mixin] method=', method, 'pigeonexpressCheckoutPayload=', pe);
                if (method && method.carrier_code === carrierCode) {
                    payload.addressInformation.extension_attributes.pigeonexpress_delivery_type =
                        pe.delivery_type || (method.method_code || '');
                    payload.addressInformation.extension_attributes.pigeonexpress_location_id = pe.location_id || '';
                    payload.addressInformation.extension_attributes.pigeonexpress_location_name = pe.location_name || '';
                    payload.addressInformation.extension_attributes.pigeonexpress_location_address = pe.location_address || '';
                    payload.addressInformation.extension_attributes.pigeonexpress_instructions = pe.instructions || '';
                }
                console.log('[PE bss-validate-mixin] payload.addressInformation.extension_attributes=', payload.addressInformation.extension_attributes);

                return storage.post(
                    resourceUrlManager.getUrlForSetShippingInformation(quote),
                    JSON.stringify(payload)
                ).fail(
                    function (response) {
                        errorProcessor.process(response);
                    }
                );
            }
        };
    };
});
