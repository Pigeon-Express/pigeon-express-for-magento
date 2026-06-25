<?php
/**
 * Copy Pigeon Express delivery/location from ShippingInformation to quote shipping address.
 * Connection: frontend sends extension_attributes → this plugin writes them to quote address.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Plugin\Checkout;

use Magento\Checkout\Api\Data\ShippingInformationInterface;
use Magento\Checkout\Model\ShippingInformationManagement;
use Magento\Quote\Api\Data\AddressExtensionInterfaceFactory;
use Magento\Quote\Model\QuoteRepository;
use Psr\Log\LoggerInterface;
use PigeonExpress\Shipping\Model\QuoteAddressLocationPersistor;

class ShippingInformationManagementPlugin
{
    /**
     * @var QuoteRepository
     */
    private $quoteRepository;

    /**
     * @var AddressExtensionInterfaceFactory
     */
    private $addressExtensionFactory;

    /**
     * @var QuoteAddressLocationPersistor
     */
    private $locationPersistor;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        QuoteRepository $quoteRepository,
        AddressExtensionInterfaceFactory $addressExtensionFactory,
        QuoteAddressLocationPersistor $locationPersistor,
        LoggerInterface $logger
    ) {
        $this->quoteRepository = $quoteRepository;
        $this->addressExtensionFactory = $addressExtensionFactory;
        $this->locationPersistor = $locationPersistor;
        $this->logger = $logger;
    }

    /**
     * After saving shipping information, persist Pigeon Express fields to quote address.
     *
     * @param ShippingInformationManagement $subject
     * @param mixed $result
     * @param int $cartId
     * @param ShippingInformationInterface $addressInformation
     * @return mixed
     */
    public function afterSaveAddressInformation(
        ShippingInformationManagement $subject,
        $result,
        $cartId,
        ShippingInformationInterface $addressInformation
    ) {
        $ext = $addressInformation->getExtensionAttributes();

        $this->logger->info('[PE SaveAddressInfo] afterSaveAddressInformation called', [
            'cart_id' => $cartId,
            'ext_is_null' => $ext === null,
            'location_id' => $ext ? $ext->getPigeonexpressLocationId() : null,
        ]);

        if (!$ext) {
            return $result;
        }

        $quote = $this->quoteRepository->getActive($cartId);
        $address = $quote->getShippingAddress();
        if (!$address) {
            $this->logger->info('[PE SaveAddressInfo] No shipping address on quote', ['cart_id' => $cartId]);
            return $result;
        }

        $addrExt = $address->getExtensionAttributes();
        if ($addrExt === null) {
            $addrExt = $this->addressExtensionFactory->create();
            $address->setExtensionAttributes($addrExt);
        }

        $addrExt->setPigeonexpressDeliveryType($ext->getPigeonexpressDeliveryType());
        $addrExt->setPigeonexpressLocationId($ext->getPigeonexpressLocationId());
        $addrExt->setPigeonexpressLocationName($ext->getPigeonexpressLocationName());
        $addrExt->setPigeonexpressLocationAddress($ext->getPigeonexpressLocationAddress());
        $addrExt->setPigeonexpressInstructions($ext->getPigeonexpressInstructions());

        if ($address->getId()) {
            $this->locationPersistor->save(
                (int) $address->getId(),
                $addrExt->getPigeonexpressDeliveryType(),
                $addrExt->getPigeonexpressLocationId(),
                $addrExt->getPigeonexpressLocationName(),
                $addrExt->getPigeonexpressLocationAddress(),
                $addrExt->getPigeonexpressInstructions()
            );
            $this->logger->info('[PE SaveAddressInfo] Persisted to PE quote table', [
                'cart_id' => $cartId,
                'address_id' => $address->getId(),
                'location_id' => $addrExt->getPigeonexpressLocationId(),
            ]);
        }

        $this->logger->info('[PE SaveAddressInfo] Set on address', [
            'cart_id' => $cartId,
            'address_id' => $address->getId(),
            'location_id' => $ext->getPigeonexpressLocationId(),
        ]);

        return $result;
    }
}
