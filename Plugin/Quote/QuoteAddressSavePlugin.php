<?php
/**
 * After quote address save: persist Pigeon Express location from extension_attributes to pigeonexpress_quote_address.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Plugin\Quote;

use Magento\Quote\Model\Quote\Address;
use PigeonExpress\Shipping\Model\QuoteAddressLocationPersistor;
use Psr\Log\LoggerInterface;

class QuoteAddressSavePlugin
{
    /**
     * @var QuoteAddressLocationPersistor
     */
    private $persistor;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(QuoteAddressLocationPersistor $persistor, LoggerInterface $logger)
    {
        $this->persistor = $persistor;
        $this->logger = $logger;
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
            $this->logger->info('[PE QuoteAddressSave] No extension_attributes', ['address_id' => $id]);
            return $result;
        }

        $locationId = $ext->getPigeonexpressLocationId();
        $this->logger->info('[PE QuoteAddressSave] afterSave persisting', [
            'address_id' => $id,
            'location_id' => $locationId,
        ]);

        $this->persistor->save(
            (int) $id,
            $ext->getPigeonexpressDeliveryType(),
            $ext->getPigeonexpressLocationId(),
            $ext->getPigeonexpressLocationName(),
            $ext->getPigeonexpressLocationAddress(),
            $ext->getPigeonexpressInstructions()
        );

        return $result;
    }
}
