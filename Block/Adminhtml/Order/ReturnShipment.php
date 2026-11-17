<?php
/**
 * The "Return shipment" part of the order panel: the return shipment of the
 * order with its status and label, or the form that creates one. A child of
 * the panel block (Block\Delivery\Info), included from its template.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Block\Adminhtml\Order;

use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Registry;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Sales\Api\Data\OrderInterface;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Block\Delivery\Info;
use PigeonExpress\Shipping\Model\Environment;
use PigeonExpress\Shipping\Model\Magento\TrackingUrl;
use PigeonExpress\Shipping\Model\ReturnShipment\Availability;
use PigeonExpress\Shipping\Model\ReturnShipment\ReturnBodyBuilder;
use PigeonExpress\Shipping\Model\ReturnShipment\ReturnShipmentRepository;
use PigeonExpress\Shipping\Model\ReturnShipment\ReturnShipmentService;
use PigeonExpress\Shipping\Model\Shipment\AddressForm;
use PigeonExpress\Shipping\Model\Status\StatusCodes;
use PigeonExpress\Shipping\Model\Status\StatusNames;

class ReturnShipment extends Template
{
    public const ADMIN_RESOURCE = 'PigeonExpress_Shipping::return_shipment';

    /** Where a refused form is kept so the admin does not retype it. */
    public const FORM_STORAGE_KEY = 'pigeonexpress_return_form';

    /** @var string */
    protected $_template = 'PigeonExpress_Shipping::order/return-shipment.phtml';

    /** @var Registry */
    private $registry;

    /** @var Availability */
    private $availability;

    /** @var ReturnShipmentRepository */
    private $repository;

    /** @var ReturnBodyBuilder */
    private $builder;

    /** @var StatusNames */
    private $statusNames;

    /** @var TrackingUrl */
    private $trackingUrl;

    /** @var AuthorizationInterface */
    private $authorization;

    /** @var DataPersistorInterface */
    private $dataPersistor;

    /** @var ConfigInterface */
    private $config;

    /** @var ResourceConnection */
    private $resource;

    /** @var Environment */
    private $environment;

    /** @var array<string, mixed>|null|false */
    private $row = false;

    /** @var FormKey */
    private $formKey;

    public function __construct(
        Context $context,
        Registry $registry,
        Availability $availability,
        ReturnShipmentRepository $repository,
        ReturnBodyBuilder $builder,
        StatusNames $statusNames,
        TrackingUrl $trackingUrl,
        AuthorizationInterface $authorization,
        DataPersistorInterface $dataPersistor,
        ConfigInterface $config,
        ResourceConnection $resource,
        Environment $environment,
        FormKey $formKey,
        array $data = []
    ) {
        $this->formKey = $formKey;
        $this->registry = $registry;
        $this->availability = $availability;
        $this->repository = $repository;
        $this->builder = $builder;
        $this->statusNames = $statusNames;
        $this->trackingUrl = $trackingUrl;
        $this->authorization = $authorization;
        $this->dataPersistor = $dataPersistor;
        $this->config = $config;
        $this->resource = $resource;
        $this->environment = $environment;
        parent::__construct($context, $data);
    }

    public function getOrder(): ?OrderInterface
    {
        $order = $this->registry->registry('current_order');

        return $order instanceof OrderInterface && $order->getEntityId() ? $order : null;
    }

    /**
     * The live return shipment of the order, or null.
     *
     * @return array<string, mixed>|null
     */
    public function getReturn(): ?array
    {
        if ($this->row === false) {
            $order = $this->getOrder();
            $this->row = $order ? $this->repository->findActive((int) $order->getEntityId()) : null;
        }

        return $this->row;
    }

    /**
     * Shown: the return there is, or the form for an order with a sent waybill.
     */
    public function isShown(): bool
    {
        if (!$this->authorization->isAllowed(self::ADMIN_RESOURCE) || $this->getOrder() === null) {
            return false;
        }

        return $this->getReturn() !== null || $this->isFormOffered();
    }

    public function isFormOffered(): bool
    {
        $parent = $this->getParentBlock();

        return $this->getReturn() === null
            && $this->availability->isOffered()
            && $parent instanceof Info
            && $parent->hasActiveShipment();
    }

    /** Whether the form opens straight away: it was refused and handed back. */
    public function isFormOpen(): bool
    {
        return $this->submitted() !== null;
    }

    /**
     * What the form starts on: the customer, collected from where the order
     * was delivered, delivered to the shop as Send From says, the receiver
     * paying, the customer told. A refused form comes back as typed.
     *
     * @return array<string, mixed>
     */
    public function getPrefill(): array
    {
        $order = $this->getOrder();
        $storeId = $order ? (int) $order->getStoreId() : 0;
        $sent = $this->forwardForm();
        $type = (string) ($sent['delivery_type'] ?? '');
        $pickup = $type === ConfigInterface::DELIVERY_TYPE_APS ? ReturnBodyBuilder::PICKUP_LOCKER
            : ($type === ConfigInterface::DELIVERY_TYPE_OFFICE ? ReturnBodyBuilder::PICKUP_OFFICE : ReturnBodyBuilder::PICKUP_ADDRESS);
        $delivery = $this->builder->defaultDelivery($storeId);

        $info = trim((string) ($sent['street_number'] ?? ''));
        foreach (['building_number' => 'бл.', 'entrance' => 'вх.', 'floor' => 'ет.', 'apartment' => 'ап.'] as $key => $prefix) {
            $value = trim((string) ($sent[$key] ?? ''));
            if ($value !== '') {
                $info .= ($info !== '' ? ', ' : '') . $prefix . ' ' . $value;
            }
        }
        if ((string) ($sent['street_id'] ?? '') === '' && trim((string) ($sent['street'] ?? '')) !== '') {
            $info = trim((string) $sent['street'] . ' ' . $info);
        }

        $packages = [];
        foreach ((array) ($sent['packages'] ?? []) as $row) {
            if (is_array($row)) {
                $packages[] = [
                    'weight' => (string) ($row['weight'] ?? ''),
                    'length' => (string) ($row['length'] ?? ''),
                    'width'  => (string) ($row['width'] ?? ''),
                    'height' => (string) ($row['height'] ?? ''),
                ];
            }
        }
        if ($packages === []) {
            $packages[] = [
                'weight' => $this->trim($this->config->getDefaultWeight($storeId)),
                'length' => $this->trim($this->config->getDefaultLength($storeId)),
                'width'  => $this->trim($this->config->getDefaultWidth($storeId)),
                'height' => $this->trim($this->config->getDefaultHeight($storeId)),
            ];
        }

        $locationId = (string) ($sent['location_id'] ?? '');
        $locationLabel = (string) ($sent['location'] ?? '');
        $prefill = [
            'sender_name'           => (string) ($sent['contact_name'] ?? ''),
            'sender_phone'          => (string) ($sent['contact_phone'] ?? ''),
            'sender_email'          => $order ? (string) $order->getCustomerEmail() : '',
            'pickup_type'           => $pickup,
            'city_id'               => (string) ($sent['city_id'] ?? ''),
            'city_label'            => (string) ($sent['city'] ?? ''),
            'street_id'             => (string) ($sent['street_id'] ?? ''),
            'street_label'          => (string) ($sent['street_id'] ?? '') !== '' ? (string) ($sent['street'] ?? '') : '',
            'additional_info'       => $info,
            'pickup_office_id'      => $pickup === ReturnBodyBuilder::PICKUP_OFFICE ? $locationId : '',
            'pickup_office_label'   => $pickup === ReturnBodyBuilder::PICKUP_OFFICE ? $locationLabel : '',
            'pickup_locker_id'      => $pickup === ReturnBodyBuilder::PICKUP_LOCKER ? $locationId : '',
            'pickup_locker_label'   => $pickup === ReturnBodyBuilder::PICKUP_LOCKER ? $locationLabel : '',
            'delivery_type'         => $delivery['type'],
            'delivery_office_id'    => $delivery['office_id'],
            'delivery_office_label' => $this->officeLabel($delivery['office_id']),
            'packages'              => $packages,
            'who_pays'              => ConfigInterface::WHO_PAYS_RECEIVER,
            'note'                  => '',
            'notify_customer'       => true,
        ];

        $submitted = $this->submitted();
        if ($submitted !== null) {
            $prefill = array_merge($prefill, $submitted);
            foreach ($prefill['packages'] as $index => $row) {
                $prefill['packages'][$index] = array_map('strval', (array) $row);
            }
        }

        return $prefill;
    }

    /**
     * The form of the order's own live shipment, as sent.
     *
     * @return array<string, mixed>
     */
    private function forwardForm(): array
    {
        $parent = $this->getParentBlock();
        $shipment = $parent instanceof Info ? $parent->getExistingShipment() : null;
        $decoded = $shipment !== null ? json_decode((string) $shipment->getFormFields(), true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function submitted(): ?array
    {
        $kept = $this->dataPersistor->get(self::FORM_STORAGE_KEY);
        $order = $this->getOrder();
        if (!is_array($kept) || $order === null || (int) ($kept['order_id'] ?? 0) !== (int) $order->getEntityId()) {
            return null;
        }

        return is_array($kept['form'] ?? null) ? $kept['form'] : null;
    }

    /**
     * The shop's address the return goes to, as the merchant reads it, or ''
     * when the account's default address applies.
     */
    public function getShopAddressText(): string
    {
        $order = $this->getOrder();
        $storeId = $order ? (int) $order->getStoreId() : 0;
        $fields = $this->builder->shopAddressFields($storeId);
        if (isset($fields['delivery_address_id'])) {
            return (string) __('Address %1', (string) $fields['delivery_address_id']);
        }
        if (!isset($fields['delivery_address'])) {
            return '';
        }

        return implode(', ', array_filter([
            trim((string) $this->config->getPickupAddressCityLabel($storeId)),
            trim($this->config->getPickupAddressStreetLabel($storeId) . ' ' . $this->config->getPickupAddressStreetNumber($storeId)),
        ]));
    }

    public function officeLabel(string $id): string
    {
        if ((int) $id <= 0) {
            return '';
        }
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->resource->getTableName('pigeonexpress_office'), ['name', 'address', 'city'])
                ->where('api_id = ?', (int) $id)
                ->where('environment = ?', $this->environment->current())
                ->limit(1)
        );
        if (!$row) {
            return (string) __('Office %1', $id);
        }

        return implode(' - ', array_filter([(string) $row['name'], (string) $row['address'], (string) $row['city']]));
    }

    /**
     * @return array{code:string, label:string, tone:string, checked:string}
     */
    public function getStatus(): array
    {
        $row = $this->getReturn() ?? [];
        $code = (string) ($row['status_code'] ?? '');

        return [
            'code'    => $code,
            'label'   => $code !== '' ? $this->statusNames->label($code, (string) ($row['status_name'] ?? '')) : '',
            'tone'    => $code !== '' ? StatusCodes::tone($code) : '',
            'checked' => $this->formatUtc((string) ($row['status_checked_at'] ?? '')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getSent(): array
    {
        $row = $this->getReturn();

        return is_array($row['sent_json'] ?? null) ? $row['sent_json'] : [];
    }

    public function getPickupText(): string
    {
        return ReturnShipmentService::pickupText($this->getSent());
    }

    public function getDeliveryText(): string
    {
        $sent = $this->getSent();
        if (($sent['delivery_type'] ?? '') === ReturnBodyBuilder::DELIVERY_ADDRESS) {
            $shop = $this->getShopAddressText();
            if ($shop !== '') {
                return (string) __('Shop address: %1', $shop);
            }
            $sent['delivery_label'] = '';
        }

        return ReturnShipmentService::deliveryText($sent);
    }

    /** What Pigeon Express charged, '' until the details were read. */
    public function getChargedText(): string
    {
        $row = $this->getReturn() ?? [];
        $details = is_array($row['details_json'] ?? null) ? $row['details_json'] : [];
        if (!isset($details['total']) || $details['total'] === null) {
            return '';
        }
        $order = $this->getOrder();

        return $order instanceof \Magento\Sales\Model\Order
            ? (string) $order->formatPriceTxt((float) $details['total'])
            : number_format((float) $details['total'], 2);
    }

    public function getTrackingUrl(): string
    {
        $row = $this->getReturn() ?? [];
        $number = (string) ($row['tracking_number'] ?? '') ?: (string) ($row['reference_number'] ?? '');

        return $number !== '' ? $this->trackingUrl->forNumber($number) : '';
    }

    public function actionUrl(string $action, array $params = []): string
    {
        $order = $this->getOrder();

        return $order
            ? $this->getUrl('pigeonexpress/reverse/' . $action, $params + ['order_id' => (int) $order->getEntityId()])
            : '';
    }

    public function getCitySearchUrl(): string
    {
        return $this->getUrl('pigeonexpress/order/citysearch');
    }

    public function getStreetSearchUrl(): string
    {
        $order = $this->getOrder();

        return $this->getUrl('pigeonexpress/order/streetsearch', ['order_id' => $order ? (int) $order->getEntityId() : 0]);
    }

    public function getLocationSearchUrl(string $type): string
    {
        $order = $this->getOrder();

        return $this->getUrl('pigeonexpress/order/locationsearch', [
            'type'     => $type,
            'order_id' => $order ? (int) $order->getEntityId() : 0,
        ]);
    }

    public function getNoteMaxLength(): int
    {
        return AddressForm::NOTE_MAX_LENGTH;
    }

    private function trim(float $value): string
    {
        return $value > 0 ? rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') : '';
    }

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

    public function getFormKey(): string
    {
        return $this->formKey->getFormKey();
    }
}
