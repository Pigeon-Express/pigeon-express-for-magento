<?php
/**
 * Flat rate price that clears itself when its delivery type is priced dynamically.
 *
 * The field is hidden in the admin by a `depends` rule, but Magento still submits
 * hidden inputs, so without this the old amount would sit in the database
 * indefinitely. Someone switching back to fixed pricing months later would
 * silently inherit a stale price they never re-confirmed.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use PigeonExpress\Shipping\Model\Config\Source\PricingMode;

class FlatRate extends Value
{
    /** Field id suffix this backend model is attached to. */
    private const FLAT_RATE_SUFFIX = '_flat_rate';

    /** Sibling field holding the pricing mode for the same delivery type. */
    private const PRICING_MODE_SUFFIX = '_pricing_mode';

    /**
     * @return $this
     */
    public function beforeSave()
    {
        if ($this->isDynamicallyPriced()) {
            $this->setValue('');
        }

        return parent::beforeSave();
    }

    /**
     * Whether the delivery type this price belongs to is priced by the API.
     *
     * The sibling value comes from `fieldset_data`, which Magento populates with
     * everything submitted in the same group, so this reads what the merchant
     * just chose rather than what is currently stored.
     */
    private function isDynamicallyPriced(): bool
    {
        $pricingModeField = $this->resolvePricingModeField();
        if ($pricingModeField === null) {
            return false;
        }

        $mode = $this->getFieldsetDataValue($pricingModeField);

        // Absent means the field was not part of this submission, so leave the
        // stored price alone rather than wiping it on an unrelated save.
        if ($mode === null || $mode === '') {
            return false;
        }

        return $mode === PricingMode::DYNAMIC;
    }

    /**
     * `address_flat_rate` belongs to `address_pricing_mode`, and so on.
     */
    private function resolvePricingModeField(): ?string
    {
        $field = (string) $this->getData('field');

        if ($field === '' || substr($field, -strlen(self::FLAT_RATE_SUFFIX)) !== self::FLAT_RATE_SUFFIX) {
            return null;
        }

        $deliveryType = substr($field, 0, -strlen(self::FLAT_RATE_SUFFIX));

        return $deliveryType . self::PRICING_MODE_SUFFIX;
    }
}
