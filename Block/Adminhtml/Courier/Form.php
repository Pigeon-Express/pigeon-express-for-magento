<?php
/**
 * The form of a new courier request: filled from the "Courier request"
 * settings, Send From and the selected orders, or as it was last sent when
 * Pigeon Express refused it.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Block\Adminhtml\Courier;

use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Controller\Adminhtml\Courier\Save;
use PigeonExpress\Shipping\Model\Account\SavedAddresses;
use PigeonExpress\Shipping\Model\Courier\CourierRequestService;
use PigeonExpress\Shipping\Model\Courier\CourierSettings;
use PigeonExpress\Shipping\Model\Courier\PickupDate;

class Form extends Template
{
    /** @var string */
    protected $_template = 'PigeonExpress_Shipping::courier/form.phtml';

    /** @var CourierRequestService */
    private $service;

    /** @var CourierSettings */
    private $settings;

    /** @var ConfigInterface */
    private $config;

    /** @var SavedAddresses */
    private $savedAddresses;

    /** @var DataPersistorInterface */
    private $dataPersistor;

    /** @var array<string, mixed>|null */
    private $data;

    /** @var FormKey */
    private $formKey;

    public function __construct(
        Context $context,
        CourierRequestService $service,
        CourierSettings $settings,
        ConfigInterface $config,
        SavedAddresses $savedAddresses,
        DataPersistorInterface $dataPersistor,
        FormKey $formKey,
        array $data = []
    ) {
        $this->formKey = $formKey;
        $this->service = $service;
        $this->settings = $settings;
        $this->config = $config;
        $this->savedAddresses = $savedAddresses;
        $this->dataPersistor = $dataPersistor;
        parent::__construct($context, $data);
    }

    public function isAvailable(): bool
    {
        return $this->settings->isAvailable();
    }

    public function getUnavailableText(): string
    {
        return (string) CourierRequestService::unavailableText();
    }

    /**
     * @return int[]
     */
    public function getOrderIds(): array
    {
        $raw = (string) $this->getRequest()->getParam('order_ids', '');

        return array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)))));
    }

    public function getBackOrderId(): int
    {
        return (int) $this->getRequest()->getParam('back_order_id');
    }

    /**
     * Everything the form starts with.
     *
     * @return array<string, mixed>
     */
    public function getFormData(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        $collected = $this->service->collect($this->getOrderIds());
        $proposal = PickupDate::propose($this->service->now(), $this->settings->cutoff());
        $saved = $this->savedAddresses->all();
        $savedId = (int) $this->config->getPickupSavedAddressId();
        if (!isset($saved[$savedId])) {
            $savedId = $saved !== [] ? (int) array_key_first($saved) : 0;
        }
        $contact = $this->settings->contact();

        $values = [
            'pickup_date'      => $proposal['date'],
            'time_type'        => CourierRequestService::TIME_INTERVAL,
            'time_from'        => $this->settings->timeFrom(),
            'time_to'          => $this->settings->timeTo(),
            'address_mode'     => $this->config->getPickupType() === ConfigInterface::PICKUP_TYPE_SAVED_ADDRESS && $savedId > 0
                ? CourierRequestService::ADDRESS_SAVED : CourierRequestService::ADDRESS_OTHER,
            'saved_address_id' => (string) $savedId,
            'city_id'          => (string) $this->config->getPickupAddressCityId(),
            'city_label'       => (string) $this->config->getPickupAddressCityLabel(),
            'street_id'        => (string) $this->config->getPickupAddressStreetId(),
            'street_label'     => (string) $this->config->getPickupAddressStreetLabel(),
            'street_number'    => (string) $this->config->getPickupAddressStreetNumber(),
            'address_info'     => '',
            'contact_name'     => $contact['name'],
            'contact_phone'    => $contact['phone'],
            'contact_email'    => $contact['email'],
            'company_name'     => '',
            'package_type'     => $this->settings->packageType(),
            'weight'           => $collected['weight'] > 0 ? (string) $collected['weight'] : '',
            'length'           => '',
            'width'            => '',
            'height'           => '',
            'instructions'     => $this->settings->instructions(),
        ];

        $kept = $this->dataPersistor->get(Save::FORM_STORAGE_KEY);
        if (is_array($kept)) {
            $this->dataPersistor->clear(Save::FORM_STORAGE_KEY);
            foreach ($values as $key => $value) {
                if (array_key_exists($key, $kept) && is_scalar($kept[$key])) {
                    $values[$key] = (string) $kept[$key];
                }
            }
            foreach (['weight', 'length', 'width', 'height'] as $key) {
                if ($values[$key] === '0') {
                    $values[$key] = '';
                }
            }
        }

        return $this->data = [
            'values'    => $values,
            'proposal'  => $proposal,
            'saved'     => array_map(static function (array $entry): string {
                return (string) $entry['label'];
            }, $saved),
            'shipments' => $collected['shipments'],
            'skipped'   => $collected['skipped'],
        ];
    }

    /**
     * How long same-day requests stay open, or why the date is the next day.
     */
    public function getProposalText(): string
    {
        $proposal = $this->getFormData()['proposal'];
        if ($proposal['today_open']) {
            $left = (int) $proposal['minutes_left'];
            $time = $left >= 60
                ? (string) __('%1 h %2 min', intdiv($left, 60), $left % 60)
                : (string) __('%1 min', $left);

            return (string) __('today until %1 · %2 left', $proposal['cutoff'], $time);
        }

        return $proposal['today_working']
            ? (string) __('Requests for today close at %1. The next working day is proposed.', $proposal['cutoff'])
            : (string) __('Not a working day. The next working day is proposed.');
    }

    /**
     * @return array<string, string>
     */
    public function getPackageTypes(): array
    {
        return array_map('strval', CourierSettings::packageTypeLabels());
    }

    public function getSaveUrl(): string
    {
        return $this->getUrl('pigeonexpress/courier/save');
    }

    public function getOrderUrl(int $orderId): string
    {
        return $this->getUrl('sales/order/view', ['order_id' => $orderId]);
    }

    public function getCitySearchUrl(): string
    {
        return $this->getUrl('pigeonexpress/order/citysearch');
    }

    public function getStreetSearchUrl(): string
    {
        return $this->getUrl('pigeonexpress/order/streetsearch');
    }

    public function getListUrl(): string
    {
        return $this->getUrl('pigeonexpress/courier/index');
    }

    public function getFormKey(): string
    {
        return $this->formKey->getFormKey();
    }
}
