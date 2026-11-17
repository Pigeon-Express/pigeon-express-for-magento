<?php
/**
 * The parcel locker select of "Parcel locker for hand-in" as a searchable
 * picker. The same picker as the pickup office, listing lockers instead.
 *
 * @copyright Copyright (c). All rights reserved.
 */
declare(strict_types=1);

namespace PigeonExpress\Shipping\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use PigeonExpress\Shipping\Model\ResourceModel\Aps\CollectionFactory as ApsCollectionFactory;
use PigeonExpress\Shipping\Model\ResourceModel\Office\CollectionFactory as OfficeCollectionFactory;

class LockerPicker extends OfficePicker
{
    /** @var ApsCollectionFactory */
    private $apsCollectionFactory;

    public function __construct(
        Context $context,
        OfficeCollectionFactory $officeCollectionFactory,
        ApsCollectionFactory $apsCollectionFactory,
        array $data = []
    ) {
        $this->apsCollectionFactory = $apsCollectionFactory;
        parent::__construct($context, $officeCollectionFactory, $data);
    }

    /**
     * @inheritdoc
     */
    protected function texts(): array
    {
        return [
            'placeholder' => (string) __('Search by locker name, town or street'),
            'clear'       => (string) __('Clear'),
            'noResults'   => (string) __('Nothing found'),
            'noOffices'   => (string) __('No parcel lockers yet. Save your API credentials and run the location sync.'),
            'linked'      => (string) __('Pigeon Express ID %1'),
            'missing'     => (string) __('Parcel locker %1 is no longer in the Pigeon Express list. Choose another.'),
        ];
    }

    /**
     * @inheritdoc
     */
    protected function createCollection()
    {
        return $this->apsCollectionFactory->create();
    }
}
