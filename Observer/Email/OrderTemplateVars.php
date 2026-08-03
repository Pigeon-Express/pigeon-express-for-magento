<?php
/**
 * Add Pigeon Express delivery data to order confirmation email template variables.
 * Loads location/instructions from pigeonexpress_order_address when not in extension_attributes.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Observer\Email;

use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderAddressExtensionInterfaceFactory;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\OrderAddressLocationPersistor;

class OrderTemplateVars implements ObserverInterface
{
    /**
     * @var OrderAddressLocationPersistor
     */
    private $locationPersistor;

    /**
     * @var OrderAddressExtensionInterfaceFactory
     */
    private $orderAddressExtensionFactory;

    public function __construct(
        OrderAddressLocationPersistor $locationPersistor,
        OrderAddressExtensionInterfaceFactory $orderAddressExtensionFactory
    ) {
        $this->locationPersistor = $locationPersistor;
        $this->orderAddressExtensionFactory = $orderAddressExtensionFactory;
    }

    public function execute(Observer $observer): void
    {
        /** @var DataObject $transport */
        $transport = $observer->getEvent()->getData('transportObject');
        if (!$transport instanceof DataObject) {
            return;
        }

        $order = $transport->getData('order');
        if (!$order || !method_exists($order, 'getShippingMethod')) {
            return;
        }

        $method = $order->getShippingMethod();
        if (!$method || strpos((string) $method, ConfigInterface::CARRIER_CODE . '_') !== 0) {
            $transport->setData('pigeonexpress_show', false);
            return;
        }

        $address = $order->getShippingAddress();
        if (!$address || !$address->getId()) {
            $transport->setData('pigeonexpress_show', false);
            return;
        }

        $this->ensureOrderAddressPeData($address);
        $ext = $address->getExtensionAttributes();
        if (!$ext) {
            $transport->setData('pigeonexpress_show', false);
            return;
        }

        $locationName = (string) ($ext->getPigeonexpressLocationName() ?? '');
        $locationAddress = (string) ($ext->getPigeonexpressLocationAddress() ?? '');
        $instructions = (string) ($ext->getPigeonexpressInstructions() ?? '');

        // Plain text, and no markup of our own.
        //
        // This is read back out with {{var shipping_msg}}, which escapes
        // whatever it is given, in both strict and legacy template modes. Tags
        // put here do not style the message, they are shown to the customer as
        // the tags they are. There is nowhere in a stock template to put real
        // markup without replacing the merchant's own email, which is the whole
        // reason this goes through shipping_msg to begin with.
        //
        // Nor is it escaped here: the template does that, and doing it twice
        // turns an ampersand in a street name into "&amp;".
        $parts = [];
        if ($locationName !== '') {
            $parts[] = $locationName;
        }
        if ($locationAddress !== '') {
            $parts[] = $locationAddress;
        }

        // The carrier and the delivery type are deliberately absent: Magento
        // prints shipping_description directly above this, and that already
        // reads "Pigeon Express - Office Delivery". Saying it again here is how
        // the message came to be four lines long.
        $message = implode(', ', $parts);

        if ($instructions !== '') {
            $comment = (string) __('Comment: %1', $instructions);
            $message = $message === '' ? $comment : $message . '. ' . $comment;
        }

        $transport->setData('pigeonexpress_show', $message !== '');

        if ($message === '') {
            return;
        }

        // Magento's own order confirmation template renders shipping_msg right
        // below the shipping description, in every stock and customised variant.
        // Publishing here means the delivery details appear without replacing the
        // merchant's email template, which is what the removed reflection plugin
        // used to do.
        $existing = (string) ($transport->getData('shipping_msg') ?? '');
        $transport->setData('shipping_msg', $existing !== '' ? $existing . ' ' . $message : $message);
    }

    /**
     * Load PE data from DB into address extension attributes when missing.
     */
    private function ensureOrderAddressPeData($address): void
    {
        $ext = $address->getExtensionAttributes();
        $hasPeData = $ext
            && ($ext->getPigeonexpressLocationName() !== null && $ext->getPigeonexpressLocationName() !== ''
                || $ext->getPigeonexpressLocationAddress() !== null && $ext->getPigeonexpressLocationAddress() !== ''
                || $ext->getPigeonexpressDeliveryType() !== null && $ext->getPigeonexpressDeliveryType() !== '');
        if ($hasPeData) {
            return;
        }

        $data = $this->locationPersistor->getByAddressId((int) $address->getId());
        if ($data === null) {
            return;
        }

        if ($ext === null) {
            $ext = $this->orderAddressExtensionFactory->create();
            $address->setExtensionAttributes($ext);
        }

        $ext->setPigeonexpressDeliveryType($data['delivery_type'] ?: null);
        $ext->setPigeonexpressLocationId($data['location_id'] ?: null);
        $ext->setPigeonexpressLocationName($data['location_name'] ?: null);
        $ext->setPigeonexpressLocationAddress($data['location_address'] ?: null);
        $ext->setPigeonexpressInstructions($data['instructions'] ?: null);
        if (array_key_exists('delivery_price', $data)) {
            $ext->setPigeonexpressDeliveryPrice($data['delivery_price']);
        }
    }
}
