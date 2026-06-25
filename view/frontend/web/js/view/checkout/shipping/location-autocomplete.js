/**
 * Pigeon Express: Office/APS location autocomplete. Local DB only; selection from results only (no free text).
 * Connection: DB → backend endpoint → this component → quote extension_attributes → order.
 */
define([
    'ko',
    'jquery',
    'mage/url',
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/model/shipping-rate-registry',
    'Magento_Checkout/js/model/shipping-rate-processor/new-address',
    'Magento_Checkout/js/action/create-shipping-address',
    'uiComponent',
    'mage/translate',
    'uiRegistry'
], function (ko, $, url, quote, rateRegistry, newAddressProcessor, createShippingAddressAction, Component, $t, registry) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'PigeonExpress_Shipping/checkout/shipping/location-autocomplete',
            carrierCode: 'pigeonexpress',
            officeMethod: 'office',
            apsMethod: 'aps',
            minChars: 2,
            searchDelay: 300
        },

        /** @type {string} */
        searchQuery: '',
        /** @type {Array} */
        results: [],
        /** @type {boolean} */
        isLoading: false,
        /** @type {Object|null} selected location { id, name, address, type } */
        selectedLocation: null,
        /** @type {boolean} */
        resultsVisible: false,
        /** @type {number} */
        _searchTimeout: 0,

        /**
         * @returns {void}
         */
        initialize: function () {
            this._super();
            this.uid = this.uid || ('pigeonexpress-location-' + Math.random().toString(36).substr(2, 9));
            this.searchQuery = ko.observable('');
            this.results = ko.observableArray([]);
            this.isLoading = ko.observable(false);
            this.selectedLocation = ko.observable(null);
            this.resultsVisible = ko.observable(false);
            window.pigeonexpressCheckoutPayload = window.pigeonexpressCheckoutPayload || {};

            var self = this;
            var previousMethodCode = null;
            quote.shippingMethod.subscribe(function (method) {
                var newCode = method ? (method.carrier_code + '_' + method.method_code) : null;
                if (newCode !== previousMethodCode) {
                    previousMethodCode = newCode;
                    self.selectedLocation(null);
                    self.searchQuery('');
                    self.results([]);
                    self.resultsVisible(false);
                    self.updateCheckoutPayload();
                }
            });

            return this;
        },

        /**
         * Visible when selected shipping method is pigeonexpress_office or pigeonexpress_aps.
         * @returns {boolean}
         */
        isVisible: function () {
            var method = quote.shippingMethod();
            if (!method || !method.carrier_code || method.carrier_code !== this.carrierCode) {
                return false;
            }
            var code = method.method_code || '';
            return code === this.officeMethod || code === this.apsMethod;
        },

        /**
         * Which type to search: office or aps (from current method).
         * @returns {string}
         */
        getSearchType: function () {
            var method = quote.shippingMethod();
            if (!method || method.method_code === this.apsMethod) {
                return this.apsMethod;
            }
            return this.officeMethod;
        },

        /**
         * Placeholder text by type.
         * @returns {string}
         */
        getPlaceholder: function () {
            return this.getSearchType() === 'aps'
                ? $t('Search APS (parcel locker)...')
                : $t('Search office...');
        },

        /**
         * Display string for selected location (name + address).
         * @returns {string}
         */
        displayValue: function () {
            var loc = this.selectedLocation();
            if (!loc) {
                return '';
            }
            return (loc.name || '') + (loc.address ? ', ' + loc.address : '');
        },

        /**
         * Trigger search (debounced). Only from backend results; no free text.
         */
        onSearchInput: function (data, event) {
            var self = this;
            var q = (event && event.target && event.target.value) ? event.target.value : this.searchQuery() || '';
            q = typeof q === 'string' ? q.trim() : '';
            this.searchQuery(q);
            if (this._searchTimeout) {
                clearTimeout(this._searchTimeout);
            }
            if (q.length < this.minChars) {
                this.results([]);
                this.resultsVisible(false);
                return;
            }
            this._searchTimeout = setTimeout(function () {
                self.doSearch(q);
            }, this.searchDelay);
        },

        /**
         * Fetch locations from backend (local DB only).
         * @param {string} q
         */
        doSearch: function (q) {
            var self = this;
            var type = this.getSearchType();
            this.isLoading(true);
            this.results([]);
            $.getJSON(url.build('pigeonexpress/checkout/locationAutocomplete'), {
                type: type,
                q: q
            }).done(function (data) {
                self.results(Array.isArray(data) ? data : []);
                self.resultsVisible(true);
            }).fail(function () {
                self.results([]);
            }).always(function () {
                self.isLoading(false);
            });
        },

        /**
         * Directly update city and postcode form field observables via uiRegistry.
         * This ensures the hidden fields carry the real location values through checkout.
         * @param {string} city
         * @param {string} postcode
         */
        updateAddressFields: function (city, postcode) {
            var fieldset = 'checkout.steps.shipping-step.shippingAddress.shipping-address-fieldset.';
            if (city) {
                registry.get(fieldset + 'city', function (cmp) {
                    if (cmp && typeof cmp.value === 'function') {
                        cmp.value(city);
                    }
                });
            }
            if (postcode) {
                registry.get(fieldset + 'postcode', function (cmp) {
                    if (cmp && typeof cmp.value === 'function') {
                        cmp.value(postcode);
                    }
                });
            }
        },

        /**
         * Update quote shipping address street (and optionally city/postcode) with location data.
         * This makes the address card in the address selector show the real location name
         * instead of the placeholder set by detachFromCustomerAddress.
         * @param {string} locationStr
         * @param {string} [city]
         * @param {string} [postcode]
         */
        updateQuoteAddressStreet: function (locationStr, city, postcode) {
            var currentAddr = quote.shippingAddress();
            if (!currentAddr) {
                return;
            }
            var defaultCountry = (window.checkoutConfig && window.checkoutConfig.defaultCountryId) ||
                currentAddr.countryId || 'UA';
            createShippingAddressAction({
                firstname: currentAddr.firstname || '',
                lastname: currentAddr.lastname || '',
                telephone: currentAddr.telephone || '',
                street: [locationStr],
                city: city || currentAddr.city || '',
                postcode: postcode || currentAddr.postcode || '',
                country_id: defaultCountry,
                region_id: currentAddr.regionId || null,
                region: currentAddr.region || null,
                region_code: currentAddr.regionCode || null,
                save_in_address_book: 0
            });
        },

        /**
         * Select a result. Only selection is allowed (no free text).
         * @param {Object} item { id, name, address, type }
         */
        selectLocation: function (item) {
            var self = this;
            this.selectedLocation(item);
            this.searchQuery(item.name + (item.address ? ', ' + item.address : ''));
            this.results([]);
            this.resultsVisible(false);
            console.log('[PE location-autocomplete] selectLocation', item);
            this.updateCheckoutPayload();
            // Persist selection to backend immediately so collectRates can see location_id.
            var payload = this.getPayload();
            $.post(
                url.build('pigeonexpress/checkout/setLocation'),
                payload
            ).done(function (response) {
                console.log('[PE setLocation] response', response);
                if (response && response.success && quote.shippingAddress()) {
                    var locationStr = item.name + (item.address ? ', ' + item.address : '');
                    var locCity = item.city || '';
                    var locPostcode = item.postcode || '';
                    console.log('[PE location-autocomplete] city/postcode from location', locCity, locPostcode);
                    self.updateAddressFields(locCity, locPostcode);
                    self.updateQuoteAddressStreet(locationStr, locCity, locPostcode);
                    rateRegistry.clear(quote.shippingAddress().getCacheKey());
                    newAddressProcessor.getRates(quote.shippingAddress());
                }
            }).fail(function (xhr) {
                console.warn('[PE setLocation] failed', xhr);
            });
        },

        /**
         * Clear selection.
         */
        clearSelection: function () {
            this.selectedLocation(null);
            this.searchQuery('');
            this.results([]);
            this.resultsVisible(false);
            console.log('[PE location-autocomplete] clearSelection');
            this.updateCheckoutPayload();
            this.updateQuoteAddressStreet('');
        },

        /**
         * Update global payload for shipping-save-processor mixin (quote → order).
         */
        updateCheckoutPayload: function () {
            window.pigeonexpressCheckoutPayload = this.getPayload();
            console.log('[PE location-autocomplete] updateCheckoutPayload', window.pigeonexpressCheckoutPayload);
        },

        /**
         * Hide results on outside click (handled in template with focusout if needed).
         */
        hideResults: function () {
            this.resultsVisible(false);
        },

        /**
         * Get payload for extension_attributes (used by payload mixin).
         * @returns {{delivery_type: string, location_id: string, location_name: string, location_address: string}}
         */
        getPayload: function () {
            var loc = this.selectedLocation();
            var method = quote.shippingMethod();
            var deliveryType = (method && method.method_code) ? method.method_code : '';
            return {
                delivery_type: deliveryType,
                location_id: loc ? String(loc.id) : '',
                location_name: loc ? (loc.name || '') : '',
                location_address: loc ? (loc.address || '') : '',
                location_city: loc ? (loc.city || '') : '',
                location_postcode: loc ? (loc.postcode || '') : ''
            };
        }
    });
});
