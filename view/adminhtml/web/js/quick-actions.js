/**
 * Pigeon Express: quick changes of a shipment the courier already has.
 *
 * "Open quick changes" asks the server what Pigeon Express allows now and
 * drops in the forms it drew. Each form prices its change first and offers
 * Confirm only beside that price, which goes the moment anything in the form
 * changes. A confirmed change reloads the order page, where the message waits.
 *
 * No money arithmetic here: the server sends the figures formatted. Copied
 * from the WooCommerce plugin (assets/js/admin.js, bindQuickActions).
 */
define(['jquery', 'PigeonExpress_Shipping/js/place-search'], function ($, placeSearch) {
    'use strict';

    return function (config, element) {
        var $box = $(element),
            $body = $box.find('[data-role="pe-quick-body"]'),
            $error = $box.find('[data-role="pe-quick-error"]'),
            $open = $box.find('[data-role="pe-quick-open"]');

        function formKey() {
            return window.FORM_KEY || $('input[name="form_key"]').first().val() || '';
        }

        function showError(message) {
            $error.text(message || '').prop('hidden', !message);
        }

        function message($form, text) {
            $form.find('[data-role="pe-quick-message"]').text(text || '').prop('hidden', !text);
        }

        function hidePrice($form) {
            $form.find('[data-role="pe-quick-price"]').prop('hidden', true).empty();
            message($form, '');
            $form.find('[data-role="pe-quick-run"][data-pe-dry-run="0"]').prop('disabled', true);
        }

        /**
         * The fields of the chosen delivery type only; the others are switched
         * off so they are not sent.
         */
        function applyDelivery($form) {
            var type = String($form.find('[data-role="pe-quick-type"]').val() || 'office');

            $form.find('[data-pe-qa-for]').each(function () {
                var on = String($(this).attr('data-pe-qa-for') || '').split(/\s+/).indexOf(type) !== -1;

                $(this).prop('hidden', !on).find('input, select, textarea').prop('disabled', !on);
            });
        }

        function initForms() {
            $body.find('[data-role="pe-quick-form"]').each(function () {
                var $form = $(this);

                $form.find('[data-role="pe-search"]').each(function () {
                    var settings = $(this).data('peSearch') || {};

                    settings.noResults = settings.noResults || config.noResults;
                    placeSearch(settings, this);
                });

                // Another city: the street picked for the old one means nothing.
                $form.find('#pe_qa_city_id').on('change', function () {
                    $form.find('#pe_qa_street_id').val('');
                    $form.find('#pe_qa_street').val('');
                });

                applyDelivery($form);
            });
        }

        function reEnable() {
            $box.find('[data-role="pe-quick-run"][data-pe-dry-run="1"]').prop('disabled', false);
        }

        $open.on('click', function () {
            if ($open.prop('disabled')) {
                return;
            }

            $open.prop('disabled', true);
            showError('');
            $body.addClass('_busy');

            $.ajax({
                url: config.optionsUrl,
                type: 'POST',
                dataType: 'json',
                data: {form_key: formKey()}
            }).done(function (response) {
                if (response && typeof response.html === 'string') {
                    // Markup drawn and escaped by the server.
                    $body.html(response.html);
                    $box.find('[data-role="pe-quick-open-row"]').prop('hidden', true);
                    initForms();
                    return;
                }

                showError(response && response.error ? response.error : config.requestFailed);
                $open.prop('disabled', false);
            }).fail(function () {
                showError(config.requestFailed);
                $open.prop('disabled', false);
            }).always(function () {
                $body.removeClass('_busy');
            });
        });

        $box.on('change', '[data-role="pe-quick-type"]', function () {
            applyDelivery($(this).closest('[data-role="pe-quick-form"]'));
        });

        // A price is for the form as it was when asked for.
        $box.on('change input', '[data-role="pe-quick-form"] :input', function () {
            hidePrice($(this).closest('[data-role="pe-quick-form"]'));
        });

        $box.on('click', '[data-role="pe-quick-run"]', function () {
            var $button = $(this),
                $form = $button.closest('[data-role="pe-quick-form"]'),
                dryRun = String($button.attr('data-pe-dry-run')) === '1',
                $price = $form.find('[data-role="pe-quick-price"]'),
                $show = $form.find('[data-role="pe-quick-run"][data-pe-dry-run="1"]'),
                $commit = $form.find('[data-role="pe-quick-run"][data-pe-dry-run="0"]'),
                data;

            if ($button.prop('disabled') || !$form.length) {
                return;
            }

            data = $form.find(':input').filter(function () {
                return !this.disabled && this.name;
            }).serializeArray();
            data.push({name: 'qa_dry_run', value: dryRun ? '1' : '0'});
            data.push({name: 'form_key', value: formKey()});

            $show.prop('disabled', true);
            $commit.prop('disabled', true);
            message($form, '');

            // The page is reloaded after a change: nothing else may start meanwhile.
            if (!dryRun) {
                $box.find('[data-role="pe-quick-run"]').prop('disabled', true);
            }

            $.ajax({
                url: config.changeUrl,
                type: 'POST',
                dataType: 'json',
                data: $.param(data)
            }).done(function (response) {
                if (response && response.reload) {
                    window.location.href = response.reload;
                    return;
                }

                if (!dryRun) {
                    reEnable();
                }
                $show.prop('disabled', false);

                if (dryRun && response && typeof response.html === 'string') {
                    // Escaped by the server.
                    $price.html(response.html).prop('hidden', false);
                    $commit.prop('disabled', false);
                    return;
                }

                if (dryRun) {
                    $price.prop('hidden', true).empty();
                } else {
                    // The price on screen still describes the form.
                    $commit.prop('disabled', $price.prop('hidden'));
                }

                message($form, response && response.error ? response.error : config.requestFailed);
            }).fail(function () {
                if (!dryRun) {
                    reEnable();
                    $commit.prop('disabled', $price.prop('hidden'));
                }
                $show.prop('disabled', false);
                message($form, config.requestFailed);
            });
        });
    };
});
