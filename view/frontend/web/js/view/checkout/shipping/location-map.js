/**
 * Pigeon Express: "Choose on the map" for the checkout office and parcel
 * locker picker.
 *
 * Loaded by model/location-extras on the first click, with Leaflet (bundled
 * under js/lib/leaflet; the styles of both are on the checkout page, see
 * checkout_index_index.xml). A Magento modal with an OpenStreetMap
 * map, one pin per location that has a position, and a search over the
 * store's own list (city, name, address); nothing is geocoded. Choosing a pin
 * hands the row back to the picker, which saves it exactly as a pick from its
 * list would.
 *
 * Map tiles come from tile.openstreetmap.org (allowed in etc/csp_whitelist.xml),
 * with the attribution its tile usage policy asks for always visible on the map.
 */
define([
    'jquery',
    'pigeonexpress/leaflet',
    'PigeonExpress_Shipping/js/model/location-extras',
    'require',
    'Magento_Ui/js/modal/modal'
], function ($, L, extras, require) {
    'use strict';

    var TILES = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
        ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" ' +
            'rel="noopener noreferrer">OpenStreetMap</a> contributors',
        MAX_RESULTS = 30,
        BULGARIA = [[41.23, 22.36], [44.22, 28.61]],
        texts = {},
        ui = null,
        map = null,
        pins = null,
        here = null,
        current = null,
        searchTimer = null;

    L = L || window.L;

    // Explicit, so the pins never depend on where Leaflet guesses its images are.
    L.Icon.Default.imagePath = require.toUrl('PigeonExpress_Shipping/js/lib/leaflet/images/');

    function el(tag, className, attrs) {
        var node = document.createElement(tag),
            key;

        if (className) {
            node.className = className;
        }

        for (key in attrs || {}) {
            if (Object.prototype.hasOwnProperty.call(attrs, key)) {
                node.setAttribute(key, attrs[key]);
            }
        }

        return node;
    }

    function escapeHtml(text) {
        return $('<div/>').text(String(text || '')).html();
    }

    /** Where a location is. The address already names the town and postcode. */
    function metaOf(row) {
        return row.address || [row.city, row.postcode].filter(Boolean).join(', ');
    }

    function build(title) {
        var root = el('div', 'pe-map'),
            search = el('div', 'pe-map__search'),
            label = el('label', 'pe-map__label', { 'for': 'pe-map-input' }),
            input = el('input', 'input-text pe-map__input', {
                id: 'pe-map-input',
                type: 'search',
                autocomplete: 'off',
                role: 'combobox',
                'aria-autocomplete': 'list',
                'aria-expanded': 'false',
                'aria-controls': 'pe-map-results',
                placeholder: texts.mapSearch || ''
            }),
            results = el('ul', 'pe-map__results', { id: 'pe-map-results', role: 'listbox' }),
            empty = el('p', 'pe-map__empty', { role: 'status' }),
            box = el('div', 'pe-map__map');

        results.hidden = true;
        empty.hidden = true;
        label.textContent = texts.mapSearch || '';
        empty.textContent = texts.noResults || '';

        search.appendChild(label);
        search.appendChild(input);
        search.appendChild(results);
        search.appendChild(empty);
        root.appendChild(search);
        root.appendChild(box);
        document.body.appendChild(root);

        ui = {
            root: root,
            $root: $(root),
            input: input,
            results: results,
            empty: empty,
            box: box,
            matches: [],
            hover: -1
        };

        // Magento's own dialog: overlay, focus kept inside, Esc closes, the
        // focus goes back to the button that opened it.
        ui.$root.modal({
            type: 'popup',
            modalClass: 'pe-map-modal',
            title: escapeHtml(title),
            closeText: escapeHtml(texts.close || 'Close'),
            buttons: [],
            focus: '',
            clickableOverlay: true,
            opened: onOpened,
            closed: onClosed
        });

        input.addEventListener('input', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () {
                runSearch(input.value);
            }, 150);
        });
        input.addEventListener('keydown', onSearchKeydown);

        results.addEventListener('mousedown', function (e) {
            var item = e.target.closest ? e.target.closest('li[data-idx]') : null;

            if (!item) {
                return;
            }
            e.preventDefault();
            showRow(ui.matches[parseInt(item.getAttribute('data-idx'), 10)]);
        });

        map = L.map(box, { zoomControl: false, attributionControl: true });
        L.control.zoom({
            zoomInTitle: texts.zoomIn || 'Zoom in',
            zoomOutTitle: texts.zoomOut || 'Zoom out'
        }).addTo(map);
        // OpenStreetMap asks for a referrer; a store that sends none at all
        // (Referrer-Policy: no-referrer) would get its tiles refused.
        L.tileLayer(TILES, {
            maxZoom: 19,
            attribution: ATTRIBUTION,
            referrerPolicy: 'strict-origin-when-cross-origin'
        }).addTo(map);
        pins = L.featureGroup().addTo(map);

        // Whatever the shopper does on the map first wins over centring on
        // their position, which may arrive a few seconds later.
        map.on('dragstart', touch);
        box.addEventListener('wheel', touch, { passive: true });
        box.addEventListener('touchstart', touch, { passive: true });
    }

    function touch() {
        if (current) {
            current.touched = true;
        }
    }

    function popupFor(row) {
        var content = el('div', 'pe-map-popup'),
            name = el('strong', 'pe-map-popup__name'),
            meta = el('span', 'pe-map-popup__meta'),
            choose = el('button', 'pe-map-popup__choose', { type: 'button' }),
            hours;

        name.textContent = row.name || '';
        meta.textContent = metaOf(row);
        choose.textContent = texts.choose || 'Choose';
        choose.setAttribute('aria-label', (texts.choose || 'Choose') + ': ' + (row.name || ''));

        content.appendChild(name);
        content.appendChild(meta);

        if (row.hours) {
            hours = el('span', 'pe-map-popup__hours');
            hours.textContent = row.hours;
            content.appendChild(hours);
        }

        content.appendChild(choose);

        choose.addEventListener('click', function () {
            var onChoose = current ? current.onChoose : null,
                widget = ui.$root.data('mageModal');

            // The picker moves the focus itself once the choice is saved; the
            // dialog must not put it back on a button that is gone by then.
            if (widget) {
                widget.focussedElement = null;
            }

            closeMap();

            if (typeof onChoose === 'function') {
                onChoose(row);
            }
        });

        return content;
    }

    function placePins(rows) {
        pins.clearLayers();
        current.markers = {};
        current.rows = rows;

        rows.forEach(function (row) {
            var marker = L.marker([row.lat, row.lng], {
                title: row.name || '',
                alt: row.name || '',
                keyboard: true
            });

            marker.bindPopup(function () {
                return popupFor(row);
            }, { maxWidth: 260, autoPanPadding: [24, 24] });
            marker.on('click', touch);
            marker.addTo(pins);
            current.markers[String(row.id)] = marker;
        });
    }

    function showHere(position) {
        if (here) {
            map.removeLayer(here);
        }

        here = L.circleMarker([position.lat, position.lng], {
            radius: 8,
            color: '#ffffff',
            weight: 3,
            fillColor: '#006bb4',
            fillOpacity: 1,
            interactive: true,
            keyboard: false
        }).bindTooltip(texts.youAreHere || '', { direction: 'top' }).addTo(map);
    }

    function fitAll() {
        var bounds = pins.getLayers().length ? pins.getBounds() : L.latLngBounds(BULGARIA);

        map.fitBounds(bounds, { padding: [24, 24], maxZoom: 14 });
    }

    function initialView(selectedId, position) {
        var marker = selectedId ? current.markers[String(selectedId)] : null;

        if (marker) {
            map.setView(marker.getLatLng(), 15);
            marker.openPopup();

            return;
        }

        if (position) {
            map.setView([position.lat, position.lng], 13);

            return;
        }

        fitAll();
    }

    /**
     * @param {Object} opts See location-extras openMap(): texts, title,
     *                      locations (pinned rows only), selectedId,
     *                      onChoose, position, locate.
     */
    function open(opts) {
        var session;

        texts = opts.texts || {};

        if (!ui) {
            build(opts.title || '');
        }

        session = {
            onChoose: opts.onChoose,
            markers: {},
            rows: [],
            touched: false
        };

        current = session;
        ui.$root.modal('setTitle', opts.title || '');
        ui.input.value = '';
        hideResults();

        if (here) {
            map.removeLayer(here);
            here = null;
        }

        placePins(opts.locations || []);
        ui.$root.modal('openModal');

        // The box may have had no size while the dialog was closed.
        map.invalidateSize(false);
        initialView(opts.selectedId, opts.position);

        if (opts.position) {
            showHere(opts.position);
        }

        if (opts.locate && typeof opts.locate.then === 'function' && !opts.position) {
            opts.locate.then(function (position) {
                if (current !== session) {
                    return;
                }
                showHere(position);
                if (!session.touched && !opts.selectedId) {
                    map.setView([position.lat, position.lng], 13);
                }
            }, function () {});
        }

        setTimeout(function () {
            if (current === session) {
                map.invalidateSize(false);
            }
        }, 60);
    }

    /** The dialog has finished opening: final size, then the focus. */
    function onOpened() {
        var coarse;

        if (!current) {
            return;
        }

        map.invalidateSize(false);

        // The search field, except on touch screens, where focusing it would
        // open the keyboard over the map the shopper came to see: there the
        // dialog itself, so Esc and Tab still work inside it.
        coarse = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;

        if (!coarse) {
            ui.input.focus();
        } else {
            ui.$root.closest('[data-role="modal"]').trigger('focus');
        }
    }

    /** However the dialog was closed: Esc, the close button, the overlay or a choice. */
    function onClosed() {
        clearTimeout(searchTimer);

        if (map) {
            map.closePopup();
        }

        hideResults();
        current = null;
    }

    function closeMap() {
        if (ui) {
            ui.$root.modal('closeModal');
        }
    }

    function runSearch(query) {
        var q, tiers, matches, best, listHeight, bounds;

        if (!current) {
            return;
        }

        q = extras.normalize(String(query || '').trim());

        if (q === '') {
            hideResults();
            fitAll();

            return;
        }

        current.touched = true;

        // A town first: "Пловдив" means the offices in Plovdiv, then those with
        // the word in their name, then anything else that mentions it.
        tiers = [[], [], []];

        current.rows.forEach(function (row) {
            var city = extras.normalize(row.city);

            if (city.indexOf(q) === 0 || String(row.postcode || '').indexOf(q) === 0) {
                tiers[0].push(row);
            } else if (extras.normalize(row.name).indexOf(q) !== -1) {
                tiers[1].push(row);
            } else if (extras.normalize(row.address).indexOf(q) !== -1 || city.indexOf(q) !== -1) {
                tiers[2].push(row);
            }
        });

        matches = tiers[0].concat(tiers[1], tiers[2]);

        renderResults(matches);

        // The map shows the best tier, so a town is not zoomed out to the whole
        // country by one street named after it elsewhere.
        best = tiers[0].length ? tiers[0] : (tiers[1].length ? tiers[1] : tiers[2]);

        if (best.length) {
            // Clear of the result list, which lies over the top of the map.
            listHeight = ui.results.hidden ? 0 : ui.results.offsetHeight;
            bounds = L.latLngBounds(best.map(function (row) {
                return [row.lat, row.lng];
            }));
            map.fitBounds(bounds, {
                paddingTopLeft: [32, 32 + listHeight],
                paddingBottomRight: [32, 32],
                maxZoom: 15
            });
        }
    }

    function renderResults(matches) {
        ui.matches = matches.slice(0, MAX_RESULTS);
        ui.hover = ui.matches.length ? 0 : -1;
        ui.results.textContent = '';

        if (!ui.matches.length) {
            ui.results.hidden = true;
            ui.empty.hidden = false;
            ui.input.setAttribute('aria-expanded', 'false');

            return;
        }

        ui.empty.hidden = true;

        ui.matches.forEach(function (row, idx) {
            var item = el('li', 'pe-map__result', {
                    role: 'option',
                    id: 'pe-map-result-' + idx,
                    'data-idx': String(idx),
                    'aria-selected': idx === ui.hover ? 'true' : 'false'
                }),
                name = el('span', 'pe-map__result-name'),
                meta = el('span', 'pe-map__result-meta');

            name.textContent = row.name || '';
            meta.textContent = metaOf(row);
            item.appendChild(name);
            item.appendChild(meta);
            ui.results.appendChild(item);
        });

        ui.results.hidden = false;
        ui.input.setAttribute('aria-expanded', 'true');
        ui.input.setAttribute('aria-activedescendant', 'pe-map-result-0');
    }

    function hideResults() {
        if (!ui) {
            return;
        }

        ui.matches = [];
        ui.hover = -1;
        ui.results.textContent = '';
        ui.results.hidden = true;
        ui.empty.hidden = true;
        ui.input.setAttribute('aria-expanded', 'false');
        ui.input.removeAttribute('aria-activedescendant');
    }

    function moveHover(delta) {
        if (!ui.matches.length) {
            return;
        }

        ui.hover = (ui.hover + delta + ui.matches.length) % ui.matches.length;

        Array.prototype.forEach.call(ui.results.children, function (item, i) {
            item.setAttribute('aria-selected', i === ui.hover ? 'true' : 'false');
            if (i === ui.hover && item.scrollIntoView) {
                item.scrollIntoView({ block: 'nearest' });
            }
        });

        ui.input.setAttribute('aria-activedescendant', 'pe-map-result-' + ui.hover);
    }

    /** Bring one location into view with its popup open, focus on Choose. */
    function showRow(row) {
        var marker;

        if (!row || !current) {
            return;
        }

        marker = current.markers[String(row.id)];

        hideResults();

        if (!marker) {
            return;
        }

        current.touched = true;
        map.setView(marker.getLatLng(), Math.max(map.getZoom(), 16));
        marker.openPopup();

        setTimeout(function () {
            var button = ui.box.querySelector('.leaflet-popup .pe-map-popup__choose');

            if (button) {
                button.focus();
            }
        }, 0);
    }

    function onSearchKeydown(e) {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            moveHover(1);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            moveHover(-1);
        } else if (e.key === 'Enter') {
            // Never a form's submit.
            e.preventDefault();
            clearTimeout(searchTimer);
            if (ui.hover >= 0 && ui.matches[ui.hover]) {
                showRow(ui.matches[ui.hover]);
            } else {
                runSearch(ui.input.value);
            }
        } else if ((e.key === 'Escape' || e.key === 'Esc') && !ui.results.hidden) {
            // The first Esc closes the list, the next one the map.
            e.preventDefault();
            e.stopPropagation();
            hideResults();
        }
    }

    return {
        open: open,
        close: closeMap
    };
});
