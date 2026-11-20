/**
 * Pigeon Express: the nearest offices or lockers and the map, for the checkout
 * office and parcel locker picker (view/checkout/shipping/location-autocomplete).
 *
 * Used only while "Nearest office" or "Map of offices and lockers" is on; the
 * picker hands its component config over with configure().
 *
 * Location: asked of the browser at most once per page, never again in the
 * same browser session after the shopper declines, and only on HTTPS. The
 * point is rounded to about 100 m before it leaves the browser and is sent
 * only to the store's own server, which asks Pigeon Express for the nearest
 * locations. It is kept in this page's memory and nowhere else.
 *
 * Map: Leaflet (bundled with the module) and the map script are loaded on the
 * first "Choose on the map", not with the page.
 */
define([
    'jquery',
    'mage/url',
    'require'
], function ($, url, require) {
    'use strict';

    var DENIED_KEY = 'pigeonExpressLocationDenied',
        RETRY_AFTER = 30000,
        cfg = { nearest: false, map: false, texts: {} },
        positionPromise = null,
        lastPosition = null,
        nearestCache = {},
        nearestFailedAt = {},
        mapRowsCache = {},
        mapPromise = null,

        /* Lowercase + simple Cyrillic to Latin transliteration, so "plovdiv"
         * finds "Пловдив". */
        CYR2LAT = {
            'а': 'a', 'б': 'b', 'в': 'v', 'г': 'g', 'д': 'd', 'е': 'e', 'ж': 'zh', 'з': 'z', 'и': 'i', 'й': 'i',
            'к': 'k', 'л': 'l', 'м': 'm', 'н': 'n', 'о': 'o', 'п': 'p', 'р': 'r', 'с': 's', 'т': 't', 'у': 'u',
            'ф': 'f', 'х': 'h', 'ц': 'ts', 'ч': 'ch', 'ш': 'sh', 'щ': 'sht', 'ъ': 'a', 'ь': '', 'ю': 'yu', 'я': 'ya'
        };

    function storageGet(key) {
        try {
            return window.sessionStorage ? window.sessionStorage.getItem(key) : null;
        } catch (e) {
            return null;
        }
    }

    function storageSet(key, value) {
        try {
            if (window.sessionStorage) {
                window.sessionStorage.setItem(key, value);
            }
        } catch (e) {
            // Private mode or storage switched off: the refusal is then only
            // remembered for this page, through positionPromise.
        }
    }

    function round3(value) {
        return Math.round(Number(value) * 1000) / 1000;
    }

    function canLocate() {
        return !!cfg.nearest &&
            window.location.protocol === 'https:' &&
            window.isSecureContext !== false &&
            !!window.navigator &&
            !!window.navigator.geolocation &&
            storageGet(DENIED_KEY) !== '1';
    }

    /**
     * The shopper's position, rounded. Asks the browser once per page; a
     * refusal is remembered for the browser session.
     *
     * @return {Promise<{lat: number, lng: number}>}
     */
    function locate() {
        if (positionPromise) {
            return positionPromise;
        }

        if (!canLocate()) {
            return Promise.reject(new Error('unavailable'));
        }

        positionPromise = new Promise(function (resolve, reject) {
            window.navigator.geolocation.getCurrentPosition(function (position) {
                lastPosition = {
                    lat: round3(position.coords.latitude),
                    lng: round3(position.coords.longitude)
                };
                resolve(lastPosition);
            }, function (error) {
                // 1 = PERMISSION_DENIED, which is also what a dismissed prompt
                // reports in most browsers.
                if (error && error.code === 1) {
                    storageSet(DENIED_KEY, '1');
                }
                reject(error || new Error('failed'));
            }, {
                enableHighAccuracy: false,
                timeout: 15000,
                maximumAge: 600000
            });
        });

        // Nobody may be listening yet (the map opens later): no unhandled
        // rejection in the console for a shopper who said no.
        positionPromise.catch(function () {});

        return positionPromise;
    }

    /** The page's form key: the server checks it on every nearest request. */
    function formKey() {
        return (window.checkoutConfig && window.checkoutConfig.formKey) ||
            $('input[name="form_key"]').first().val() || '';
    }

    /** The position already known on this page, or null. Never asks. */
    function knownPosition() {
        return lastPosition;
    }

    /**
     * The nearest locations of a type: picker rows with a `distance` text,
     * nearest first. Resolves to an empty list whenever there is nothing to
     * show (setting off, location declined or unavailable, server busy or
     * carrier error), so the picker simply shows its usual fields. One
     * request per type per page.
     *
     * @param {string} type 'office' or 'aps'.
     * @return {Promise<Array>}
     */
    function nearest(type) {
        if (!cfg.nearest || !cfg.nearestUrl) {
            return Promise.resolve([]);
        }

        if (nearestCache[type]) {
            return nearestCache[type];
        }

        // After a failed request (busy server, network blip) the picker shows
        // its usual fields for a while, then asks again on its next draw.
        if (nearestFailedAt[type] && Date.now() - nearestFailedAt[type] < RETRY_AFTER) {
            return Promise.resolve([]);
        }

        function failed() {
            nearestFailedAt[type] = Date.now();
            delete nearestCache[type];

            return [];
        }

        nearestCache[type] = locate().then(function (position) {
            // A refusal or an unavailable position ends in the catch below,
            // and stays cached for the page.
            return Promise.resolve($.ajax({
                url: url.build(cfg.nearestUrl),
                type: 'POST',
                dataType: 'json',
                global: false,
                data: {
                    'form_key': formKey(),
                    type: type,
                    lat: position.lat,
                    lng: position.lng
                }
            })).then(function (json) {
                if (json && json.success && Array.isArray(json.locations)) {
                    return json.locations;
                }

                return failed();
            }, failed);
        }).catch(function () {
            return [];
        });

        return nearestCache[type];
    }

    function hasPosition(row) {
        return row && typeof row.lat === 'number' && typeof row.lng === 'number' &&
            isFinite(row.lat) && isFinite(row.lng);
    }

    /** The rows of a list that can go on the map. */
    function pinned(rows) {
        return Array.isArray(rows) ? rows.filter(hasPosition) : [];
    }

    /**
     * Every location of a type that has a position, for the map. Asked once
     * per type per page; a failed request is asked again next time.
     *
     * @param {string} type 'office' or 'aps'.
     * @return {Promise<Array>}
     */
    function mapLocations(type) {
        if (!cfg.map || !cfg.mapLocationsUrl) {
            return Promise.resolve([]);
        }

        if (!mapRowsCache[type]) {
            mapRowsCache[type] = Promise.resolve($.ajax({
                url: url.build(cfg.mapLocationsUrl),
                type: 'GET',
                dataType: 'json',
                global: false,
                data: { type: type }
            })).then(function (json) {
                return json && json.success ? pinned(json.locations) : [];
            }, function () {
                delete mapRowsCache[type];

                return [];
            });
        }

        return mapRowsCache[type];
    }

    function loadModule(name) {
        return new Promise(function (resolve, reject) {
            require([name], resolve, function (error) {
                // Let a later click try again.
                if (error && error.requireModules) {
                    error.requireModules.forEach(function (id) {
                        require.undef(id);
                    });
                }
                reject(error);
            });
        });
    }

    /**
     * Leaflet and the map script, once. Files of the store; their styles are
     * on the checkout page already (checkout_index_index.xml).
     */
    function loadMap() {
        if (!cfg.map) {
            return Promise.reject(new Error('off'));
        }

        if (!mapPromise) {
            mapPromise = loadModule('PigeonExpress_Shipping/js/view/checkout/shipping/location-map');

            // A failed load may be a passing network problem: try again on the
            // next click.
            mapPromise.catch(function () {
                mapPromise = null;
            });
        }

        return mapPromise;
    }

    /**
     * Open the map.
     *
     * @param {Object}   opts
     * @param {string}   opts.type       'office' or 'aps'.
     * @param {Array}    opts.locations  Rows with a position (mapLocations()).
     * @param {string}   opts.selectedId The location chosen now, if any.
     * @param {Function} opts.onChoose   Called with the row the shopper chose; the map is closed by then.
     * @return {Promise} Settles once the map is open, or failed to load.
     */
    function openMap(opts) {
        return loadMap().then(function (map) {
            map.open({
                texts: cfg.texts || {},
                type: opts.type,
                locations: pinned(opts.locations),
                selectedId: opts.selectedId,
                onChoose: opts.onChoose,
                title: opts.type === 'aps' ? cfg.texts.mapTitleAps : cfg.texts.mapTitleOffice,
                // Centred on the shopper when they share their location (only
                // with "Nearest office" on). The map does not wait for it.
                position: knownPosition(),
                locate: cfg.nearest ? locate() : null
            });
        });
    }

    function normalize(text) {
        var lower, out = '', i, ch;

        if (!text) {
            return '';
        }

        lower = String(text).toLowerCase();

        for (i = 0; i < lower.length; i++) {
            ch = lower.charAt(i);
            out += CYR2LAT[ch] !== undefined ? CYR2LAT[ch] : ch;
        }

        return out;
    }

    return {
        /**
         * @param {Object} config The picker's component config: nearest, map,
         *                        nearestUrl, mapLocationsUrl, texts.
         */
        configure: function (config) {
            cfg = {
                nearest: !!(config && config.nearest),
                map: !!(config && config.map),
                nearestUrl: (config && config.nearestUrl) || '',
                mapLocationsUrl: (config && config.mapLocationsUrl) || '',
                texts: (config && config.texts) || {}
            };
        },
        locate: locate,
        knownPosition: knownPosition,
        nearest: nearest,
        mapLocations: mapLocations,
        pinned: pinned,
        openMap: openMap,
        normalize: normalize
    };
});
