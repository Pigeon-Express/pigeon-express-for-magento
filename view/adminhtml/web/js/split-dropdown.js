/**
 * Pigeon Express: the label download menu.
 *
 * Magento's admin theme already styles a split button and shows its menu when
 * the wrapper carries `_active`. All that is missing is the toggling, which is
 * this. Initialised through data-mage-init rather than an inline handler, so the
 * admin stays within Content Security Policy.
 */
define(['jquery'], function ($) {
    'use strict';

    return function (config, element) {
        var $split = $(element);

        $split.on('click', '.action-toggle', function (event) {
            event.preventDefault();
            $split.toggleClass('_active');
        });

        // Choosing a format starts a download and leaves the page as it is, so
        // the menu has to be closed here rather than by a navigation.
        $split.on('click', '.dropdown-menu a', function () {
            $split.removeClass('_active');
        });

        $(document).on('click.pigeonexpressLabelMenu', function (event) {
            if (element !== event.target && !$.contains(element, event.target)) {
                $split.removeClass('_active');
            }
        });

        $(document).on('keyup.pigeonexpressLabelMenu', function (event) {
            if (event.key === 'Escape') {
                $split.removeClass('_active');
            }
        });
    };
});
