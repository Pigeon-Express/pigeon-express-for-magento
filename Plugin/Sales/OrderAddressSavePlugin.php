<?php
/**
 * After order address save: persist Pigeon Express location from extension_attributes to pigeonexpress_order_address.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Plugin\Sales;

use Magento\Sales\Model\Order\Address;
use PigeonExpress\Shipping\Model\OrderAddressLocationPersistor;

class OrderAddressSavePlugin
{
    /**
     * @var OrderAddressLocationPersistor
     */
    private $persistor;

    public function __construct(OrderAddressLocationPersistor $persistor)
    {
        $this->persistor = $persistor;
    }

    /**
     * After address save, write Pigeon Express data to our table if present.
     *
     * @param Address $subject
     * @param Address $result
     * @return Address
     */
    public function afterSave(Address $subject, Address $result): Address
    {
        $id = $result->getId();
        if (!$id) {
            return $result;
        }

        $ext = $result->getExtensionAttributes();
        if (!$ext) {
            return $result;
        }

        $this->persistor->save(
            (int) $id,
            $ext->getPigeonexpressDeliveryType(),
            $ext->getPigeonexpressLocationId(),
            $ext->getPigeonexpressLocationName(),
            $ext->getPigeonexpressLocationAddress(),
            $ext->getPigeonexpressInstructions(),
            $ext->getPigeonexpressDeliveryPrice()
        );

        return $result;
    }
}
