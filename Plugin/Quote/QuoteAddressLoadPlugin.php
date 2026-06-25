<?php
/**
 * After quote address load: load Pigeon Express location from pigeonexpress_quote_address into extension_attributes.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Plugin\Quote;

use Magento\Framework\Model\AbstractModel;
use Magento\Quote\Model\ResourceModel\Quote\Address as QuoteAddressResource;
use Magento\Quote\Api\Data\AddressExtensionInterfaceFactory;
use PigeonExpress\Shipping\Model\QuoteAddressLocationPersistor;
use Psr\Log\LoggerInterface;

class QuoteAddressLoadPlugin
{
    /**
     * @var QuoteAddressLocationPersistor
     */
    private $persistor;

    /**
     * @var AddressExtensionInterfaceFactory
     */
    private $extensionFactory;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        QuoteAddressLocationPersistor $persistor,
        AddressExtensionInterfaceFactory $extensionFactory,
        LoggerInterface $logger
    ) {
        $this->persistor = $persistor;
        $this->extensionFactory = $extensionFactory;
        $this->logger = $logger;
    }

    /**
     * After address load, populate extension_attributes from our table.
     *
     * @param QuoteAddressResource $subject
     * @param QuoteAddressResource $result
     * @param AbstractModel $object
     * @param mixed $value
     * @param string|null $field
     * @return QuoteAddressResource
     */
    public function afterLoad(
        QuoteAddressResource $subject,
        QuoteAddressResource $result,
        AbstractModel $object,
        $value,
        $field = null
    ): QuoteAddressResource {
        $id = $object->getId();
        if (!$id) {
            return $result;
        }

        $data = $this->persistor->getByAddressId((int) $id);
        $this->logger->info('[PE QuoteAddressLoad] afterLoad', [
            'address_id' => $id,
            'data_from_db' => $data,
        ]);
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

        return $result;
    }
}
