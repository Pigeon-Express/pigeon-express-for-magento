/**
 * Pigeon Express: a search box that picks a city, a street, an office or a
 * parcel locker and keeps its id in a hidden field beside it.
 *
 * Markup: an element with data-role="pe-search" holding a text input and a
 * hidden input. Options: url (JSON endpoint answering {items:[{id,name,label}]}),
 * dependsOn (selector of a hidden id sent as city_id, for streets), minLength.
 * Typing clears the id, so a saved id never describes another place than the
 * text shown. Results are built from text nodes only.
 */
define(['jquery'], function ($) {
    'use strict';

    var DEBOUNCE_MS = 250;

    return function (config, element) {
        var $wrap = $(element).addClass('pigeonexpress-suggest-wrap'),
            $text = $wrap.find('input[type="text"]').first(),
            $id = $wrap.find('input[type="hidden"]').first(),
            $list = $('<ul class="pigeonexpress-suggest" role="listbox"></ul>').hide().appendTo($wrap),
            minLength = config.minLength || 2,
            timer,
            request;

        function close() {
            $list.empty().hide();
        }

        function pick(item) {
            $text.val(item.name);
            $id.val(item.id).trigger('change');
            close();
        }

        function render(items) {
            $list.empty();
            if (!items.length) {
                $('<li class="pigeonexpress-suggest-note"></li>').text(config.noResults || '').appendTo($list);
            }
            $.each(items, function (i, item) {
                $('<li class="pigeonexpress-suggest-item" role="option"></li>')
                    .text(item.label || item.name)
                    .on('mousedown', function (event) {
                        event.preventDefault();
                        pick(item);
                    })
                    .appendTo($list);
            });
            $list.show();
        }

        function search() {
            var query = $.trim($text.val()),
                data = {q: query};

            if (query.length < minLength) {
                close();
                return;
            }
            if (config.dependsOn) {
                data.city_id = $(config.dependsOn).val();
                if (!data.city_id) {
                    close();
                    return;
                }
            }
            if (request) {
                request.abort();
            }
            request = $.ajax({url: config.url, type: 'GET', data: data, dataType: 'json'})
                .done(function (answer) {
                    render(answer && answer.items ? answer.items : []);
                });
        }

        $text.attr('autocomplete', 'off').on('input', function () {
            $id.val('').trigger('change');
            clearTimeout(timer);
            timer = setTimeout(search, DEBOUNCE_MS);
        }).on('blur', function () {
            setTimeout(close, 150);
        }).on('keydown', function (event) {
            if (event.key === 'Escape') {
                close();
            }
        });
    };
});
