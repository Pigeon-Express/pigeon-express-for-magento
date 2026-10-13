/**
 * Pigeon Express: searchable picker for the pickup office.
 *
 * Looks and behaves like the pickup address pickers. The offices travel with
 * the page, so filtering is instant and needs no request. Every word typed has
 * to appear in the name or the address, so "пловдив ботев" finds the Христо
 * Ботев office in Пловдив.
 *
 * The native select stays in the page, hidden, as the field that is saved. The
 * picker only reads and writes its value, and follows its disabled state, so
 * "Use Default" and the Pickup Type dependency work on it exactly as before.
 *
 * Initialised through data-mage-init rather than an inline handler, so the admin
 * stays within Content Security Policy.
 */
define(['jquery'], function ($) {
    'use strict';

    /** Enough to scroll through comfortably without building a huge list. */
    var MAX_RESULTS = 60;

    function words(value) {
        return $.trim(String(value || '').toLowerCase()).split(/\s+/).filter(Boolean);
    }

    /**
     * Text with every typed word marked, built from text nodes so nothing from
     * the office list is ever read as markup.
     */
    function highlighted(textValue, terms, className) {
        var $out = $('<span></span>').addClass(className),
            lower = textValue.toLowerCase(),
            marks = [],
            pos = 0;

        terms.forEach(function (term) {
            var at = lower.indexOf(term);

            if (at > -1) {
                marks.push([at, at + term.length]);
            }
        });

        marks.sort(function (a, b) {
            return a[0] - b[0];
        }).forEach(function (mark) {
            if (mark[0] < pos) {
                return;
            }
            $out.append(document.createTextNode(textValue.slice(pos, mark[0])));
            $out.append($('<mark></mark>').text(textValue.slice(mark[0], mark[1])));
            pos = mark[1];
        });

        return $out.append(document.createTextNode(textValue.slice(pos)));
    }

    return function (config, element) {
        var text = config.text,
            offices = config.offices || [],
            $wrap = $(element),
            $select = $(document.getElementById(config.selectId)),
            $input = $wrap.find('[data-role="pe-office-search"]'),
            $status = $wrap.find('[data-role="pe-status"]'),
            $list = $('<ul class="pe-picker__list" role="listbox"></ul>'),
            $clear = $('<button type="button" class="pe-picker__clear" tabindex="-1">&times;</button>'),
            byId = {},
            items = [],
            active = -1,
            typing = false;

        if (!$select.length || !$input.length) {
            return;
        }

        offices.forEach(function (office) {
            office.haystack = (office.name + ' ' + office.address).toLowerCase();
            byId[office.id] = office;
        });

        // -- Markup ----------------------------------------------------------

        $input.attr({
            autocomplete: 'off',
            placeholder: text.placeholder,
            role: 'combobox',
            'aria-autocomplete': 'list'
        });
        $clear.attr({title: text.clear, 'aria-label': text.clear});
        $input.before('<span class="pe-picker__icon" aria-hidden="true"></span>')
            .after($list).after($clear);

        // -- Showing the saved value -----------------------------------------

        function current() {
            return byId[$select.val()] || null;
        }

        /**
         * Bring the visible box in line with the select: its name, whether it
         * may be edited, and the badge underneath.
         */
        function sync() {
            var office = current(),
                value = $select.val(),
                disabled = $select.prop('disabled');

            if (!typing) {
                $input.val(office ? office.name : '');
            }

            $input.prop('disabled', disabled);
            $wrap.toggleClass('_disabled', disabled)
                .toggleClass('_filled', !!$.trim($input.val()))
                .toggleClass('_linked', !!office && !typing);

            $status.removeClass('_linked _warn').empty();

            if (office) {
                $status.addClass('_linked').text(
                    text.linked.replace('%1', office.id) + (office.address ? ' · ' + office.address : '')
                );
            } else if (value) {
                // Saved before, gone from the synced list since.
                $status.addClass('_warn').text(text.missing.replace('%1', value));
            }
        }

        function pick(office) {
            typing = false;
            $select.val(office ? office.id : '').trigger('change');
            close();
            sync();
        }

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

        function note(message) {
            items = [];
            $list.empty().append($('<li class="pe-picker__note"></li>').text(message));
            open();
        }

        function search(query) {
            var terms = words(query),
                selected = $select.val();

            if (!offices.length) {
                note(text.noOffices);
                return;
            }

            items = offices.filter(function (office) {
                return terms.every(function (term) {
                    return office.haystack.indexOf(term) > -1;
                });
            }).slice(0, MAX_RESULTS);

            $list.empty();
            active = -1;

            if (!items.length) {
                note(text.noResults);
                return;
            }

            items.forEach(function (office, index) {
                $('<li class="pe-picker__item pe-picker__item--stacked" role="option"></li>')
                    .toggleClass('_selected', office.id === selected)
                    .append(highlighted(office.name, terms, 'pe-picker__name'))
                    .append(highlighted(office.address, terms, 'pe-picker__meta'))
                    .on('mousedown', function (event) {
                        // mousedown, not click: blur would close the list first.
                        event.preventDefault();
                        pick(office);
                    })
                    .on('mouseenter', function () {
                        highlight(index - active);
                    })
                    .appendTo($list);
            });

            open();

            // Start on the saved office, so arrowing on from it is natural.
            items.some(function (office, index) {
                if (office.id === selected) {
                    highlight(index + 1);
                    return true;
                }
                return false;
            });
        }

        function highlight(step) {
            var $options = $list.find('.pe-picker__item'),
                $current;

            if (!$options.length) {
                return;
            }

            active = (active + step + $options.length) % $options.length;
            $current = $options.removeClass('_active').eq(active).addClass('_active');

            if ($current[0] && $current[0].scrollIntoView) {
                $current[0].scrollIntoView({block: 'nearest'});
            }
        }

        // -- Events ----------------------------------------------------------

        $input.on('focus', function () {
            // The whole list on focus: with a couple of hundred offices,
            // browsing is as useful as searching.
            $input.trigger('select');
            search(typing ? $input.val() : '');
        });

        $input.on('input', function () {
            typing = true;
            $wrap.toggleClass('_filled', !!$.trim($input.val())).removeClass('_linked');
            search($input.val());
        });

        $input.on('keydown', function (event) {
            if (event.key === 'Enter') {
                // Enter would otherwise submit the whole configuration form.
                event.preventDefault();

                if (items.length) {
                    pick(items[active > -1 ? active : 0]);
                }
                return;
            }

            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();

                if (!$list.hasClass('_open')) {
                    search(typing ? $input.val() : '');
                }
                highlight(event.key === 'ArrowDown' ? 1 : -1);
            } else if (event.key === 'Escape') {
                typing = false;
                close();
                sync();
            }
        });

        // Leaving without choosing puts back the saved office, so the box never
        // shows a half-typed search as if it were the setting.
        $input.on('blur', function () {
            setTimeout(function () {
                typing = false;
                close();
                sync();
            }, 150);
        });

        $clear.on('mousedown', function (event) {
            event.preventDefault();

            if (!$select.prop('disabled')) {
                pick(null);
                $input.trigger('focus');
            }
        });

        // "Use Default" and the Pickup Type dependency enable and disable the
        // select directly, without an event, so watch the attribute.
        if (window.MutationObserver) {
            new MutationObserver(sync).observe($select[0], {attributes: true, attributeFilter: ['disabled']});
        }
        $select.on('change', function () {
            if (!typing) {
                sync();
            }
        });

        sync();
    };
});
