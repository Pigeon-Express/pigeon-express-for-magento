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
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Sales\Api\Data\OrderAddressExtensionInterfaceFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Quote\Model\Quote;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Api\ShipmentSenderInterface;
use PigeonExpress\Shipping\Controller\Adminhtml\Order\Send as SendController;
use PigeonExpress\Shipping\Model\OrderAddressLocationPersistor;
use PigeonExpress\Shipping\Model\ResourceModel\Shipment\CollectionFactory as ShipmentCollectionFactory;
use PigeonExpress\Shipping\Model\Config\Source\WhoPays;
use PigeonExpress\Shipping\Model\Shipment\AddressForm;
use PigeonExpress\Shipping\Model\Shipment\HandInText;
use PigeonExpress\Shipping\Model\Shipment\WhoPaysFacts;
use PigeonExpress\Shipping\Model\AdditionalServiceRepository;
use Magento\Framework\AuthorizationInterface;
use PigeonExpress\Shipping\Block\Adminhtml\Order\Charges;
use PigeonExpress\Shipping\Controller\Adminhtml\Order\Edit as EditController;
use PigeonExpress\Shipping\Model\ShipmentEditor;
use PigeonExpress\Shipping\Model\Status\StatusCodes;
use PigeonExpress\Shipping\Model\Status\StatusNames;

class Info extends Template
{
    /** @var ShipmentEditor */
    private $shipmentEditor;

    /** @var StatusNames */
    private $statusNames;

    /** @var AuthorizationInterface */
    private $authorization;

    /** @var \PigeonExpress\Shipping\Model\ShipmentCanceller */
    private $shipmentCanceller;

    /** @var array{fields: array<string, mixed>, issues: array<string, array{severity:string, message:string}>}|null */
    private $form;

    /** @var WhoPaysFacts */
    private $whoPaysFacts;

    /** @var HandInText */
    private $handInText;

    /** @var AdditionalServiceRepository */
    private $serviceRepository;

    /** @var AddressForm */
    private $addressForm;

    /** @var DataPersistorInterface */
    private $dataPersistor;

    /** @var ShipmentSenderInterface */
    private $shipmentSender;

    /** @var array<int, array<string, float>>|null */
    private $packages;

    /** @var string */
    private $packageProblem = '';

    /** @var WhoPays */
    private $whoPaysSource;

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

