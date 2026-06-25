<?php
/**
 * Block to display Pigeon Express delivery data (type, office/address, price).
 * Use with order (success page, order view, email) or quote (checkout).
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Block\Delivery;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Sales\Api\Data\OrderAddressExtensionInterfaceFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Quote\Model\Quote;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\OrderAddressLocationPersistor;
use PigeonExpress\Shipping\Model\ResourceModel\Shipment\CollectionFactory as ShipmentCollectionFactory;

class Info extends Template
{
    /**
     * @var ConfigInterface
     */
    private $config;

    /**
     * @var CheckoutSession
     */
    private $checkoutSession;

    /**
     * @var Registry
     */
    private $registry;

    /**
     * @var OrderAddressLocationPersistor
     */
    private $locationPersistor;

    /**
     * @var OrderAddressExtensionInterfaceFactory
     */
    private $orderAddressExtensionFactory;

    /**
     * @var ShipmentCollectionFactory
     */
    private $shipmentCollectionFactory;

    /**
     * @var FormKey
     */
    private $formKey;

    /**
     * @var OrderInterface|Quote|null
     */
    private $orderOrQuote;

    /**
     * @var \PigeonExpress\Shipping\Model\Shipment|null
     */
    private $cachedExistingShipment = null;

    /**
     * @var bool
     */
    private $existingShipmentLoaded = false;

    public function __construct(
        Context $context,
        ConfigInterface $config,
        CheckoutSession $checkoutSession,
        Registry $registry,
        OrderAddressLocationPersistor $locationPersistor,
        OrderAddressExtensionInterfaceFactory $orderAddressExtensionFactory,
        ShipmentCollectionFactory $shipmentCollectionFactory,
        FormKey $formKey,
        array $data = []
    ) {
        $this->config = $config;
        $this->checkoutSession = $checkoutSession;
        $this->registry = $registry;
        $this->locationPersistor = $locationPersistor;
        $this->orderAddressExtensionFactory = $orderAddressExtensionFactory;
        $this->shipmentCollectionFactory = $shipmentCollectionFactory;
        $this->formKey = $formKey;
        parent::__construct($context, $data);
    }

    /**
     * Resolve order/quote from registry or checkout session if not set.
     */
    protected function _beforeToHtml()
    {
        if ($this->orderOrQuote === null) {
            $order = $this->registry->registry('current_order');
            if ($order) {
                $this->orderOrQuote = $order;
            } else {
                $order = $this->checkoutSession->getLastRealOrder();
                if ($order && $order->getId()) {
                    $this->orderOrQuote = $order;
                }
            }
        }
        return parent::_beforeToHtml();
    }

    /**
     * Set order or quote for display.
     *
     * @param OrderInterface|Quote $orderOrQuote
     * @return $this
     */
    public function setOrderOrQuote($orderOrQuote): self
    {
        $this->orderOrQuote = $orderOrQuote;
        return $this;
    }

    /**
     * Whether the current order/quote uses Pigeon Express shipping.
     */
    public function isPigeonExpress(): bool
    {
        $order = $this->getOrder();
        if ($order && method_exists($order, 'getShippingMethod')) {
            $method = $order->getShippingMethod();
        } else {
            $address = $this->getShippingAddress();
            $method = $address && method_exists($address, 'getShippingMethod')
                ? $address->getShippingMethod()
                : null;
        }

        if ($method === null || $method === '') {
            return false;
        }

        return strpos((string) $method, ConfigInterface::CARRIER_CODE . '_') === 0;
    }

    /**
     * Delivery type (address, office, aps) or null.
     */
    public function getDeliveryType(): ?string
    {
        $ext = $this->getAddressExtensionAttributes();
        $type = $ext ? $ext->getPigeonexpressDeliveryType() : null;
        if ($type !== null && $type !== '') {
            return $type;
        }
        // Fallback: derive from shipping method (e.g. pigeonexpress_office -> office)
        $order = $this->getOrder();
        if ($order && method_exists($order, 'getShippingMethod')) {
            $method = (string) $order->getShippingMethod();
            $prefix = ConfigInterface::CARRIER_CODE . '_';
            if (strpos($method, $prefix) === 0) {
                return substr($method, strlen($prefix)) ?: null;
            }
        }
        return null;
    }

    /**
     * Human-readable delivery type label.
     */
    public function getDeliveryTypeLabel(): string
    {
        $type = $this->getDeliveryType();
        if (!$type) {
            return '';
        }
        return (string) $this->config->getDeliveryTypeTitle($type);
    }

    /**
     * Location name (office/APS name) or empty for address delivery.
     */
    public function getLocationName(): string
    {
        $ext = $this->getAddressExtensionAttributes();
        return $ext ? (string) ($ext->getPigeonexpressLocationName() ?? '') : '';
    }

    /**
     * Location address (office/APS address) or formatted shipping address for address delivery.
     */
    public function getLocationAddress(): string
    {
        $ext = $this->getAddressExtensionAttributes();
        if (!$ext) {
            return '';
        }
        $addr = $ext->getPigeonexpressLocationAddress();
        if ($addr !== null && $addr !== '') {
            return (string) $addr;
        }
        $shippingAddress = $this->getShippingAddress();
        if ($shippingAddress && method_exists($shippingAddress, 'getStreetFull')) {
            $street = $shippingAddress->getStreetFull();
            $lines = array_filter(is_array($street) ? $street : [$street]);
            $city = $shippingAddress->getCity();
            $region = $shippingAddress->getRegion();
            $postcode = $shippingAddress->getPostcode();
            $parts = array_filter(array_merge($lines, [$city, $region, $postcode]));
            return implode(', ', $parts);
        }
        return '';
    }

    /**
     * Delivery price (from extension attribute or order/quote shipping amount).
     */
    public function getDeliveryPrice(): ?float
    {
        $ext = $this->getAddressExtensionAttributes();
        if ($ext && $ext->getPigeonexpressDeliveryPrice() !== null) {
            return (float) $ext->getPigeonexpressDeliveryPrice();
        }
        $address = $this->getShippingAddress();
        if ($address && method_exists($address, 'getShippingAmount')) {
            $amount = $address->getShippingAmount();
            return $amount !== null ? (float) $amount : null;
        }
        $order = $this->getOrder();
        if ($order) {
            $amount = $order->getShippingAmount();
            return $amount !== null ? (float) $amount : null;
        }
        return null;
    }

    /**
     * Formatted delivery price for display.
     */
    public function getFormattedDeliveryPrice(): string
    {
        $price = $this->getDeliveryPrice();
        if ($price === null) {
            return '';
        }
        $order = $this->getOrder();
        $quote = $this->getQuote();
        if ($order && method_exists($order, 'formatPrice')) {
            return $order->getOrderCurrencyCode()
                ? $order->formatPrice($price)
                : (string) $price;
        }
        if ($quote && method_exists($quote, 'getQuoteCurrencyCode')) {
            return $quote->getQuoteCurrencyCode()
                ? $quote->getStore()->formatPrice($price, false)
                : (string) $price;
        }
        return (string) $price;
    }

    /**
     * Single-line summary: type + location or address + price.
     */
    public function getSummaryLine(): string
    {
        if (!$this->isPigeonExpress()) {
            return '';
        }
        $parts = [];
        $label = $this->getDeliveryTypeLabel();
        if ($label !== '') {
            $parts[] = $label;
        }
        $name = $this->getLocationName();
        $addr = $this->getLocationAddress();
        if ($name !== '' || $addr !== '') {
            $parts[] = trim($name . ' ' . $addr);
        }
        $price = $this->getFormattedDeliveryPrice();
        if ($price !== '') {
            $parts[] = $price;
        }
        return implode(' — ', $parts);
    }

    /**
     * Load existing PE shipment for the current order, or null if none.
     */
    public function getExistingShipment(): ?\PigeonExpress\Shipping\Model\Shipment
    {
        if ($this->existingShipmentLoaded) {
            return $this->cachedExistingShipment;
        }
        $this->existingShipmentLoaded = true;

        $order = $this->getOrder();
        if (!$order || !$order->getId()) {
            return null;
        }
        $collection = $this->shipmentCollectionFactory->create();
        $collection->addFieldToFilter('order_id', (int) $order->getId());
        $collection->setPageSize(1);
        /** @var \PigeonExpress\Shipping\Model\Shipment $shipment */
        $shipment = $collection->getFirstItem();
        $this->cachedExistingShipment = $shipment->getId() ? $shipment : null;
        return $this->cachedExistingShipment;
    }

    /**
     * Admin URL for the Send to Pigeon Express controller.
     */
    public function getSendUrl(): string
    {
        $order = $this->getOrder();
        if (!$order || !$order->getId()) {
            return '';
        }
        return $this->getUrl('pigeonexpress/order/send', ['order_id' => $order->getId()]);
    }

    /**
     * CSRF form key.
     */
    public function getFormKey(): string
    {
        return $this->formKey->getFormKey();
    }

    /**
     * Receiver phone from shipping address, normalized for PE API.
     */
    public function getNormalizedReceiverPhone(): string
    {
        $address = $this->getShippingAddress();
        if (!$address || !method_exists($address, 'getTelephone')) {
            return '';
        }
        $raw = (string) $address->getTelephone();
        return \PigeonExpress\Shipping\Model\ShipmentSender::normalizePhone($raw);
    }

    private function getOrder(): ?OrderInterface
    {
        if ($this->orderOrQuote instanceof OrderInterface) {
            return $this->orderOrQuote;
        }
        return null;
    }

    private function getQuote(): ?Quote
    {
        if ($this->orderOrQuote instanceof Quote) {
            return $this->orderOrQuote;
        }
        return null;
    }

    /**
     * @return \Magento\Sales\Model\Order\Address|\Magento\Quote\Model\Quote\Address|null
     */
    private function getShippingAddress()
    {
        $order = $this->getOrder();
        if ($order && method_exists($order, 'getShippingAddress')) {
            return $order->getShippingAddress();
        }
        $quote = $this->getQuote();
        if ($quote) {
            return $quote->getShippingAddress();
        }
        return null;
    }

    /**
     * Extension attributes of shipping address; for order addresses loads PE data from DB if missing
     * (addresses loaded via collection do not trigger OrderAddressLoadPlugin).
     *
     * @return \Magento\Sales\Api\Data\OrderAddressExtensionInterface|null
     */
    private function getAddressExtensionAttributes()
    {
        $address = $this->getShippingAddress();
        if (!$address) {
            return null;
        }

        // Only order addresses are persisted in pigeonexpress_order_address; ensure PE data is loaded
        if ($this->getOrder() && method_exists($address, 'getId') && $address->getId()) {
            $this->ensureOrderAddressPeData($address);
        }

        return $address->getExtensionAttributes();
    }

    /**
     * If order address has no PE data in extension attributes, load from pigeonexpress_order_address.
     *
     * @param \Magento\Sales\Model\Order\Address $address
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
