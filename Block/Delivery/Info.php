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

class Info extends Template
{
    /** @var \PigeonExpress\Shipping\Model\ShipmentCanceller */
    private $shipmentCanceller;

    /** @var array{fields: array<string, string>, issues: array<string, array{severity:string, message:string}>}|null */
    private $form;

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
        $this->shipmentCanceller = $shipmentCanceller;
        $this->addressForm = $addressForm;
        $this->dataPersistor = $dataPersistor;
        $this->shipmentSender = $shipmentSender;
        $this->whoPaysSource = $whoPaysSource;
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

        $labels = [
            'sent'                => __('Registered'),
            'shipment_registered' => __('Registered'),
            'cancelled'           => __('Cancelled'),
            'in_transit'          => __('In transit'),
            'out_for_delivery'    => __('Out for delivery'),
            'delivered'           => __('Delivered'),
            'returned'            => __('Returned'),
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
            $amount = $this->shipmentSender->codAmountFor($order);
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

            return $this->form;
        }

        try {
            $location = $this->locationPersistor->getByAddressId((int) $shippingAddress->getId()) ?? [];

            $fields = $this->addressForm->fromOrder(
                $shippingAddress,
                $this->getInstructions(),
                (string) ($this->getDeliveryType() ?? ConfigInterface::DELIVERY_TYPE_ADDRESS),
                (string) ($location['location_id'] ?? ''),
                (string) ($location['location_name'] ?? '')
            );

            $this->form = $this->addressForm->validate($fields, (int) $order->getStoreId());
        } catch (\Throwable $e) {
            $this->form['issues']['address'] = [
                'severity' => AddressForm::SEVERITY_ERROR,
                'message'  => (string) $e->getMessage(),
            ];
        }

        return $this->form;
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

        return [
            'fields' => is_array($stored['fields'] ?? null) ? $stored['fields'] : $this->addressForm->blank(),
            'issues' => is_array($stored['issues'] ?? null) ? $stored['issues'] : [],
        ];
    }

    /**
     * Value of one form field.
     */
    public function getFieldValue(string $field): string
    {
        return (string) ($this->getForm()['fields'][$field] ?? '');
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
