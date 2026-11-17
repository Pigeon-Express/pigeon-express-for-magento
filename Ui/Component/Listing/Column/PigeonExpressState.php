<?php
/**
 * The "Pigeon Express" filter options of Sales > Orders.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Ui\Component\Listing\Column;

use Magento\Framework\Data\OptionSourceInterface;
use PigeonExpress\Shipping\Model\Magento\Grid\StateFilter;

class PigeonExpressState implements OptionSourceInterface
{
    /** @var StateFilter */
    private $stateFilter;

    public function __construct(StateFilter $stateFilter)
    {
        $this->stateFilter = $stateFilter;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->stateFilter->options() as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }

        return $options;
    }
}
