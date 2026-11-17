/**
 * Pigeon Express: read the charges of a shipment that has none stored yet,
 * on request, and put the "Pigeon Express charges" box in place.
 */
define([
    'jquery',
    'mage/translate'
], function ($, $t) {
    'use strict';

    return function (config, element) {
        var $box = $(element),
            $button = $box.find('[data-role="pe-charges-load"]'),
            $message = $box.find('[data-role="pe-charges-message"]');

        $button.on('click', function () {
            $button.prop('disabled', true);
            $message.text(config.loading || $t('Loading...'));

            $.ajax({
                url: config.url,
                type: 'POST',
                dataType: 'json',
                data: {form_key: window.FORM_KEY}
            }).done(function (response) {
                if (response && response.html) {
                    $box.replaceWith(response.html);
                    return;
                }
                $message.text(response && response.error ? response.error : config.unreachable);
                $button.prop('disabled', false);
            }).fail(function () {
                $message.text(config.unreachable);
                $button.prop('disabled', false);
            });
        });
    };
});
