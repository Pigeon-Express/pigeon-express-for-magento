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
        /** @type {number} */
        _cityTimeout: 0,

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

            // The city comes first. Sofia alone has 43 offices and 84 lockers,
            // so searching the whole country in one box either buries the right
            // answer or hides it behind a result limit.
            this.cityQuery = ko.observable('');
            this.cityResults = ko.observableArray([]);
            this.cityResultsVisible = ko.observable(false);
            this.selectedCity = ko.observable(null);
            this.locations = ko.observableArray([]);
            this.locationFilter = ko.observable('');

            // A city can hold 84 lockers, which is too many to scroll blindly,
            // so the list stays filterable. Every one of them is still in it.
            this.filteredLocations = ko.computed(function () {
                var needle = (this.locationFilter() || '').toLowerCase().trim(),
                    all = this.locations();

                if (!needle) {
                    return all;
                }

                return all.filter(function (location) {
                    return ((location.name || '') + ' ' + (location.address || ''))
                        .toLowerCase().indexOf(needle) !== -1;
                });
            }, this);

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
                    // Offices and lockers are different lists, so a city chosen
                    // under one method cannot carry over to the other.
                    self.clearCity();
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
         * Search the city list for whichever pickup type is selected.
         */
        onCityInput: function (data, event) {
            var self = this,
                q = (event && event.target && event.target.value) || '';

            q = typeof q === 'string' ? q.trim() : '';
            this.cityQuery(q);

            if (this._cityTimeout) {
                clearTimeout(this._cityTimeout);
            }

            if (q.length < this.minChars) {
                this.cityResults([]);
                this.cityResultsVisible(false);
                return;
            }

            this._cityTimeout = setTimeout(function () {
                $.getJSON(url.build('pigeonexpress/checkout/citySearch'), {
                    type: self.getSearchType(),
                    q: q
                }).done(function (data) {
                    self.cityResults((data && data.items) || []);
                    self.cityResultsVisible(true);
                }).fail(function () {
                    self.cityResults([]);
                });
            }, this.searchDelay);
        },

        /**
         * Take a city and load everything in it.
         */
        selectCity: function (city) {
            var self = this;

            this.selectedCity(city);
            this.cityQuery(city.name || '');
            this.cityResults([]);
            this.cityResultsVisible(false);
            this.locationFilter('');
            this.selectedLocation(null);
            this.locations([]);
            this.isLoading(true);

            $.getJSON(url.build('pigeonexpress/checkout/locationAutocomplete'), {
                type: this.getSearchType(),
                city_id: city.id
            }).done(function (data) {
                self.locations(Array.isArray(data) ? data : []);
            }).fail(function () {
                self.locations([]);
            }).always(function () {
                self.isLoading(false);
            });
        },

        /**
         * Back to choosing a city, which invalidates whatever was picked in it.
         */
        clearCity: function () {
            this.selectedCity(null);
            this.cityQuery('');
            this.cityResults([]);
            this.cityResultsVisible(false);
            this.locations([]);
            this.locationFilter('');
        },

        /**
         * The chosen pickup point, in two parts: what it is called and where it
         * is. Both read through here rather than off the object in the template,
         * so that clearing the selection cannot momentarily be read off null:
         * Knockout can re-evaluate a descendant binding before the `if` around
         * it has taken the element away.
         */
        selectedName: function () {
            var location = this.selectedLocation();

            return location ? location.name || '' : '';
        },

        selectedMeta: function () {
            var location = this.selectedLocation();

            return location ? location.address || '' : '';
        },

        /**
         * The label on the second field, which is not a generic one: an office
         * and a parcel locker are different things to be sent to.
         */
        locationTypeLabel: function () {
            return this.getSearchType() === 'aps'
                ? $t('Parcel locker')
                : $t('Pigeon Express office');
        },

        /**
         * How many pickup points a city holds, shown against its name.
         */
        cityCountLabel: function (city) {
            var count = city && city.count ? Number(city.count) : 0;

            if (!count) {
                return '';
            }

            return this.getSearchType() === 'aps'
                ? $t('%1 parcel lockers').replace('%1', count)
                : $t('%1 offices').replace('%1', count);
        },

        /**
         * How much of the list is on screen. The list is never truncated, so
         * this says how many there are rather than how many were kept.
         */
        resultCountLabel: function () {
            var shown = this.filteredLocations().length,
                all = this.locations().length;

            if (shown === all) {
                return $t('%1 to choose from').replace('%1', all);
            }

            return $t('%1 of %2 match').replace('%1', shown).replace('%2', all);
        },

        /**
         * Placeholder for the box that filters the list, by type.
         * @returns {string}
         */
        getPlaceholder: function () {
            return this.getSearchType() === 'aps'
                ? $t('Filter the parcel lockers')
                : $t('Filter the offices');
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
        /**
         * The chosen counter, written the way it should read on the order.
         *
         * The type is said out loud because the address of a parcel locker is
         * the address of the supermarket it stands outside, and nothing else on
         * the order would say the parcel is not being carried to a door.
         */
        locationStreetLine: function (location) {
            if (!location) {
                return '';
            }

            var type = location.type === this.apsMethod ? $t('Parcel locker') : $t('Office'),
                parts = [];

            if (location.name) {
                parts.push(location.name);
            }

            if (location.address) {
                parts.push(location.address);
            }

            return '(' + type + ') ' + parts.join(', ');
        },

        /**
         * Put the chosen counter into Magento's own address fields.
         *
         * Written rather than left alone, because the customer may have typed a
         * street address before switching to collection, and the fields are the
         * address of record: whatever is in them is what reaches the order, the
         * invoice and the confirmation email. Leaving them would send a parcel
         * to a counter under a heading giving the customer's own front door.
         */
        applyLocationToAddress: function (location) {
            var fieldset = 'checkout.steps.shipping-step.shippingAddress.shipping-address-fieldset.',
                values = {
                    'street.0': this.locationStreetLine(location),
                    'street.1': '',
                    'city': location.city || '',
                    'postcode': location.postcode || '',
                    'region_id': location.region_id || ''
                };

            Object.keys(values).forEach(function (name) {
                registry.get(fieldset + name, function (cmp) {
                    if (cmp && typeof cmp.value === 'function') {
                        cmp.value(values[name]);
                    }
                });
            });
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
            this.updateCheckoutPayload();
            // Persist selection to backend immediately so collectRates can see location_id.
            var payload = this.getPayload();
            $.post(
                url.build('pigeonexpress/checkout/setLocation'),
                payload
            ).done(function (response) {
                if (response && response.success && quote.shippingAddress()) {
                    var locationStr = self.locationStreetLine(item);
                    var locCity = item.city || '';
                    var locPostcode = item.postcode || '';
                    self.applyLocationToAddress(item);
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
            this.updateCheckoutPayload();
            this.updateQuoteAddressStreet('');
        },

        /**
         * Update global payload for shipping-save-processor mixin (quote → order).
         */
        updateCheckoutPayload: function () {
            window.pigeonexpressCheckoutPayload = this.getPayload();
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
