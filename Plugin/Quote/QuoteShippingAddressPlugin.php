<?php
/**
 * When quote shipping address is read, ensure Pigeon Express location is in extension_attributes.
 * Addresses loaded with the quote often come from collection, so ResourceModel::load() plugin doesn't run.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Plugin\Quote;

use Magento\Quote\Model\Quote;
use Magento\Quote\Api\Data\AddressExtensionInterfaceFactory;
use PigeonExpress\Shipping\Model\QuoteAddressLocationPersistor;

class QuoteShippingAddressPlugin
{
    /**
     * @var QuoteAddressLocationPersistor
     */
    private $persistor;

    /**
     * @var AddressExtensionInterfaceFactory
     */
    private $extensionFactory;

    public function __construct(
        QuoteAddressLocationPersistor $persistor,
        AddressExtensionInterfaceFactory $extensionFactory
    ) {
        $this->persistor = $persistor;
        $this->extensionFactory = $extensionFactory;
    }

    /**
     * After getShippingAddress: load our data from DB if not already set (e.g. when quote was loaded with collection).
     *
     * @param Quote $subject
     * @param \Magento\Quote\Model\Quote\Address|null $result
     * @return \Magento\Quote\Model\Quote\Address|null
     */
    public function afterGetShippingAddress(Quote $subject, $result)
    {
        if ($result === null) {
            return $result;
        }

        $id = $result->getId();
        if (!$id) {
            return $result;
        }

        $ext = $result->getExtensionAttributes();
        if ($ext !== null && $ext->getPigeonexpressLocationId() !== null && $ext->getPigeonexpressLocationId() !== '') {
            return $result;
        }

        $data = $this->persistor->getByAddressId((int) $id);
        if ($data === null) {
            return $result;
        }

        if ($ext === null) {
            $ext = $this->extensionFactory->create();
            $result->setExtensionAttributes($ext);
        }

        $ext->setPigeonexpressDeliveryType($data['delivery_type'] ?: null);
        $ext->setPigeonexpressLocationId($data['location_id'] ?: null);
        $ext->setPigeonexpressLocationName($data['location_name'] ?: null);
        $ext->setPigeonexpressLocationAddress($data['location_address'] ?: null);
        $ext->setPigeonexpressInstructions($data['instructions'] ?: null);

        return $result;
    }
}
