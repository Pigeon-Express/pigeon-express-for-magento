/**
 * Pigeon Express: the address fields, filled from the carrier's own lists.
 *
 * A customer who types a street loosely is the usual cause of a parcel going
 * astray. Pigeon Express identifies a recipient by phone, and when it cannot
 * resolve the address it was given it quietly substitutes one it already holds
 * for that number, so the label reads correctly and the parcel arrives somewhere
 * else. Choosing from these lists is what stops that happening at source rather
 * than being caught later on the order screen.
 *
 * The composed address is written into Magento's own city, street and postcode
 * components rather than replacing them. Those fields feed tax, invoices, emails
 * and the address book, and an order whose Magento address disagrees with its
 * waybill would recreate the exact problem this is here to prevent. Nothing here
 * ever writes a placeholder.
 */
define([
    'ko',
    'jquery',
    'mage/url',
    'Magento_Checkout/js/model/quote',
    'uiComponent',
    'mage/translate',
    'uiRegistry'
], function (ko, $, url, quote, Component, $t, registry) {
    'use strict';

    var FIELDSET = 'checkout.steps.shipping-step.shippingAddress.shipping-address-fieldset.';

    /**
     * Read on every call, never captured: see shipping-mixin.js.
     */
    function settings() {
        return (window.checkoutConfig && window.checkoutConfig.pigeonexpress) || {};
    }

    return Component.extend({
        defaults: {
            template: 'PigeonExpress_Shipping/checkout/shipping/address-fields',
            carrierCode: 'pigeonexpress',
            addressMethod: 'address',
            minChars: 2,
            searchDelay: 300
        },

        initialize: function () {
            this._super();

            this.uid = this.uid || ('pigeonexpress-address-' + Math.random().toString(36).substr(2, 9));

            this.cityQuery = ko.observable('');
            this.cityResults = ko.observableArray([]);
            this.cityResultsVisible = ko.observable(false);
            this.selectedCity = ko.observable(null);

            this.streetQuery = ko.observable('');
            this.streetResults = ko.observableArray([]);
            this.streetResultsVisible = ko.observable(false);
            this.streetNotice = ko.observable('');
            this.selectedStreet = ko.observable(null);

            this.streetNumber = ko.observable('');
            this.buildingNumber = ko.observable('');
            this.entrance = ko.observable('');
            this.floor = ko.observable('');
            this.apartment = ko.observable('');

            // Pigeon Express holds one postal code per settlement, so choosing a
            // city sets it. A couple of dozen of the smallest have none on
            // record, and there the box is opened for the customer to fill in:
            // Magento will not take a Bulgarian address without one.
            this.postcode = ko.observable('');

            this.cityError = ko.observable('');
            this.streetError = ko.observable('');
            this.postcodeError = ko.observable('');

            window.pigeonexpressCheckoutPayload = window.pigeonexpressCheckoutPayload || {};

            var self = this;

            // Anything that changes the address has to reach the quote, so the
            // rate is quoted against the address the parcel is actually going to.
            [
                this.streetNumber, this.buildingNumber, this.entrance,
                this.floor, this.apartment, this.postcode
            ].forEach(function (field) {
                field.subscribe(function () {
                    self.publish();
                });
            });

            return this;
        },

        /**
         * Only for address delivery, and only when the merchant asked for it.
         */
        isVisible: function () {
            if (!settings().override_address_fields) {
                return false;
            }

            var method = quote.shippingMethod();

            return !!method
                && method.carrier_code === this.carrierCode
                && method.method_code === this.addressMethod;
        },

        // -- City ------------------------------------------------------------

        onCityInput: function (data, event) {
            var self = this,
                q = (event && event.target && event.target.value) || '';

            q = typeof q === 'string' ? q.trim() : '';
            this.cityQuery(q);
            this.selectedCity(null);
            this.cityError('');

            clearTimeout(this._cityTimeout);

            if (q.length < this.minChars) {
                this.cityResults([]);
                this.cityResultsVisible(false);
                return;
            }

            this._cityTimeout = setTimeout(function () {
                $.getJSON(url.build('pigeonexpress/checkout/citySearch'), {q: q}).done(function (data) {
                    self.cityResults((data && data.items) || []);
                    self.cityResultsVisible(true);
                }).fail(function () {
                    self.cityResults([]);
                });
            }, this.searchDelay);
        },

        selectCity: function (city) {
            this.selectedCity(city);
            this.cityQuery(city.name || '');
            this.cityResults([]);
            this.cityResultsVisible(false);
            this.cityError('');

            this.postcode(city.postcode || '');
            this.postcodeError('');

            // A street belongs to one city, so the previous choice is void.
            this.selectedStreet(null);
            this.streetQuery('');
            this.streetResults([]);
            this.streetNotice('');
            this.streetError('');

            this.publish();
        },

        /**
         * The postal code is only the customer's to type where we have none.
         */
        isPostcodeReadonly: function () {
            var city = this.selectedCity();

            return !city || !!city.postcode;
        },

        // -- Street ----------------------------------------------------------

        onStreetInput: function (data, event) {
            var self = this,
                q = (event && event.target && event.target.value) || '';

            q = typeof q === 'string' ? q.trim() : '';
            this.streetQuery(q);
            this.selectedStreet(null);
            this.streetNotice('');
            this.streetError('');

            clearTimeout(this._streetTimeout);

            // A street list belongs to one city, so there is nothing to search
            // until one is chosen. Say so rather than leaving the box silent.
            if (!this.selectedCity()) {
                this.streetResults([]);
                this.streetResultsVisible(false);
                this.streetNotice(q.length ? $t('Choose a city first') : '');
                this.publish();
                return;
            }

            if (q.length < this.minChars) {
                this.streetResults([]);
                this.streetResultsVisible(false);
                this.publish();
                return;
            }

            this._streetTimeout = setTimeout(function () {
                $.getJSON(url.build('pigeonexpress/checkout/streetSearch'), {
                    city_id: self.selectedCity().id,
                    q: q
                }).done(function (data) {
                    if (data && data.error) {
                        self.streetNotice(data.error);
                        self.streetResults([]);
                        return;
                    }
                    self.streetResults((data && data.items) || []);
                    self.streetResultsVisible(true);
                }).fail(function () {
                    self.streetResults([]);
                });
            }, this.searchDelay);

            this.publish();
        },

        selectStreet: function (street) {
            this.selectedStreet(street);
            this.streetQuery(street.name || '');
            this.streetResults([]);
            this.streetResultsVisible(false);
            this.streetNotice('');
            this.streetError('');
            this.publish();
        },

        /**
         * Is the form filled in well enough to send a parcel on?
         *
         * Ours to answer, because the stock fields these feed are hidden, and
         * Magento passes a hidden field without looking at it. Called from the
         * shipping step, which will not move on while it says no.
         */
        validate: function () {
            var required = $t('This is a required field.'),
                valid = true;

            if (!this.selectedCity()) {
                this.cityError(required);
                valid = false;
            } else {
                this.cityError('');
            }

            if (!$.trim(this.streetQuery())) {
                this.streetError(required);
                valid = false;
            } else {
                this.streetError('');
            }

            // Only ever empty where Pigeon Express holds no code for the
            // settlement, in which case the box was opened for the customer.
            if (!$.trim(this.postcode())) {
                this.postcodeError(required);
                valid = false;
            } else {
                this.postcodeError('');
            }

            return valid;
        },

        /**
         * The address as one line, the way it would be written down.
         */
        composedStreet: function () {
            var parts = [],
                street = $.trim(this.streetQuery());

            if (street) {
                parts.push(street + ($.trim(this.streetNumber()) ? ' ' + $.trim(this.streetNumber()) : ''));
            }

            [
                ['бл.', this.buildingNumber()],
                ['вх.', this.entrance()],
                ['ет.', this.floor()],
                ['ап.', this.apartment()]
            ].forEach(function (pair) {
                if ($.trim(pair[1] || '')) {
                    parts.push(pair[0] + ' ' + $.trim(pair[1]));
                }
            });

            return parts.join(', ');
        },

        /**
         * Push the address into Magento's own fields and onto the payload.
         *
         * Real values, never placeholders. The stock address is the address of
         * record and everything downstream reads it, so it has to say the same
         * thing the waybill will.
         */
        publish: function () {
            var city = this.selectedCity();

            // Written every time, including empty. Writing only what is filled
            // in would leave a street the customer had since deleted standing in
            // the field that actually reaches the order, which then passes a
            // check the visible form should have failed.
            this.setField('city', city ? city.name || '' : '');
            this.setField('postcode', $.trim(this.postcode()));
            this.setField('street', this.composedStreet(), 0);

            window.pigeonexpressCheckoutPayload = window.pigeonexpressCheckoutPayload || {};
            window.pigeonexpressCheckoutPayload.pigeonexpress_city_id = city ? String(city.id) : '';
            window.pigeonexpressCheckoutPayload.pigeonexpress_street_id =
                this.selectedStreet() ? String(this.selectedStreet().id) : '';
        },

        /**
         * Write into one of Magento's address components.
         */
        setField: function (name, value, index) {
            var path = FIELDSET + name + (typeof index === 'number' ? '.' + index : '');

            registry.get(path, function (cmp) {
                if (cmp && typeof cmp.value === 'function') {
                    cmp.value(value);
                }
            });
        }
    });
});
