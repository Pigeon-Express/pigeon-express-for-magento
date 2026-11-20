/**
 * Pigeon Express: city and street pickers for the shipment form.
 *
 * Typing a city or street by hand is what causes parcels to go missing. The
 * carrier matches recipients by phone, and when it cannot resolve the address it
 * was given it silently uses one it already holds for that number. Choosing from
 * these lists attaches the carrier's own identifier, which removes the guesswork.
 *
 * Cities come from the local table and are instant. Streets come from the API
 * for the chosen city, so the city has to be picked first.
 *
 * Initialised through data-mage-init rather than an inline handler, so the admin
 * stays within Content Security Policy.
 */
define(['jquery', 'mage/translate'], function ($, $t) {
    'use strict';

    /** Long enough to stop firing per keystroke, short enough to feel live. */
    var DEBOUNCE_MS = 250,
        MIN_QUERY = 2;

    return function (config, element) {
        var $form = $(element),
            $city = $form.find('[data-role="pe-city"]'),
            $street = $form.find('[data-role="pe-street"]'),
            $location = $form.find('[data-role="pe-location"]'),
            $type = $form.find('[data-role="pe-delivery-type"]'),
            $cityId = $form.find('#pe_city_id'),
            $streetId = $form.find('#pe_street_id'),
            $locationId = $form.find('#pe_location_id'),
            $postcode = $form.find('#pe_postal_code');

        /**
         * Show only the fields the chosen delivery type needs.
         *
         * A parcel bound for a counter has no use for a street, and leaving the
         * boxes on screen invites somebody to fill them in and wonder why the
         * courier ignored them.
         */
        function applyDeliveryType() {
            var type = $type.val(),
                wanted = type === 'address' ? 'address' : 'location';

            // The attribute drives the CSS, so the right half of the form is
            // already hidden when the page paints rather than blinking away once
            // this runs.
            $form.attr('data-pe-type', type);

            $form.find('[data-pe-group]').each(function () {
                var $group = $(this),
                    matches = $group.attr('data-pe-group') === wanted;

                // A disabled field is not submitted, which is what keeps a street
                // left over from an earlier choice out of an office waybill.
                $group.find('input, textarea').prop('disabled', !matches);
            });

            $location.closest('.admin__field').find('.admin__field-label span')
                .text(type === 'aps' ? $t(config.apsLabel || 'Parcel locker')
                    : $t(config.officeLabel || 'Pigeon Express office'));

            // Inspection and return receipts need somebody to hand the parcel
            // over. Hidden by the CSS for a locker, and disabled so that a
            // choice made for a door is not sent with a locker waybill.
            $form.find('[data-pe-handover] select').prop('disabled', type === 'aps');
            // Pigeon Express refuses a declared value for a locker: hidden by
            // the CSS and disabled, so it is not sent with a locker waybill.
            $form.find('[data-pe-not-locker]').find('input, select').prop('disabled', type === 'aps');
        }

        // A message describes the value that was checked, so the moment somebody
        // edits that value the message is describing something that no longer
        // exists. Clearing it as they type keeps the form honest; the server
        // checks everything again on submit, so nothing is lost by it.
        $form.on(
            'input change',
            '.admin__control-text, .admin__control-textarea, .admin__control-select',
            function () {
                $(this).closest('.admin__field')
                    .removeClass('_error _warn')
                    .find('.pe-message').remove();
            }
        );

        // The cash on delivery value depends on who pays the carrier: when the
        // recipient does, the courier collects the delivery charge itself, so
        // it is left out of the amount. Follows the choice until the admin
        // types an amount of their own.
        $form.find('[data-role="pe-cod-amount"]').each(function () {
            var $cod = $(this),
                edited = false;

            $cod.on('input', function () {
                edited = true;
            });

            $form.find('#pe_who_pays').on('change', function () {
                var amount = $(this).val() === 'receiver'
                    ? $cod.attr('data-cod-receiver')
                    : $cod.attr('data-cod-sender');

                if (!edited && amount !== undefined) {
                    $cod.val(amount);
                }
            });
        });

        // -- Package rows ---------------------------------------------------

        var $packages = $form.find('[data-role="pe-packages"]'),
            $rows = $packages.find('[data-role="pe-package-rows"]'),
            $template = $packages.find('[data-role="pe-package-template"]'),
            $packageMessage = $packages.find('[data-role="pe-package-message"]'),
            $handIn = $form.find('[data-role="pe-handin-text"]'),
            handInTimer,
            handInRequest;

        /**
         * Give the rows consecutive indexes, so the server reads them in the
         * order they are shown, and keep the last row from being removed: a
         * shipment without a package is not one.
         */
        function renumberPackages() {
            var $all = $rows.find('[data-role="pe-package-row"]');

            $all.each(function (index) {
                $(this).find('input[name]').each(function () {
                    this.name = this.name.replace(/pe\[packages\]\[[^\]]*\]/, 'pe[packages][' + index + ']');
                });
            });

            $all.find('[data-role="pe-package-remove"]').prop('disabled', $all.length < 2);
        }

        /**
         * Ask again where the shipment is handed in. Debounced, and only the
         * last answer counts, so typing a weight does not flicker the line.
         */
        function refreshHandIn() {
            if (!config.handInUrl || !$handIn.length) {
                return;
            }

            clearTimeout(handInTimer);
            $handIn.addClass('_stale');

            handInTimer = setTimeout(function () {
                if (handInRequest) {
                    handInRequest.abort();
                }

                handInRequest = $.post(config.handInUrl, $form.serialize())
                    .done(function (response) {
                        if (response && typeof response.hand_in === 'string' && response.hand_in !== '') {
                            $handIn.text(response.hand_in);
                            $handIn.removeClass('_stale');
                        }
                    });
            }, 500);
        }

        function packagesChanged() {
            $packages.removeClass('_error');
            $packageMessage.prop('hidden', true).text('');
            refreshHandIn();
        }

        $packages.on('click', '[data-role="pe-package-add"]', function () {
            var html = $template.html(),
                $row;

            if (!html) {
                return;
            }

            $row = $($.parseHTML($.trim(html.replace(/__INDEX__/g, String($rows.children().length)))));
            $rows.append($row);
            renumberPackages();
            $row.find('.pe-package-weight').trigger('focus');
            packagesChanged();
        });

        $packages.on('click', '[data-role="pe-package-remove"]', function () {
            if ($rows.find('[data-role="pe-package-row"]').length < 2) {
                return;
            }

            $(this).closest('[data-role="pe-package-row"]').remove();
            renumberPackages();
            packagesChanged();
        });

        $packages.on('input change', '[data-role="pe-package-input"], .pe-package-fragile', packagesChanged);

        // Declared value and return receipts send a shipment to the office
        // too, and a locker cannot take them, so they count as well.
        $form.on('change', '#pe_declared_value, #pe_review, #pe_return_receipt, #pe_delivery_type', refreshHandIn);

        renumberPackages();

        // -- Declared value ---------------------------------------------------

        $form.find('[data-role="pe-declared-value"]').on('change', function () {
            $form.find('[data-role="pe-declared-amount"]').prop('hidden', !this.checked);
        });

        if ($type.length) {
            applyDeliveryType();

            $type.on('change', function () {
                // The office list and the locker list are different, so a choice
                // made under one type cannot carry over to the other.
                $locationId.val('');
                $location.val('');
                applyDeliveryType();
            });
        }

        /**
         * Attach a suggestion list to a text input.
         *
         * @param {jQuery} $input
         * @param {Function} fetch    Receives the query and a callback
         * @param {Function} onPick   Receives the chosen item
         * @param {Function} onType   Called whenever the value is edited by hand
         */
        function attach($input, fetch, onPick, onType) {
            var $list, timer, items = [], active = -1;

            if (!$input.length) {
                return;
            }

            $input.wrap('<span class="pigeonexpress-suggest-wrap"></span>');
            $list = $('<ul class="pigeonexpress-suggest" role="listbox"></ul>').insertAfter($input);

            function close() {
                $list.hide().empty();
                active = -1;
            }

            function note(text) {
                $list.empty().append($('<li class="pigeonexpress-suggest-note"></li>').text(text)).show();
            }

            function render() {
                $list.empty();

                if (!items.length) {
                    note($t(config.noResults || 'Nothing found'));
                    return;
                }

                items.forEach(function (item, index) {
                    $('<li class="pigeonexpress-suggest-item" role="option"></li>')
                        .text(item.label || item.name)
                        .on('mousedown', function (event) {
                            // mousedown, not click: blur would close the list first.
                            event.preventDefault();
                            choose(index);
                        })
                        .appendTo($list);
                });

                $list.show();
            }

            function choose(index) {
                var item = items[index];

                if (!item) {
                    return;
                }

                $input.val(item.name);
                onPick(item);
                close();
                $input.closest('.admin__field').removeClass('_error _warn')
                    .find('.pe-message').remove();
            }

            function highlight(step) {
                var $options = $list.find('.pigeonexpress-suggest-item');

                if (!$options.length) {
                    return;
                }

                active = (active + step + $options.length) % $options.length;
                $options.removeClass('_active').eq(active).addClass('_active');
            }

            $input.on('input', function () {
                var query = $.trim($input.val());

                onType();
                clearTimeout(timer);

                if (query.length < MIN_QUERY) {
                    close();
                    return;
                }

                timer = setTimeout(function () {
                    fetch(query, function (result) {
                        if (result.error) {
                            note(result.error);
                            return;
                        }
                        items = result.items || [];
                        active = -1;
                        render();
                    });
                }, DEBOUNCE_MS);
            });

            $input.on('keydown', function (event) {
                if (!$list.is(':visible')) {
                    return;
                }

                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    highlight(1);
                } else if (event.key === 'ArrowUp') {
                    event.preventDefault();
                    highlight(-1);
                } else if (event.key === 'Enter' && active > -1) {
                    event.preventDefault();
                    choose(active);
                } else if (event.key === 'Escape') {
                    close();
                }
            });

            $input.on('blur', function () {
                setTimeout(close, 150);
            });
        }

        attach(
            $city,
            function (query, done) {
                $.getJSON(config.citySearchUrl, {q: query}).done(done).fail(function () {
                    done({items: []});
                });
            },
            function (item) {
                $cityId.val(item.id);

                // The postal code is part of the city in the carrier's own data,
                // and it is what distinguishes two villages of the same name, so
                // choosing a city settles it. A code left over from a different
                // town is worse than none.
                if ($postcode.length && item.postcode) {
                    $postcode.val(item.postcode);
                }

                // A street belongs to one city, so the previous choice is void.
                $streetId.val('');
                $street.val('');
            },
            function () {
                // Typed by hand, so we no longer know which city this is. The
                // server tries to match it again on submit.
                $cityId.val('');
                $streetId.val('');
            }
        );

        attach(
            $street,
            function (query, done) {
                if (!$.trim($cityId.val())) {
                    done({error: $t(config.chooseCityFirst || 'Choose a city first')});
                    return;
                }

                $.getJSON(config.streetSearchUrl, {
                    q: query,
                    city_id: $cityId.val()
                }).done(done).fail(function () {
                    done({error: $t(config.streetsUnavailable || 'Street list unavailable, type it by hand')});
                });
            },
            function (item) {
                $streetId.val(item.id);
            },
            function () {
                $streetId.val('');
            }
        );

        attach(
            $location,
            function (query, done) {
                $.getJSON(config.locationSearchUrl, {
                    q: query,
                    type: $type.val()
                }).done(done).fail(function () {
                    done({items: []});
                });
            },
            function (item) {
                // A change event, so the locker's free boxes follow the pick.
                $locationId.val(item.id).trigger('change');
            },
            function () {
                if ($locationId.val() !== '') {
                    $locationId.val('').trigger('change');
                }
            }
        );

        // What the carrier charges for the shipment as currently described. Kept
        // apart from what the customer paid: after a parcel is redirected the two
        // are different numbers, and showing one in place of the other would hide
        // exactly the difference the merchant asked to see.
        $form.find('[data-role="pe-recalculate"]').on('click', function () {
            var $button = $(this),
                $message = $form.find('[data-role="pe-quote"]'),
                $feeLabel = $form.find('[data-role="pe-fee-label"]'),
                $newFeeRow = $form.find('[data-role="pe-new-fee-row"]'),
                $newFeeSender = $form.find('[data-role="pe-new-fee-sender"]'),
                $newFeeReceiver = $form.find('[data-role="pe-new-fee-receiver"]');

            $button.prop('disabled', true);
            $message.removeClass('_error').text($t(config.recalculating || 'Loading...'));

            $.post(config.recalculateUrl, $form.serialize())
                .done(function (response) {
                    if (response && response.error) {
                        $message.addClass('_error').text(response.error);
                        return;
                    }

                    // The fees already on screen are what the customer paid, so
                    // they become the old ones rather than being overwritten.
                    // Seeing both is the whole point of asking. There is a row
                    // per side now, and each carries its own wording, so they
                    // are relabelled one at a time rather than all to the same
                    // string.
                    $feeLabel.each(function () {
                        var $label = $(this);

                        $label.text($label.data('oldLabel') || $t(config.oldFeeLabel || 'Old shipping fee'));
                    });

                    // Server rendered currency markup, not user input, so it goes
                    // in as HTML. Escaped, the price tag itself would be readable
                    // on the page. Split by side like the stored rows, falling
                    // back to the single figure if an older response arrives.
                    $newFeeSender.html(response.sender_formatted || response.formatted);
                    $newFeeReceiver.html(response.receiver_formatted || response.formatted);
                    $newFeeRow.prop('hidden', false);
                    $message.text('');

                    if (typeof response.hand_in === 'string' && response.hand_in !== '' && $handIn.length) {
                        $handIn.text(response.hand_in).removeClass('_stale');
                    }
                })
                .fail(function () {
                    $message.addClass('_error').text($t(config.unreachable || 'Could not reach Pigeon Express.'));
                })
                .always(function () {
                    $button.prop('disabled', false);
                });
        });

        // Immediate feedback on the fields nothing can be shipped without, so an
        // empty one is caught here rather than after a round trip.
        $form.on('submit', function (event) {
            var missing = false;

            $form.find('.admin__field._required:visible').each(function () {
                var $field = $(this),
                    $input = $field.find('input[type="text"]').first();

                if (!$.trim($input.val())) {
                    missing = true;
                    $field.addClass('_error').removeClass('_warn');

                    if (!$field.find('.pe-message').length) {
                        $field.find('.admin__field-control').append(
                            $('<div class="admin__field-error pe-message"></div>')
                                .text($t(config.requiredMessage || 'This field is required.'))
                        );
                    }
                }
            });

            // Every package needs a weight; the size falls back to the default box.
            $rows.find('.pe-package-weight').each(function () {
                var value = parseFloat($.trim($(this).val()).replace(',', '.'));

                if (!(value > 0)) {
                    missing = true;
                    $(this).addClass('_error');
                    $packages.addClass('_error');
                    $packageMessage.prop('hidden', false)
                        .text($t(config.weightMessage || 'Please enter a weight greater than zero.'));
                } else {
                    $(this).removeClass('_error');
                }
            });

            if (missing) {
                event.preventDefault();
                $form.find('.admin__field._error').first()
                    .find('input[type="text"]').first().trigger('focus');

                return;
            }

            // One create per click: a second submit while the first is on its
            // way could create a second waybill. The server locks the order
            // as well; this only spares the admin the error.
            if ($form.data('peSubmitting')) {
                event.preventDefault();

                return;
            }
            $form.data('peSubmitting', true);
            $form.find('button[type="submit"]').prop('disabled', true);
        });
    };
});
