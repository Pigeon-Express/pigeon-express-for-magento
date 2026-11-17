<?php
/**
 * Source model: the addresses saved in the Pigeon Express account.
 *
 * @copyright Copyright (c). All rights reserved.
 */
declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use PigeonExpress\Shipping\Model\Account\SavedAddresses;

class SavedAddress implements OptionSourceInterface
{
    /** @var SavedAddresses */
    private $savedAddresses;

    public function __construct(SavedAddresses $savedAddresses)
    {
        $this->savedAddresses = $savedAddresses;
    }

    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        $options = [['value' => '', 'label' => __('- Select an address -')]];

        foreach ($this->savedAddresses->all() as $id => $entry) {
            $label = (string) $entry['label'];
            if (!empty($entry['is_default'])) {
                $label = (string) __('%1 (default)', $label);
            }
            $options[] = ['value' => (string) $id, 'label' => $label];
        }

        return $options;
    }
}
