/**
 * Pigeon Express: city and street pickers for the pickup address in the carrier
 * configuration.
 *
 * Works as the checkout's address fields do. The city is searched in the local
 * table and choosing one sets its postal code; the street is searched in that
 * city through the API. The carrier ids sit in hidden fields beside them, and
 * editing a name by hand clears its id, so a saved id can never describe a
 * different place than the name shown.
 *
 * One instance runs per picker. The city instance also owns what belongs to
 * the address as a whole: the postal code lock, the shared "Use Default"
 * checkboxes and the check on save.
 *
 * Initialised through data-mage-init rather than an inline handler, so the admin
 * stays within Content Security Policy.
 */
define(['jquery', 'jquery/validate'], function ($) {
    'use strict';

    /** Long enough to stop firing per keystroke, short enough to feel live. */
    var DEBOUNCE_MS = 250,
        MIN_QUERY = 2,
        CHANGED = 'pe-pickup-address:changed';

    /**
     * The name with the typed part marked, built from text nodes so nothing
     * from the API is ever read as markup.
     */
    function highlighted(name, query) {
        var $out = $('<span class="pe-picker__name"></span>'),
            at = query ? name.toLowerCase().indexOf(query.toLowerCase()) : -1;

        if (at < 0) {
            return $out.text(name);
        }

        return $out.append(
            document.createTextNode(name.slice(0, at)),
            $('<mark></mark>').text(name.slice(at, at + query.length)),
            document.createTextNode(name.slice(at + query.length))
        );
    }

    return function (config, element) {
        var ids = config.ids,
            text = config.text,
            isCity = config.role === 'city',
            $wrap = $(element),
            $input = $wrap.find('input[type="text"]').first(),
            $status = $wrap.find('[data-role="pe-status"]'),
            $list = $('<ul class="pe-picker__list" role="listbox"></ul>'),
            $spinner = $('<span class="pe-picker__spinner" aria-hidden="true"></span>'),
            $clear = $('<button type="button" class="pe-picker__clear" tabindex="-1">&times;</button>'),
            timer,
            request,
            lastQuery = '',
            items = [],
            active = -1;

        if (!$input.length) {
            return;
        }

        function field(name) {
            return $(document.getElementById(ids[name]));
        }

        /**
         * Write a value the way a person would, so Magento's own listeners see
         * it. A field still inheriting is disabled and left alone; the shared
         * checkboxes below keep that from happening to only part of the address.
         */
        function set(name, value) {
            var $target = field(name);

            if ($target.length && !$target.prop('disabled')) {
                $target.val(value).trigger('change');
            }
        }

        function announce() {
            $(document).trigger(CHANGED);
        }

        // -- Markup ----------------------------------------------------------

        $wrap.addClass('pe-picker');
        $input.attr({
            autocomplete: 'off',
            placeholder: text.placeholder,
            role: 'combobox',
            'aria-autocomplete': 'list'
        }).addClass('pe-picker__input');
        $clear.attr({title: text.clear, 'aria-label': text.clear});
        $input.before('<span class="pe-picker__icon" aria-hidden="true"></span>')
            .after($list).after($clear).after($spinner);

        // -- Suggestion list -------------------------------------------------

        function open() {
            $list.addClass('_open');
            $wrap.addClass('_open');
        }

        function close() {
            $list.removeClass('_open').empty();
            $wrap.removeClass('_open');
            active = -1;
        }

        function busy(on) {
            $wrap.toggleClass('_busy', !!on);
        }

        function note(message, kind) {
            items = [];
            $list.empty().append(
                $('<li class="pe-picker__note"></li>').addClass(kind ? '_' + kind : '').text(message)
            );
            open();
        }

        function render() {
            $list.empty();

            if (!items.length) {
                note(text.noResults);
                return;
            }

            items.forEach(function (item, index) {
                var meta = isCity
                        ? [item.postcode, item.district].filter(Boolean).join(' · ')
                        : item.type || '',
                    $item = $('<li class="pe-picker__item" role="option"></li>')
                        .append(highlighted(item.name || item.label || '', lastQuery));

                // Bulgaria has several villages to a name, and the postal code
                // and district are what tell a merchant which Гълъбово is theirs.
                if (meta) {
                    $item.append($('<span class="pe-picker__meta"></span>').text(meta));
                }

                $item.on('mousedown', function (event) {
                    // mousedown, not click: blur would close the list first.
                    event.preventDefault();
                    choose(index);
                }).on('mouseenter', function () {
                    highlight(index - active);
                }).appendTo($list);
            });

            open();
        }

        function highlight(step) {
            var $options = $list.find('.pe-picker__item'),
                $current;

            if (!$options.length) {
                return;
            }

            active = (active + step + $options.length) % $options.length;
            $current = $options.removeClass('_active').eq(active).addClass('_active');

            // Keep the highlighted row in view while arrowing through a long list.
            if ($current[0] && $current[0].scrollIntoView) {
                $current[0].scrollIntoView({block: 'nearest'});
            }
        }

        function fetch(query) {
            var params = {q: query};

            if (!isCity) {
                params.city_id = $.trim(field('city_id').val());

                if (!params.city_id) {
                    note(text.chooseCityFirst, 'warn');
                    return;
                }
            }

            if (request) {
                request.abort();
            }

            lastQuery = query;
            busy(true);

            request = $.getJSON(config.searchUrl, params).done(function (result) {
                if (result && result.error) {
                    note(result.error, 'warn');
                    return;
                }
                items = (result && result.items) || [];
                active = -1;
                render();
            }).fail(function (xhr, status) {
                if (status === 'abort') {
                    return;
                }
                if (isCity) {
                    items = [];
                    render();
                } else {
                    note(text.streetsUnavailable, 'warn');
                }
            }).always(function (xhr, status) {
                if (status !== 'abort') {
                    busy(false);
                }
            });
        }

        // -- Choosing and editing --------------------------------------------

        function choose(index) {
            var item = items[index];

            if (!item) {
                return;
            }

            $input.val(item.name).trigger('change');

            if (isCity) {
                set('city_id', item.id);

                // The postal code is part of the city in the carrier's own data.
                // Written even when empty: a code left over from a different
                // town is worse than none, and an empty box is opened for typing.
                set('postal_code', item.postcode || '');

                // A street belongs to one city, so the previous choice is void.
                set('street_id', '');
                set('street_label', '');
            } else {
                set('street_id', item.id);
            }

            close();
            announce();
        }

        function search() {
            var query = $.trim($input.val());

            clearTimeout(timer);

            if (query.length < MIN_QUERY) {
                close();
                return;
            }

            timer = setTimeout(function () {
                fetch(query);
            }, DEBOUNCE_MS);
        }

        $input.on('input', function () {
            // Typed by hand, so we no longer know which place this is.
            set(isCity ? 'city_id' : 'street_id', '');
            announce();
            search();
        });

        // Reopen the list on returning to a name that was never chosen.
        $input.on('focus', function () {
            if (!$.trim(field(isCity ? 'city_id' : 'street_id').val())) {
                search();
            }
        });

        $input.on('keydown', function (event) {
            if (!$list.hasClass('_open')) {
                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    search();
                }
                return;
            }

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                highlight(1);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                highlight(-1);
            } else if (event.key === 'Enter') {
                // Enter would otherwise submit the whole configuration form.
                event.preventDefault();
                choose(active > -1 ? active : 0);
            } else if (event.key === 'Escape') {
                close();
            }
        });

        $input.on('blur', function () {
            setTimeout(close, 150);
        });

        $clear.on('mousedown', function (event) {
            event.preventDefault();

            if ($input.prop('disabled')) {
                return;
            }

            $input.val('').trigger('change');
            set(isCity ? 'city_id' : 'street_id', '');

            if (isCity) {
                set('postal_code', '');
                set('street_id', '');
                set('street_label', '');
            }

            close();
            announce();
            $input.trigger('focus');
        });

        // -- Status ----------------------------------------------------------

        /**
         * Say whether the name shown is tied to the carrier's list, which is
         * the one thing the merchant cannot otherwise see now the ids are hidden.
         */
        function refreshStatus() {
            var id = $.trim(field(isCity ? 'city_id' : 'street_id').val()),
                name = $.trim($input.val());

            $wrap.toggleClass('_filled', !!name)
                .toggleClass('_linked', !!(name && id))
                .toggleClass('_disabled', $input.prop('disabled'));

            $status.removeClass('_linked _warn').empty();

            if (name && id) {
                $status.addClass('_linked').text(text.linked.replace('%1', id));
            } else if (name) {
                $status.addClass('_warn').text(isCity ? text.cityNotLinked : text.streetNotLinked);
            }
        }

        $(document).on(CHANGED, refreshStatus);
        refreshStatus();

        if (!isCity) {
            return;
        }

        // -- Address as a whole (city instance only) -------------------------

        /**
         * The postal code comes with the city and is locked on the server. It is
         * only opened for a chosen city the carrier holds no postal code for, as
         * at checkout.
         */
        function refreshPostcode() {
            var $postcode = field('postal_code'),
                open = !!$.trim(field('city_id').val()) && !$.trim($postcode.val());

            $postcode.prop('readonly', !open)
                .attr('tabindex', open ? null : '-1')
                .toggleClass('pe-readonly', !open);
        }

        // Not while the merchant is typing in an opened box, or the first
        // character would shut it again.
        $(document).on(CHANGED, function () {
            if (!field('postal_code').is(':focus')) {
                refreshPostcode();
            }
        });
        refreshPostcode();

        /**
         * One "Use Default" for the whole address. Ticking or unticking any of
         * them does the same to the rest, through a real click so Magento's own
         * handler enables or disables each field as usual.
         */
        (function linkInheritance() {
            var syncing = false,
                boxes = $.map(ids, function (id) {
                    return document.getElementById(id + '_inherit');
                });

            $(boxes).on('change', function () {
                var checked = this.checked;

                if (syncing) {
                    return;
                }

                syncing = true;
                boxes.forEach(function (box) {
                    if (box.checked !== checked) {
                        box.click();
                    }
                });
                syncing = false;

                announce();
            });
        }());

        /**
         * Refuse to save a city that is not from the list. Without its id the
         * carrier has no origin to price or collect from, and every rate and
         * shipment would fail until somebody noticed. A disabled field, hidden by
         * Pickup Type or still inheriting, is not checked.
         */
        if ($.validator) {
            $.validator.addMethod('validate-pe-pickup-city', function (value) {
                return !!$.trim(value) && !!$.trim(field('city_id').val());
            }, text.cityRequired);

            $input.addClass('validate-pe-pickup-city');
        }
    };
});
