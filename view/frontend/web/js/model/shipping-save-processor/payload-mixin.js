/**
 * Add Pigeon Express delivery/location/instructions to shipping payload (extension_attributes).
 * Connection: location-autocomplete component sets window.pigeonexpressCheckoutPayload; this mixin adds it to the request.
 */
define([
    'Magento_Checkout/js/model/quote'
], function (quote) {
    'use strict';

    var carrierCode = 'pigeonexpress';

    return function (payload) {
        if (!payload || !payload.addressInformation) {
            console.log('[PE payload-mixin] no payload/addressInformation');
            return payload;
        }
        payload.addressInformation['extension_attributes'] = payload.addressInformation['extension_attributes'] || {};

        var method = quote.shippingMethod();
        var pe = window.pigeonexpressCheckoutPayload || {};
        console.log('[PE payload-mixin] method=', method, 'pigeonexpressCheckoutPayload=', pe);
        if (method && method.carrier_code === carrierCode) {
            payload.addressInformation['extension_attributes']['pigeonexpress_delivery_type'] =
                pe.delivery_type || (method.method_code || '');
            payload.addressInformation['extension_attributes']['pigeonexpress_location_id'] =
                pe.location_id || '';
            payload.addressInformation['extension_attributes']['pigeonexpress_location_name'] =
                pe.location_name || '';
            payload.addressInformation['extension_attributes']['pigeonexpress_location_address'] =
                pe.location_address || '';
            payload.addressInformation['extension_attributes']['pigeonexpress_instructions'] =
                pe.instructions || '';
            // The city and street picked from the carrier's lists (address
            // fields), so the shipment need not guess a street by its name.
            payload.addressInformation['extension_attributes']['pigeonexpress_city_id'] =
                pe.pigeonexpress_city_id || '';
            payload.addressInformation['extension_attributes']['pigeonexpress_street_id'] =
                pe.pigeonexpress_street_id || '';
        }
        console.log('[PE payload-mixin] extension_attributes=', payload.addressInformation['extension_attributes']);

        return payload;
    };
});
