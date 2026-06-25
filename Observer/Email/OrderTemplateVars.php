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
     * @var ConfigInterface
     */
    private $config;

    /**
     * @var OrderAddressLocationPersistor
     */
    private $locationPersistor;

    /**
     * @var OrderAddressExtensionInterfaceFactory
     */
    private $orderAddressExtensionFactory;

    public function __construct(
        ConfigInterface $config,
        OrderAddressLocationPersistor $locationPersistor,
        OrderAddressExtensionInterfaceFactory $orderAddressExtensionFactory
    ) {
        $this->config = $config;
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

        $type = $ext->getPigeonexpressDeliveryType();
        $storeId = $order->getStoreId() !== null ? (int) $order->getStoreId() : null;
        $typeLabel = $type ? (string) $this->config->getDeliveryTypeTitle($type, $storeId) : '';
        $locationName = (string) ($ext->getPigeonexpressLocationName() ?? '');
        $locationAddress = (string) ($ext->getPigeonexpressLocationAddress() ?? '');
        $instructions = (string) ($ext->getPigeonexpressInstructions() ?? '');

        $lines = [];
        $lines[] = '<strong>Pigeon Express</strong>';
        if ($typeLabel !== '') {
            $lines[] = 'Type: ' . htmlspecialchars($typeLabel, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if ($locationName !== '') {
            $lines[] = htmlspecialchars($locationName, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if ($locationAddress !== '') {
            $lines[] = htmlspecialchars($locationAddress, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if ($instructions !== '') {
            $lines[] = 'Comment: ' . htmlspecialchars($instructions, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $html = '<p class="pigeonexpress-delivery-email">' . implode('<br/>', $lines) . '</p>';

        $transport->setData('pigeonexpress_show', true);
        $transport->setData('pigeonexpress_html', $html);
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
