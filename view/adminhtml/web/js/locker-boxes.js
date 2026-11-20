/**
 * Pigeon Express: free boxes of the parcel locker picked in the shipment
 * form, and the verdict for this parcel.
 *
 * Asked when a locker is picked (or is already there on a locker delivery),
 * and again a moment after the packages change; the server keeps the
 * carrier's answer a few minutes, so a size change costs no carrier call. Gone
 * on any other delivery type. Only the latest answer counts, and "All boxes"
 * stays open or closed as the merchant left it. Copied from the WooCommerce
 * plugin (assets/js/admin.js, bindLockerBoxes).
 */
define(['jquery'], function ($) {
    'use strict';

    return function (config, element) {
        var $out = $(element),
            $form = $out.closest('form'),
            timer,
            seq = 0,
            lastKey = null,
            chosenOpen = null;

        function formKey() {
            return window.FORM_KEY || $form.find('input[name="form_key"]').val() || '';
        }

        function load(lockerId) {
            var $rows = $form.find('[data-role="pe-package-row"]'),
                $first = $rows.first(),
                value = function (selector) {
                    return String($first.find(selector).val() || '');
                },
                payload = {
                    location_id: lockerId,
                    packages: $rows.length,
                    weight: value('.pe-package-weight'),
                    length: value('.pe-package-length'),
                    width: value('.pe-package-width'),
                    height: value('.pe-package-height')
                },
                key = JSON.stringify(payload),
                mine;

            // Typing that changes nothing (a focus, the same digit again) asks nothing.
            if (key === lastKey) {
                return;
            }
            lastKey = key;

            mine = ++seq;
            payload.form_key = formKey();
            $out.addClass('_busy');

            $.ajax({url: config.url, type: 'POST', dataType: 'json', data: payload})
                .done(function (response) {
                    var html = response && typeof response.html === 'string' ? response.html : '';

                    if (mine !== seq) {
                        return;
                    }

                    // Markup drawn and escaped by the server.
                    $out.html(html).prop('hidden', html === '');

                    if (typeof chosenOpen === 'boolean') {
                        $out.find('.pe-locker-boxes__all').prop('open', chosenOpen);
                    }
                })
                .fail(function () {
                    if (mine !== seq) {
                        return;
                    }

                    lastKey = null;
                    $out.empty().append(
                        $('<p class="pe-locker-boxes__note"></p>').text(config.unreachable || '')
                    ).prop('hidden', false);
                })
                .always(function () {
                    if (mine === seq) {
                        $out.removeClass('_busy');
                    }
                });
        }

        function queue(delay) {
            var isLocker = String($form.find('[data-role="pe-delivery-type"]').val() || '') === 'aps',
                lockerId = String($form.find('#pe_location_id').val() || '');

            clearTimeout(timer);

            if (!isLocker || !lockerId) {
                // A late answer for the locker left behind must not show.
                seq++;
                lastKey = null;
                $out.prop('hidden', true).empty().removeClass('_busy');
                return;
            }

            timer = setTimeout(function () {
                load(lockerId);
            }, delay);
        }

        if (!$form.length || !config.url) {
            return;
        }

        $form.on('change', '#pe_location_id, [data-role="pe-delivery-type"]', function () {
            queue(0);
        });

        $form.on('input change', '[data-role="pe-package-input"]', function () {
            queue(700);
        });

        // Rows are added and removed by their own handlers; read after them.
        $form.on('click', '[data-role="pe-package-add"], [data-role="pe-package-remove"]', function () {
            setTimeout(function () {
                queue(700);
            }, 0);
        });

        // Remembered for the redraws while the parcel's size is typed. Read
        // before the browser flips it (a click comes first, also on a key).
        $out.on('click', '.pe-locker-boxes__all > summary', function () {
            chosenOpen = !this.parentNode.open;
        });

        queue(0);
    };
});
