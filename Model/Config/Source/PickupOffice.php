<?php
/**
 * Source model: list of Pigeon Express offices for "Pickup office" config.
 * Uses synced offices from pigeonexpress_office; value = api_id (PE API office ID).
 *
 * @copyright Copyright (c). All rights reserved.
 */
declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use PigeonExpress\Shipping\Model\ResourceModel\Office\CollectionFactory as OfficeCollectionFactory;

class PickupOffice implements OptionSourceInterface
{
    /**
     * @var OfficeCollectionFactory
     */
    private $officeCollectionFactory;

    public function __construct(OfficeCollectionFactory $officeCollectionFactory)
    {
        $this->officeCollectionFactory = $officeCollectionFactory;
    }

    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        $options = [
            ['value' => '', 'label' => __('-- Select pickup office --')],
        ];

        $collection = $this->officeCollectionFactory->create();
        $collection->addFieldToFilter('is_active', 1);
        $collection->setOrder('name', 'ASC');

        foreach ($collection as $office) {
            $apiId = $office->getApiId();
            if ($apiId === null) {
                continue;
            }
            $name = (string) ($office->getName() ?? '');
            $city = (string) ($office->getCity() ?? '');
            $label = $city !== '' ? $name . ' (' . $city . ')' : $name;
            if ($label === '') {
                $label = (string) $apiId;
            }
            $options[] = [
                'value' => (string) $apiId,
                'label' => $label,
            ];
        }

        return $options;
    }
}
