/**
 * Pigeon Express: ask before submitting a destructive admin form.
 *
 * Cancelling a shipment cannot be undone, so the click needs a confirmation.
 * Initialised through data-mage-init rather than an inline onsubmit handler, so
 * the admin stays within Content Security Policy.
 */
define([
    'jquery',
    'Magento_Ui/js/modal/confirm',
    'mage/translate'
], function ($, confirm, $t) {
    'use strict';

    return function (config, element) {
        var $form = $(element),
            confirmed = false;

        $form.on('submit', function (event) {
            if (confirmed) {
                return true;
            }

            event.preventDefault();

            var options = {
                title: config.title || $t('Are you sure?'),
                content: config.message || $t('This action cannot be undone.'),
                actions: {
                    confirm: function () {
                        confirmed = true;
                        $form.trigger('submit');
                    }
                }
            };

            // "Cancel" / "OK" under a title that says "Cancel ..." reads both
            // ways, so the buttons can say what each one does.
            if (config.confirmText && config.keepText) {
                options.buttons = [{
                    text: config.keepText,
                    'class': 'action-secondary action-dismiss',
                    click: function (clickEvent) {
                        this.closeModal(clickEvent);
                    }
                }, {
                    text: config.confirmText,
                    'class': 'action-primary action-accept',
                    click: function (clickEvent) {
                        this.closeModal(clickEvent, true);
                    }
                }];
            }

            confirm(options);

            return false;
        });
    };
});
