<?php
/**
 * Source model: the synced parcel lockers, for "Parcel locker for hand-in".
 * Value = api_id (Pigeon Express locker id).
 *
 * @copyright Copyright (c). All rights reserved.
 */
declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use PigeonExpress\Shipping\Model\ResourceModel\Aps\CollectionFactory as ApsCollectionFactory;

class PickupLocker implements OptionSourceInterface
{
    /** @var ApsCollectionFactory */
    private $apsCollectionFactory;

    public function __construct(ApsCollectionFactory $apsCollectionFactory)
    {
        $this->apsCollectionFactory = $apsCollectionFactory;
    }

    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        $options = [
            ['value' => '', 'label' => __('-- Select parcel locker --')],
        ];

        $collection = $this->apsCollectionFactory->create();
        $collection->addFieldToFilter('is_active', 1);
        $collection->setOrder('name', 'ASC');

        foreach ($collection as $locker) {
            $apiId = $locker->getApiId();
            if ($apiId === null) {
                continue;
            }
            $label = (string) ($locker->getName() ?? '');
            $options[] = [
                'value' => (string) $apiId,
                'label' => $label !== '' ? $label : (string) $apiId,
            ];
        }

        return $options;
    }
}
