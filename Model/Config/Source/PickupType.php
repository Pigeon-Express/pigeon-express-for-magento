<?php
/**
 * Pickup type: where Pigeon Express receives the merchant's parcels.
 *
 * "Saved address" is offered only to an account that has saved addresses,
 * or to a store that chose one before (so the setting is never lost silently).
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Config\Source;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\OptionSourceInterface;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\Account\SavedAddresses;

class PickupType implements OptionSourceInterface
{
    public const OFFICE = ConfigInterface::PICKUP_TYPE_OFFICE;
    public const ADDRESS = ConfigInterface::PICKUP_TYPE_ADDRESS;
    public const LOCKER = ConfigInterface::PICKUP_TYPE_LOCKER;
    public const SAVED_ADDRESS = ConfigInterface::PICKUP_TYPE_SAVED_ADDRESS;

    /** @var SavedAddresses */
    private $savedAddresses;

    /** @var ScopeConfigInterface */
    private $scopeConfig;

    public function __construct(
        SavedAddresses $savedAddresses,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->savedAddresses = $savedAddresses;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        $options = [
            ['value' => self::OFFICE, 'label' => __('Office (Pigeon Express office)')],
            ['value' => self::ADDRESS, 'label' => __('Address (pickup from your address)')],
        ];

        if ($this->offersSavedAddress()) {
            $options[] = ['value' => self::SAVED_ADDRESS, 'label' => __('Saved address from your Pigeon Express account')];
        }

        $options[] = [
            'value' => self::LOCKER,
            'label' => __('Parcel locker - I leave shipments in a Pigeon Express parcel locker'),
        ];

        return $options;
    }

    private function offersSavedAddress(): bool
    {
        if ($this->scopeConfig->getValue('carriers/pigeonexpress/pickup_type') === self::SAVED_ADDRESS) {
            return true;
        }

        return $this->savedAddresses->hasAny();
    }
}
