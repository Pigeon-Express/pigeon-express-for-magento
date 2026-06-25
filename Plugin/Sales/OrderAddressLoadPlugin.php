<?php
/**
 * After order address load: load Pigeon Express location from pigeonexpress_order_address into extension_attributes.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Plugin\Sales;

use Magento\Framework\Model\AbstractModel;
use Magento\Sales\Model\ResourceModel\Order\Address as OrderAddressResource;
use Magento\Sales\Api\Data\OrderAddressExtensionInterfaceFactory;
use PigeonExpress\Shipping\Model\OrderAddressLocationPersistor;

class OrderAddressLoadPlugin
{
    /**
     * @var OrderAddressLocationPersistor
     */
    private $persistor;

    /**
     * @var OrderAddressExtensionInterfaceFactory
     */
    private $extensionFactory;

    public function __construct(
        OrderAddressLocationPersistor $persistor,
        OrderAddressExtensionInterfaceFactory $extensionFactory
    ) {
        $this->persistor = $persistor;
        $this->extensionFactory = $extensionFactory;
    }

    /**
     * After address load, populate extension_attributes from our table.
     *
     * @param OrderAddressResource $subject
     * @param OrderAddressResource $result
     * @param AbstractModel $object
     * @param mixed $value
     * @param string|null $field
     * @return OrderAddressResource
     */
    public function afterLoad(
        OrderAddressResource $subject,
        OrderAddressResource $result,
        AbstractModel $object,
        $value,
        $field = null
    ): OrderAddressResource {
        $id = $object->getId();
        if (!$id) {
            return $result;
        }

        $data = $this->persistor->getByAddressId((int) $id);
        if ($data === null) {
            return $result;
        }

        $ext = $object->getExtensionAttributes();
        if ($ext === null) {
            $ext = $this->extensionFactory->create();
            $object->setExtensionAttributes($ext);
        }

        $ext->setPigeonexpressDeliveryType($data['delivery_type'] ?: null);
        $ext->setPigeonexpressLocationId($data['location_id'] ?: null);
        $ext->setPigeonexpressLocationName($data['location_name'] ?: null);
        $ext->setPigeonexpressLocationAddress($data['location_address'] ?: null);
        $ext->setPigeonexpressInstructions($data['instructions'] ?: null);
        if (array_key_exists('delivery_price', $data)) {
            $ext->setPigeonexpressDeliveryPrice($data['delivery_price']);
        }

        return $result;
    }
}
