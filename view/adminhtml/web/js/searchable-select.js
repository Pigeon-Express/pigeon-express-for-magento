/**
 * Pigeon Express: live filter for a long admin config select.
 *
 * Initialised through data-mage-init rather than an inline <script>, so the
 * admin stays within Content Security Policy. The office list runs to a few
 * hundred entries, which is unusable as a plain select.
 */
define(['jquery'], function ($) {
    'use strict';

    return function (config, element) {
        var $wrapper = $(element),
            input = $wrapper.find('[data-role="pe-select-search"]')[0],
            select = document.getElementById(config.selectId),
            allOptions;

        if (!input || !select) {
            return;
        }

        allOptions = Array.prototype.slice.call(select.options);

        input.addEventListener('input', function () {
            var query = this.value.toLowerCase().trim(),
                currentValue = select.value;

            while (select.options.length) {
                select.remove(0);
            }

            allOptions.forEach(function (option) {
                if (!query || option.text.toLowerCase().indexOf(query) !== -1) {
                    select.add(
                        new Option(option.text, option.value, false, option.value === currentValue)
                    );
                }
            });

            // Keep the saved value selected even when it is filtered out of view,
            // so typing a search never silently changes the stored setting.
            if (!select.value && currentValue) {
                select.value = currentValue;
            }
        });
    };
});
