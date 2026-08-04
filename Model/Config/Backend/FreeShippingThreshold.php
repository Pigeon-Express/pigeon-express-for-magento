<?php
/**
 * Free shipping threshold that clears itself when the threshold is switched off.
 *
 * The field is hidden in the admin by a `depends` rule, but Magento still submits
 * hidden inputs, so without this the old amount would sit in the database
 * indefinitely. Someone turning the threshold back on months later would silently
 * start giving away deliveries at a figure they never re-confirmed.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Config\Backend;

use Magento\Framework\App\Config\Value;

class FreeShippingThreshold extends Value
{
    /** Field id suffix this backend model is attached to. */
    private const THRESHOLD_SUFFIX = '_free_shipping_threshold';

    /** Sibling field holding the toggle for the same delivery type. */
    private const ENABLED_SUFFIX = '_free_shipping_enabled';

    /**
     * @return $this
     */
    public function beforeSave()
    {
        if ($this->isThresholdDisabled()) {
            $this->setValue('');
        }

        return parent::beforeSave();
    }

    /**
     * Whether the delivery type this threshold belongs to has it switched off.
     *
     * The sibling value comes from `fieldset_data`, which Magento populates with
     * everything submitted in the same group, so this reads what the merchant
     * just chose rather than what is currently stored.
     */
    private function isThresholdDisabled(): bool
    {
        $enabledField = $this->resolveEnabledField();
        if ($enabledField === null) {
            return false;
        }

        $enabled = $this->getFieldsetDataValue($enabledField);

        // Absent means the field was not part of this submission, so leave the
        // stored threshold alone rather than wiping it on an unrelated save.
        if ($enabled === null || $enabled === '') {
            return false;
        }

        return !(bool) $enabled;
    }

    /**
     * `address_free_shipping_threshold` belongs to `address_free_shipping_enabled`,
     * and so on.
     */
    private function resolveEnabledField(): ?string
    {
        $field = (string) $this->getData('field');

        if ($field === '' || substr($field, -strlen(self::THRESHOLD_SUFFIX)) !== self::THRESHOLD_SUFFIX) {
            return null;
        }

        $deliveryType = substr($field, 0, -strlen(self::THRESHOLD_SUFFIX));

        return $deliveryType . self::ENABLED_SUFFIX;
    }
}
