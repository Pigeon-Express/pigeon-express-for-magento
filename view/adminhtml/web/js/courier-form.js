/**
 * Pigeon Express: the courier request form. Shows the end of the pickup time
 * only for an interval, the address fields of the chosen address, and the
 * city and street search boxes.
 */
define(['jquery', 'PigeonExpress_Shipping/js/place-search'], function ($, placeSearch) {
    'use strict';

    return function (config, element) {
        var $form = $(element);

        function sync() {
            var interval = $form.find('[name="courier[time_type]"]:checked').val() !== 'specific_time',
                mode = $form.find('[name="courier[address_mode]"]:checked').val() || 'other';

            $form.find('[data-role="pe-time-to"]').toggle(interval);
            $form.find('[data-pe-mode]').each(function () {
                $(this).toggle($(this).data('peMode') === mode);
            });
        }

        $form.find('[data-role="pe-search"]').each(function () {
            placeSearch($(this).data('peSearch'), this);
        });
        $form.on('change', '[name="courier[time_type]"], [name="courier[address_mode]"]', sync);
        sync();
    };
});