    /** @var bool|null Memoised: the answer costs a query and the form asks twice. */
    private $freeShipping;

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
        \PigeonExpress\Shipping\Model\ShipmentCanceller $shipmentCanceller,
        AddressForm $addressForm,
        DataPersistorInterface $dataPersistor,
        ShipmentSenderInterface $shipmentSender,
        WhoPays $whoPaysSource,
        WhoPaysFacts $whoPaysFacts,
        HandInText $handInText,
        AdditionalServiceRepository $serviceRepository,
        ShipmentEditor $shipmentEditor,
        StatusNames $statusNames,
        AuthorizationInterface $authorization,
        array $data = []
    ) {
        $this->shipmentEditor = $shipmentEditor;
        $this->statusNames = $statusNames;
        $this->authorization = $authorization;
        $this->config = $config;
        $this->checkoutSession = $checkoutSession;
        $this->registry = $registry;
        $this->locationPersistor = $locationPersistor;
        $this->orderAddressExtensionFactory = $orderAddressExtensionFactory;
        $this->shipmentCollectionFactory = $shipmentCollectionFactory;
        $this->formKey = $formKey;
        parent::__construct($context, $data);
        $this->shipmentCanceller = $shipmentCanceller;
        $this->addressForm = $addressForm;
        $this->dataPersistor = $dataPersistor;
        $this->shipmentSender = $shipmentSender;
        $this->whoPaysSource = $whoPaysSource;
        $this->whoPaysFacts = $whoPaysFacts;
        $this->handInText = $handInText;
        $this->serviceRepository = $serviceRepository;
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
        return $this->formatMoney($this->getDeliveryPrice());
    }

    /**
     * What the merchant is charged for this delivery.
     *
     * Pigeon Express bills one side or the other, never both, so the side that
     * is not billed reads zero rather than being left out. A row that vanishes
     * looks like missing data, and seeing both together is the point: which way
     * round it is decides who is out of pocket.
     */
    public function getFormattedSenderFee(): string
    {
        return $this->formatMoney($this->feeForSide(ConfigInterface::WHO_PAYS_SENDER));
    }

    /**
     * What the courier collects from the recipient on delivery.
     */
    public function getFormattedReceiverFee(): string
    {
        return $this->formatMoney($this->feeForSide(ConfigInterface::WHO_PAYS_RECEIVER));
    }

    /**
     * Whether the merchant's own cost for this delivery is known without asking.
     */
    public function isSenderFeeKnown(): bool
    {
        return $this->isFeeKnownFor(ConfigInterface::WHO_PAYS_SENDER);
    }

    /**
     * Whether what the courier collects is known without asking.
     */
    public function isReceiverFeeKnown(): bool
    {
        return $this->isFeeKnownFor(ConfigInterface::WHO_PAYS_RECEIVER);
    }

    /**
     * A billed side with nothing charged has not been priced, it is not free.
     *
     * The only figure the order carries is what the customer paid. On a free
     * delivery that is zero while the carrier still invoices the merchant, and
     * the module never asked what for: a delivery given away is quoted without
     * calling the API, there being no price to show the customer. Printing 0.00
     * there would state a cost that is about to arrive on an invoice. The side
     * that is not billed is a different matter: zero is simply true.
     */
    private function isFeeKnownFor(string $side): bool
    {
        $price = $this->getDeliveryPrice();
        if ($price === null) {
            return false;
        }

        if ($this->getFieldValue('who_pays') !== $side) {
            return true;
        }

        return $price > 0.0;
    }

    /**
     * The delivery price if this side is the one being billed, otherwise zero.
     *
     * Read from the form rather than from the store setting, so it follows what
     * the admin has actually chosen for this shipment, including the switch to
     * the sender that a free delivery makes on their behalf.
     */
    private function feeForSide(string $side): ?float
    {
        $price = $this->getDeliveryPrice();
        if ($price === null) {
            return null;
        }

        return $this->getFieldValue('who_pays') === $side ? $price : 0.0;
    }

    /**
     * In the order's currency where there is one, and the store's otherwise.
     */
    private function formatMoney(?float $price): string
    {
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
        return implode(', ', $parts);
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
        // Newest first. An order can hold several rows once a cancelled waybill
        // has been replaced, and the current one is what the admin must act on.
        $collection->setOrder('entity_id', 'DESC');
        $collection->setPageSize(1);
        /** @var \PigeonExpress\Shipping\Model\Shipment $shipment */
        $shipment = $collection->getFirstItem();
        $this->cachedExistingShipment = $shipment->getId() ? $shipment : null;
        return $this->cachedExistingShipment;
    }

    /**
     * Where the parcel is, as Pigeon Express last said, for the status row.
     *
     * @return array{code:string, label:string, date:string, checked:string, tone:string}
     */
    public function getStatusSummary(): array
    {
        $shipment = $this->getExistingShipment();
        $code = $shipment !== null ? $shipment->getStatusCode() : '';

        return [
            'code'    => $code,
            'label'   => $code !== '' ? $this->statusNames->label($code, $shipment->getStatusName()) : '',
            'date'    => $code !== '' ? $this->formatUtc((string) $shipment->getStatusAt()) : '',
            'checked' => $shipment !== null ? $this->formatUtc((string) $shipment->getStatusCheckedAt()) : '',
            'tone'    => $code !== '' ? StatusCodes::tone($code) : '',
        ];
    }

    /**
     * A UTC "Y-m-d H:i:s" in the admin's time zone and locale, '' for none.
     */
    private function formatUtc(string $utc): string
    {
        if ($utc === '') {
            return '';
        }

        try {
            return (string) $this->_localeDate->formatDateTime(
                new \DateTime($utc, new \DateTimeZone('UTC')),
                \IntlDateFormatter::MEDIUM,
                \IntlDateFormatter::SHORT
            );
        } catch (\Throwable $e) {
            return $utc;
        }
    }

    public function isAllowed(string $resource): bool
    {
        try {
            return $this->authorization->isAllowed($resource);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function getRefreshUrl(): string
    {
        $order = $this->getOrder();

        return $order && $order->getId()
            ? $this->getUrl('pigeonexpress/order/refresh', ['order_id' => $order->getId()])
            : '';
    }

    public function getDetailsUrl(): string
    {
        $order = $this->getOrder();

        return $order && $order->getId()
            ? $this->getUrl('pigeonexpress/order/details', ['order_id' => $order->getId()])
            : '';
    }

    /** The order page with the shipment form open on the existing shipment. */
    public function getEditUrl(): string
    {
        $order = $this->getOrder();

        return $order && $order->getId()
            ? $this->getUrl('sales/order/view', ['order_id' => $order->getId(), EditController::EDIT_PARAM => 1])
            : '';
    }

    public function getEditSubmitUrl(): string
    {
        $order = $this->getOrder();

        return $order && $order->getId()
            ? $this->getUrl('pigeonexpress/order/edit', ['order_id' => $order->getId()])
            : '';
    }

    public function getOrderViewUrl(): string
    {
        $order = $this->getOrder();

        return $order && $order->getId() ? $this->getUrl('sales/order/view', ['order_id' => $order->getId()]) : '';
    }

    /**
     * Why the live shipment cannot be changed, '' when it can.
     */
    public function getEditBlockReason(): string
    {
        return $this->shipmentEditor->editBlockReason($this->getExistingShipment());
    }

    /**
     * Whether the shipment form is open on the existing shipment, to change it.
     */
    public function isEditing(): bool
    {
        return (bool) $this->getRequest()->getParam(EditController::EDIT_PARAM)
            && $this->hasActiveShipment()
            && $this->getEditBlockReason() === ''
            && $this->isAllowed(EditController::ADMIN_RESOURCE);
    }

    /**
     * Whether the shipment form is shown: to create a shipment, or to change one.
     */
    public function isFormShown(): bool
    {
        return ($this->getSendUrl() !== '' && !$this->hasActiveShipment()) || $this->isEditing();
    }

    /** The URL the shipment form posts to. */
    public function getFormActionUrl(): string
    {
        return $this->isEditing() ? $this->getEditSubmitUrl() : $this->getSendUrl();
    }

    /**
     * The "Pigeon Express charges" box for the live shipment, '' before the
     * details were first read.
     */
    public function getChargesHtml(): string
    {
        $shipment = $this->getExistingShipment();
        $order = $this->getOrder();
        if ($shipment === null || !$order) {
            return '';
        }

        /** @var Charges $charges */
        $charges = $this->getLayout()->createBlock(Charges::class);
        $charges->setShipment($shipment)->setOrder($order);

        return (string) $charges->toHtml();
    }

    /** Whether the live shipment has its charges stored. */
    public function hasCharges(): bool
    {
        $shipment = $this->getExistingShipment();

        return $shipment !== null && $shipment->getDetails() !== null;
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

    /**
     * URL that cancels the Pigeon Express shipment for this order.
     */
    public function getCancelUrl(): string
    {
        $order = $this->getOrder();
        if (!$order || !$order->getId()) {
            return '';
        }

        return $this->getUrl('pigeonexpress/order/cancel', ['order_id' => $order->getId()]);
    }

    /**
     * URL that streams the label the API returns by default, rendered inline.
     *
     * The browser's own PDF viewer handles printing and saving, so the admin
     * needs one link rather than a menu of formats. The controller still accepts
     * a `format` parameter for the other sizes and for raw ZPL, should a
     * merchant with a Zebra printer need it.
     */
    public function getLabelUrl(): string
    {
        $order = $this->getOrder();
        if (!$order || !$order->getId()) {
            return '';
        }

        return $this->getUrl('pigeonexpress/order/label', ['order_id' => $order->getId()]);
    }

    /**
     * The label formats the carrier can produce, for the download menu.
     *
     * Preview stays a single link to whatever Pigeon Express returns by default,
     * because that is the one a merchant prints day to day. The rest are here for
     * the merchant who has an A4 printer or a Zebra, and are downloads rather
     * than previews: ZPL is a printer program, not something to look at.
     *
     * @return array<int, array{code:string, label:string, url:string}>
     */
    public function getLabelFormats(): array
    {
        $order = $this->getOrder();
        if (!$order || !$order->getId()) {
            return [];
        }

        $formats = [
            'default' => __('Default, as Pigeon Express returns it'),
            'pdf150'  => __('Thermal, 100 x 150 mm'),
            'a4'      => __('A4 sheet'),
            'zpl300'  => __('Zebra ZPL, 300 dpi'),
        ];

        $rows = [];
        foreach ($formats as $code => $label) {
            $rows[] = [
                'code'  => $code,
                'label' => (string) $label,
                'url'   => $this->getUrl('pigeonexpress/order/label', [
                    'order_id' => $order->getId(),
                    'format'   => $code,
                    'download' => 1,
                ]),
            ];
        }

        return $rows;
    }

    /**
     * Whether the shipment is still early enough for Pigeon Express to cancel it.
     */
    public function isShipmentCancellable(): bool
    {
        $shipment = $this->getExistingShipment();

        return $shipment !== null && $this->shipmentCanceller->isCancellable($shipment);
    }

    /**
     * Current shipment status, for display.
     */
    public function getShipmentStatus(): string
    {
        $shipment = $this->getExistingShipment();

        return $shipment !== null ? (string) $shipment->getStatus() : '';
    }

    /**
     * Whether the order currently has a live waybill.
     *
     * A cancelled shipment is history, not something in transit, so the order is
     * treated as unshipped and can be sent again.
     */
    public function hasActiveShipment(): bool
    {
        $shipment = $this->getExistingShipment();

        return $shipment !== null
            && (string) $shipment->getStatus() !== \PigeonExpress\Shipping\Model\ShipmentCanceller::STATUS_CANCELLED;
    }

    /**
     * Every shipment ever created for this order, newest first.
     *
     * Shown so a cancelled waybill and its replacement are both visible, rather
     * than the earlier attempt silently disappearing.
     *
     * @return \PigeonExpress\Shipping\Model\Shipment[]
     */
    public function getShipmentHistory(): array
    {
        $order = $this->getOrder();
        if (!$order || !$order->getId()) {
            return [];
        }

        $collection = $this->shipmentCollectionFactory->create();
        $collection->addFieldToFilter('order_id', (int) $order->getId());
        $collection->setOrder('entity_id', 'DESC');

        return array_values($collection->getItems());
    }

    /**
     * Whether a shipment row represents a cancelled waybill.
     *
     * @param \PigeonExpress\Shipping\Model\Shipment $shipment
     */
    public function isCancelled($shipment): bool
    {
        return (string) $shipment->getStatus() === \PigeonExpress\Shipping\Model\ShipmentCanceller::STATUS_CANCELLED;
    }

    /**
     * A shipment status in words rather than in the carrier's own vocabulary.
     *
     * An unknown status is shown rather than hidden: a new one from the carrier
     * should read as slightly untidy, not as nothing at all.
     *
     * @param \PigeonExpress\Shipping\Model\Shipment $shipment
     */
    public function getStatusLabel($shipment): string
    {
        $status = (string) $shipment->getStatus();

        // The carrier's status once it has been read, unless the waybill was
        // cancelled here.
        $code = (string) $shipment->getStatusCode();
        if ($code !== '' && $status !== \PigeonExpress\Shipping\Model\ShipmentCanceller::STATUS_CANCELLED) {
            return $this->statusNames->label($code, (string) $shipment->getStatusName());
        }

        $labels = [
            'sent'                => __('Shipment registered'),
            'shipment_registered' => __('Shipment registered'),
            'cancelled'           => __('Cancelled'),
            'in_transit'          => __('In transit'),
            'out_for_delivery'    => __('Out for delivery'),
            'delivered'           => __('Delivered'),
            'returned'            => __('Returned to the sender'),
        ];

        if (isset($labels[$status])) {
            return (string) $labels[$status];
        }

        return $status !== '' ? ucfirst(str_replace('_', ' ', $status)) : '';
    }

    /**
     * Whether the form currently asks for a street address.
     *
     * This follows the form, not the order: an admin who switches the parcel to
     * an office needs the address fields to go away with it.
     */
    public function isAddressDelivery(): bool
    {
        return $this->addressForm->isAddressDelivery($this->getForm()['fields']);
    }

    /**
     * Where the parcel can be sent, for the form's delivery type control.
     *
     * All three are offered whatever the customer chose, because the point of
     * the control is to redirect a parcel when they ring up and ask.
     *
     * @return array<int, array{value:string, label:string}>
     */
    public function getDeliveryTypeOptions(): array
    {
        $options = [];

        foreach ([
            ConfigInterface::DELIVERY_TYPE_ADDRESS,
            ConfigInterface::DELIVERY_TYPE_OFFICE,
            ConfigInterface::DELIVERY_TYPE_APS,
        ] as $type) {
            $title = (string) $this->config->getDeliveryTypeTitle($type);
            $options[] = [
                'value' => $type,
                'label' => $title !== '' ? $title : ucfirst($type),
            ];
        }

        return $options;
    }

    /**
     * The parcels this order becomes, as the waybill will describe them.
     *
     * @return array<int, array{weight:float, length:float, width:float, height:float}>
     */
    private function getPackages(): array
    {
        if ($this->packages !== null) {
            return $this->packages;
        }

        $this->packages = [];
        $order = $this->getOrder();
        if (!$order || !$order->getId()) {
            return $this->packages;
        }

        try {
            $this->packages = $this->shipmentSender->packagesFor(
                $order,
                $this->addressForm->deliveryType($this->getForm()['fields'])
            );
        } catch (\PigeonExpress\Shipping\Exception\UnshippableException $e) {
            // Too heavy to carry. That is not a missing summary, it is a reason
            // the shipment will be refused, so the admin is told rather than
            // shown a panel with some rows quietly absent.
            $this->packageProblem = (string) $e->getMessage();
            $this->packages = [];
        } catch (\Throwable $e) {
            // A summary is worth less than a shipment. If the geometry cannot be
            // worked out the rows simply do not appear.
            $this->packages = [];
        }

        return $this->packages;
    }

    /**
     * Why the parcels could not be worked out, when that is worth saying.
     */
    public function getPackageProblem(): string
    {
        $this->getPackages();

        return $this->packageProblem;
    }

    /**
     * Total weight of the parcels, in the unit Pigeon Express works in.
     */
    public function getPackageWeight(): string
    {
        $total = 0.0;
        foreach ($this->getPackages() as $package) {
            $total += (float) $package['weight'];
        }

        return $total > 0.0 ? number_format($total, 2) . ' ' . __('kg') : '';
    }

    /**
     * Size of each parcel. Identical parcels are described once.
     */
    public function getPackageDimensions(): string
    {
        $sizes = [];
        foreach ($this->getPackages() as $package) {
            $size = sprintf(
                '%s × %s × %s',
                $this->trimNumber((float) $package['length']),
                $this->trimNumber((float) $package['width']),
                $this->trimNumber((float) $package['height'])
            );
            $sizes[$size] = true;
        }

        return empty($sizes) ? '' : implode(', ', array_keys($sizes)) . ' ' . __('cm');
    }

    /**
     * Whole numbers read better than 30.00 on a summary line.
     */
    private function trimNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    public function getPackagePieces(): int
    {
        return count($this->getPackages());
    }

    /**
     * What the courier collects on delivery, or an empty string when nothing is.
     */
    public function getFormattedCodAmount(): string
    {
        $order = $this->getOrder();
        if (!$order || !$order->getId() || !method_exists($order, 'formatPrice')) {
            return '';
        }

        try {
            $amount = $this->shipmentSender->codAmountFor($order, $this->getFieldValue('who_pays'));
        } catch (\Throwable $e) {
            return '';
        }

        return $amount > 0.0 ? (string) $order->formatPrice($amount) : '';
    }

    /**
     * Endpoint that prices the shipment as the form currently describes it.
     */
    public function getRecalculateUrl(): string
    {
        $order = $this->getOrder();
        if (!$order || !$order->getId()) {
            return '';
        }

        return $this->getUrl('pigeonexpress/order/recalculate', ['order_id' => $order->getId()]);
    }

    /**
     * Whether this order's delivery was given away rather than merely priced at zero.
     *
     * Read from what was recorded when the order was placed, not recalculated,
     * so a threshold the merchant changes afterwards cannot rewrite the reason
     * the admin is being shown.
     */
    public function isFreeShipping(): bool
    {
        if ($this->freeShipping !== null) {
            return $this->freeShipping;
        }

        $this->freeShipping = false;

        $order = $this->getOrder();
        if (!$order) {
            return false;
        }

        // A virtual order has no shipping address, and Magento says so by
        // returning false rather than null.
        $address = $order->getShippingAddress();
        if (!$address || !$address->getId()) {
            return false;
        }

        $data = $this->locationPersistor->getByAddressId((int) $address->getId());
        $this->freeShipping = !empty($data['free_shipping']);

        return $this->freeShipping;
    }

    /**
     * Who the carrier can bill, for the form's control.
     *
     * @return array<int, array{value:string, label:string}>
     */
    public function getWhoPaysOptions(): array
    {
        $options = [];
        foreach ($this->whoPaysSource->toOptionArray() as $option) {
            $options[] = [
                'value' => (string) $option['value'],
                'label' => (string) $option['label'],
            ];
        }

        return $options;
    }

    /**
     * Endpoint the office and locker picker queries.
     */
    public function getLocationSearchUrl(): string
    {
        $order = $this->getOrder();

        return $this->getUrl(
            'pigeonexpress/order/locationsearch',
            $order && $order->getId() ? ['order_id' => $order->getId()] : []
        );
    }

    /**
     * The shipment form, filled in and checked.
     *
     * A form that was just rejected is restored exactly as the admin left it,
     * corrections and all. Otherwise it is built from the order.
     *
     * @return array{fields: array<string, string>, issues: array<string, array{severity:string, message:string}>}
     */
    public function getForm(): array
    {
        if ($this->form !== null) {
            return $this->form;
        }

        $this->form = ['fields' => $this->addressForm->blank(), 'issues' => []];

        $order = $this->getOrder();
        $shippingAddress = $this->getShippingAddress();
        if (!$order || !$order->getId() || !$shippingAddress) {
            return $this->form;
        }

        $rejected = $this->rejectedForm((int) $order->getId());
        if ($rejected !== null) {
            $this->form = $rejected;
            $this->fillPackages();

            return $this->form;
        }

        try {
            $location = $this->locationPersistor->getByAddressId((int) $shippingAddress->getId()) ?? [];

            // After a cancel, start from what the last waybill was sent with:
            // whatever the admin corrected then is still true now.
            $fields = $this->lastSentForm() ?? $this->addressForm->fromOrder(
                $shippingAddress,
                $this->getInstructions(),
                (string) ($this->getDeliveryType() ?? ConfigInterface::DELIVERY_TYPE_ADDRESS),
                (string) ($location['location_id'] ?? ''),
                (string) ($location['location_name'] ?? '')
            );

            $facts = $this->whoPaysFacts->forOrder($order);
            $this->form = $this->addressForm->validate(
                $fields,
                (int) $order->getStoreId(),
                !empty($location['free_shipping']) || !$facts['cod'],
                $facts['fixed']
            );

            $this->fillParcel();
            $this->fillPackages();
        } catch (\Throwable $e) {
            $this->form['issues']['address'] = [
                'severity' => AddressForm::SEVERITY_ERROR,
                'message'  => (string) $e->getMessage(),
            ];
        }

        return $this->form;
    }

    /**
     * Start the parcel fields from what would be sent without them.
     *
     * The admin then sees, and can correct, the very figures the waybill would
     * otherwise carry. Left as they are, they produce the same shipment.
     */
    private function fillParcel(): void
    {
        $fields = &$this->form['fields'];

        if (($fields['cod_amount'] ?? '') === '') {
            $fields['cod_amount'] = $this->formatAmount($this->getCodAmountFor($fields['who_pays'] ?? ''));
        }

    }

    /**
     * Start the package rows from what the order would be sent as.
     *
     * Rows restored from an earlier waybill, or from a form that was just
     * rejected, stay as they were. Otherwise each parcel PackageBuilder makes
     * of the order becomes a row, so left alone they produce the same shipment.
     */
    private function fillPackages(): void
    {
        if (!empty($this->form['fields']['packages']) && is_array($this->form['fields']['packages'])) {
            return;
        }

        $fragile = $this->getFragileMode() === AddressForm::FRAGILE_ALWAYS;
        $rows = [];
        foreach ($this->getPackages() as $package) {
            $rows[] = [
                'weight'      => round((float) $package['weight'], 3),
                'length'      => (int) ceil((float) $package['length']),
                'width'       => (int) ceil((float) $package['width']),
                'height'      => (int) ceil((float) $package['height']),
                'description' => '',
                'fragile'     => $fragile,
            ];
        }

        $this->form['fields']['packages'] = $rows;
    }

    /**
     * The package rows for the form, each value as it goes into its box.
     *
     * Never empty: with nothing worked out from the order there is still one
     * row, with the store's default box, for the admin to fill in.
     *
     * @return array<int, array{weight:string, length:string, width:string, height:string, description:string, fragile:bool}>
     */
    public function getPackageRows(): array
    {
        $rows = [];
        foreach ((array) ($this->getForm()['fields']['packages'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = $this->packageRow($row);
        }

        return $rows !== [] ? $rows : [$this->getEmptyPackageRow()];
    }

    /**
     * The row the "Add a package" button copies: empty weight, the default box.
     *
     * @return array{weight:string, length:string, width:string, height:string, description:string, fragile:bool}
     */
    public function getEmptyPackageRow(): array
    {
        $storeId = $this->getStoreId();

        return $this->packageRow([
            'weight'  => '',
            'length'  => (int) ceil($this->config->getDefaultLength($storeId)),
            'width'   => (int) ceil($this->config->getDefaultWidth($storeId)),
            'height'  => (int) ceil($this->config->getDefaultHeight($storeId)),
            'fragile' => $this->getFragileMode() === AddressForm::FRAGILE_ALWAYS,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{weight:string, length:string, width:string, height:string, description:string, fragile:bool}
     */
    private function packageRow(array $row): array
    {
        $text = static function ($value): string {
            if (is_float($value)) {
                return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
            }

            return is_scalar($value) ? (string) $value : '';
        };

        $mode = $this->getFragileMode();

        return [
            'weight'      => $text($row['weight'] ?? ''),
            'length'      => $text($row['length'] ?? ''),
            'width'       => $text($row['width'] ?? ''),
            'height'      => $text($row['height'] ?? ''),
            'description' => $text($row['description'] ?? ''),
            'fragile'     => $mode === AddressForm::FRAGILE_ALWAYS
                || ($mode === AddressForm::FRAGILE_PER_PACKAGE && !empty($row['fragile'])),
        ];
    }

    /**
     * never, always or per_package: whether the rows have a Fragile box, and
     * whether it can be changed.
     */
    public function getFragileMode(): string
    {
        return $this->addressForm->fragileMode($this->getStoreId());
    }

    /**
     * What a row's description box shows while empty: the label contents the
     * store sends when a row says nothing.
     */
    public function getPackageDescriptionPlaceholder(): string
    {
        $storeId = $this->getStoreId();
        if ($this->config->getLabelContentsMode($storeId) === 'fixed_text') {
            $text = trim($this->config->getLabelContentsText($storeId));
            if ($text !== '') {
                return $text;
            }
        } elseif ($this->config->getLabelContentsMode($storeId) !== '') {
            return (string) __('Product names');
        }

        return (string) __('Optional');
    }

    /**
     * Longest description a row may carry.
     */
    public function getPackageDescriptionMaxLength(): int
    {
        return AddressForm::PACKAGE_DESCRIPTION_MAX_LENGTH;
    }

    private function getStoreId(): ?int
    {
        $order = $this->getOrder();

        return $order ? (int) $order->getStoreId() : null;
    }

    // -- Services ------------------------------------------------------------

    /**
     * Whether the merchant's Pigeon Express account offers a service.
     *
     * Read from the synced catalogue. An empty catalogue means it was never
     * synced, not that nothing is offered, so then everything is shown.
     */
    private function isServiceOffered(string $code): bool
    {
        try {
            $active = $this->serviceRepository->getActiveByCode();
        } catch (\Throwable $e) {
            return true;
        }

        return $active === [] || isset($active[$code]);
    }

    public function isDeclaredValueOffered(): bool
    {
        return $this->isServiceOffered('declared_value');
    }

    public function isDeclaredValueChecked(): bool
    {
        return !empty($this->getForm()['fields']['declared_value']);
    }

    /**
     * The declared value the admin typed, as it goes into its box; empty for
     * the value of the goods.
     */
    public function getDeclaredValueAmount(): string
    {
        $amount = $this->getForm()['fields']['declared_value_amount'] ?? null;
        if (is_float($amount) || is_int($amount)) {
            return $this->formatAmount((float) $amount);
        }

        return is_string($amount) ? $amount : '';
    }

    /**
     * The value of the goods, which declared value insures when no amount is typed.
     */
    public function getGoodsValue(): string
    {
        $order = $this->getOrder();
        if (!$order) {
            return '';
        }

        // What is sent when no amount is typed: the goods value within the
        // carrier's limits.
        return $this->formatAmount(
            \PigeonExpress\Shipping\Model\Service\ServiceCodesBuilder::declaredValueDefault(
                \PigeonExpress\Shipping\Model\ShipmentSender::goodsValue($order)
            )
        );
    }

    /**
     * Inspection before payment choices the account offers. Always has None.
     *
     * @return array<string, string>
     */
    public function getReviewOptions(): array
    {
        $options = [AddressForm::REVIEW_NONE => (string) __('No inspection')];
        if ($this->isServiceOffered('shipment_review')) {
            $options[AddressForm::REVIEW_VIEW] = (string) __('Inspection only');
        }
        if ($this->isServiceOffered('shipment_test_before_payment')) {
            $options[AddressForm::REVIEW_VIEW_TEST] = (string) __('Inspection and testing');
        }

        return $options;
    }

    /**
     * Return receipt choices the account offers. Always has None.
     *
     * @return array<string, string>
     */
    public function getReceiptOptions(): array
    {
        $electronic = $this->isServiceOffered('return_receipt');
        $paper = $this->isServiceOffered('paper_return_receipt');

        $options = [AddressForm::RECEIPT_NONE => (string) __('No return receipt')];
        if ($electronic) {
            $options[AddressForm::RECEIPT_ELECTRONIC] = (string) __('Electronic');
        }
        if ($paper) {
            $options[AddressForm::RECEIPT_PAPER] = (string) __('Paper');
        }
        if ($electronic && $paper) {
            $options[AddressForm::RECEIPT_BOTH] = (string) __('Electronic and paper');
        }

        return $options;
    }

    // -- Who pays ------------------------------------------------------------

    /**
     * Which rule suggested the side the form starts on: an AddressForm::WHO_PAYS_RULE_* value.
     */
    public function getWhoPaysRule(): string
    {
        $order = $this->getOrder();
        if (!$order || !$order->getId()) {
            return AddressForm::WHO_PAYS_RULE_COD;
        }

        $facts = $this->whoPaysFacts->forOrder($order);
        // The flag recorded at checkout, as isFreeShipping() reads it.
        if ($this->isFreeShipping()) {
            return AddressForm::WHO_PAYS_RULE_FREE_SHIPPING;
        }

        return $this->addressForm->whoPaysRule($facts['cod'], false, $facts['fixed']);
    }

    /**
     * The sentence under Who pays that names the rule behind the suggestion.
     */
    public function getWhoPaysReason(): string
    {
        return $this->addressForm->whoPaysReason($this->getWhoPaysRule(), $this->getStoreId());
    }

    /**
     * Whether nothing is left to pay on the order: a bank transfer still on
     * hold is not cash on delivery, but it is not paid either.
     */
    public function isOrderPaid(): bool
    {
        $order = $this->getOrder();
        if (!$order || !$order->getId()) {
            return false;
        }

        return (float) $order->getTotalDue() <= 0.0001 && (float) $order->getTotalPaid() > 0.0;
    }

    // -- Hand-in point -------------------------------------------------------

    /**
     * Whether the shop hands its shipments in at a parcel locker, so the
     * panel says where this one goes.
     */
    public function isHandInShown(): bool
    {
        return $this->handInText->isApplicable($this->getStoreId());
    }

    /**
     * "Parcel locker X" or "Office Y (why)", for the package rows as they stand.
     */
    public function getHandInText(): string
    {
        return $this->handInText->describe($this->getForm()['fields'], $this->getStoreId());
    }

    /**
     * Endpoint that says the hand-in point again after the rows change.
     */
    public function getHandInUrl(): string
    {
        $order = $this->getOrder();
        if (!$order || !$order->getId()) {
            return '';
        }

        return $this->getUrl('pigeonexpress/order/handin', ['order_id' => $order->getId()]);
    }

    /**
     * The form the most recent waybill for this order was sent with, if any.
     *
     * Only cancelled waybills reach here, the form being hidden while one is
     * live. Ids are dropped by fromRequest() if they are not plain numbers, so
     * a stored form is held to the same standard as a submitted one.
     *
     * @return array<string, string>|null
     */
    private function lastSentForm(): ?array
    {
        foreach ($this->getShipmentHistory() as $shipment) {
            $stored = json_decode((string) $shipment->getFormFields(), true);
            if (is_array($stored)) {
                return $this->addressForm->fromRequest($stored);
            }
        }

        return null;
    }

    /**
     * What the courier would collect if the given side pays for the delivery.
     */
    public function getCodAmountFor(string $whoPays): float
    {
        $order = $this->getOrder();
        if (!$order || !$order->getId()) {
            return 0.0;
        }

        try {
            return $this->shipmentSender->codAmountFor($order, $whoPays);
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    /**
     * An amount as typed into a form field: two decimals, a point, no currency.
     */
    public function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * Whether the customer paid online, so there is nothing left to collect.
     *
     * Such an order is sent with the shop paying the carrier: the delivery was
     * part of what the customer already paid, and billing the recipient would
     * have the courier ask them for it again.
     */
    public function isPrepaid(): bool
    {
        $order = $this->getOrder();
        if (!$order || !$order->getId()) {
            return false;
        }

        return !$this->shipmentSender->isCashOnDelivery($order);
    }

    /**
     * A form the Send controller handed back, if it belongs to this order.
     *
     * @return array{fields: array<string, string>, issues: array<string, array{severity:string, message:string}>}|null
     */
    private function rejectedForm(int $orderId): ?array
    {
        $stored = $this->dataPersistor->get(SendController::FORM_STORAGE_KEY);
        if (!is_array($stored) || (int) ($stored['order_id'] ?? 0) !== $orderId) {
            return null;
        }

        $this->dataPersistor->clear(SendController::FORM_STORAGE_KEY);

        $fields = is_array($stored['fields'] ?? null) ? $stored['fields'] : $this->addressForm->blank();
        $issues = is_array($stored['issues'] ?? null) ? $stored['issues'] : [];

        // A street that has an id is settled: the id is what goes on the
        // waybill. A warning carried over from before it was set would ask the
        // admin to choose a street they have already chosen.
        if ((string) ($fields['street_id'] ?? '') !== ''
            && ($issues['street']['severity'] ?? '') === AddressForm::SEVERITY_WARNING
        ) {
            unset($issues['street']);
        }

        return ['fields' => $fields, 'issues' => $issues];
    }

    /**
     * Value of one form field.
     */
    public function getFieldValue(string $field): string
    {
        $value = $this->getForm()['fields'][$field] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * The problem with one field, or an empty string when there is none.
     */
    public function getFieldMessage(string $field): string
    {
        return (string) ($this->getForm()['issues'][$field]['message'] ?? '');
    }

    /**
     * Whether a field is wrong badly enough to stop the shipment.
     */
    public function isFieldInvalid(string $field): bool
    {
        return ($this->getForm()['issues'][$field]['severity'] ?? '') === AddressForm::SEVERITY_ERROR;
    }

    /**
     * Whether a field is usable but likely to cause a misdelivery.
     */
    public function isFieldQuestionable(string $field): bool
    {
        return ($this->getForm()['issues'][$field]['severity'] ?? '') === AddressForm::SEVERITY_WARNING;
    }

    /**
     * Whether anything on the form stops the shipment being created.
     */
    public function hasBlockingIssues(): bool
    {
        return $this->addressForm->hasErrors($this->getForm()['issues']);
    }

    /**
     * Delivery instructions the customer left at checkout.
     */
    public function getInstructions(): string
    {
        $ext = $this->getAddressExtensionAttributes();

        return $ext ? (string) ($ext->getPigeonexpressInstructions() ?? '') : '';
    }

    /**
     * Endpoint the city picker queries.
     */
    public function getCitySearchUrl(): string
    {
        return $this->getUrl('pigeonexpress/order/citysearch');
    }

    /**
     * Endpoint the street picker queries.
     */
    public function getStreetSearchUrl(): string
    {
        $order = $this->getOrder();

        return $this->getUrl(
            'pigeonexpress/order/streetsearch',
            $order && $order->getId() ? ['order_id' => $order->getId()] : []
        );
    }
}
