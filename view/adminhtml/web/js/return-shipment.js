/**
 * Pigeon Express: the return shipment form in the order panel. Shows the
 * fields of the chosen pickup and delivery, and adds or removes package rows.
 */
define(['jquery', 'PigeonExpress_Shipping/js/place-search'], function ($, placeSearch) {
    'use strict';

    return function (config, element) {
        var $form = $(element),
            $rows = $form.find('[data-role="pe-rs-packages"]'),
            template = $form.find('[data-role="pe-rs-package-template"]').html() || '',
            next = $rows.find('tr').length;

        function sync() {
            var pickup = $form.find('[name="rs[pickup_type]"]:checked').val(),
                delivery = $form.find('[name="rs[delivery_type]"]:checked').val();

            $form.find('[data-pe-pickup]').each(function () {
                $(this).toggle($(this).data('pePickup') === pickup);
            });
            $form.find('[data-pe-delivery]').each(function () {
                $(this).toggle($(this).data('peDelivery') === delivery);
            });
        }

        $form.find('[data-role="pe-search"]').each(function () {
            placeSearch($(this).data('peSearch'), this);
        });
        $form.on('change', '[name="rs[pickup_type]"], [name="rs[delivery_type]"]', sync);
        $form.on('click', '[data-role="pe-rs-add"]', function () {
            $rows.append(template.replace(/__INDEX__/g, String(next++)));
        });
        $form.on('click', '[data-role="pe-rs-remove"]', function () {
            if ($rows.find('tr').length > 1) {
                $(this).closest('tr').remove();
            }
        });
        sync();
    };
});
