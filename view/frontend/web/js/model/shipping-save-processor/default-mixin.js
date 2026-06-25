/**
 * Ensure Pigeon Express extension_attributes are added to shipping payload.
 * Validates location (Office/APS) and phone before save.
 */
define([
    'ko',
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/model/resource-url-manager',
    'mage/storage',
    'Magento_Checkout/js/model/payment-service',
    'Magento_Checkout/js/model/payment/method-converter',
    'Magento_Checkout/js/model/error-processor',
    'Magento_Checkout/js/model/full-screen-loader',
    'Magento_Checkout/js/action/select-billing-address',
    'Magento_Checkout/js/model/shipping-save-processor/payload-extender',
    'PigeonExpress_Shipping/js/model/shipping-save-processor/payload-mixin',
    'Magento_Ui/js/model/messageList',
    'mage/translate'
], function (
    ko,
    quote,
    resourceUrlManager,
    storage,
    paymentService,
    methodConverter,
    errorProcessor,
    fullScreenLoader,
    selectBillingAddressAction,
    payloadExtender,
    pigeonexpressPayloadMixin,
    messageList,
    $t
) {
    'use strict';

    var carrierCode = 'pigeonexpress';

    return function (original) {
        return {
            saveShippingInformation: function () {
                var payload, method, pe, telephone;

                console.log('[PE default-mixin] saveShippingInformation called');

                if (!quote.billingAddress() && quote.shippingAddress().canUseForBilling()) {
                    selectBillingAddressAction(quote.shippingAddress());
                }

                method = quote.shippingMethod();
                pe = window.pigeonexpressCheckoutPayload || {};
                console.log('[PE default-mixin] method=', method, 'pigeonexpressCheckoutPayload=', pe);
                if (method && method.carrier_code === carrierCode) {
                    if (method.method_code === 'office' || method.method_code === 'aps') {
                        // Only validate if the autocomplete component has already initialized.
                        // If window.pigeonexpressCheckoutPayload is undefined, the component
                        // hasn't loaded yet (e.g. page refresh) — skip validation.
                        if (typeof window.pigeonexpressCheckoutPayload !== 'undefined' &&
                            (!pe.location_id || String(pe.location_id).trim() === '')
                        ) {
                            console.warn('[PE default-mixin] Missing location_id, blocking save');
                            messageList.addErrorMessage({ message: $t('Please select a delivery location.') });
                            return;
                        }
                    }
                    telephone = quote.shippingAddress() && quote.shippingAddress().telephone;
                    var phonePattern = /^[\d\s\-\+\(\)]{5,25}$/;
                    if (telephone === undefined || telephone === null || String(telephone).trim() === '') {
                        messageList.addErrorMessage({ message: $t('Phone number is required for Pigeon Express delivery.') });
                        return;
                    }
                    if (!phonePattern.test(String(telephone).trim())) {
                        messageList.addErrorMessage({ message: $t('Please enter a valid phone number.') });
                        return;
                    }
                }

                payload = {
                    addressInformation: {
                        'shipping_address': quote.shippingAddress(),
                        'billing_address': quote.billingAddress(),
                        'shipping_method_code': quote.shippingMethod()['method_code'],
                        'shipping_carrier_code': quote.shippingMethod()['carrier_code']
                    }
                };

                payloadExtender(payload);
                pigeonexpressPayloadMixin(payload);

                fullScreenLoader.startLoader();

                return storage.post(
                    resourceUrlManager.getUrlForSetShippingInformation(quote),
                    JSON.stringify(payload)
                ).done(
                    function (response) {
                        quote.setTotals(response.totals);
                        paymentService.setPaymentMethods(methodConverter(response['payment_methods']));
                        fullScreenLoader.stopLoader();
                    }
                ).fail(
                    function (response) {
                        errorProcessor.process(response);
                        fullScreenLoader.stopLoader();
                    }
                );
            }
        };
    };
});
