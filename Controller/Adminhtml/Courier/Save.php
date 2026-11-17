<?php
/**
 * Send a new courier request from the form.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Controller\Adminhtml\Courier;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use PigeonExpress\Shipping\Model\Courier\CourierRequestService;
use Psr\Log\LoggerInterface;

class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'PigeonExpress_Shipping::courier_request_create';

    /** Where a refused form is kept so the admin does not retype it. */
    public const FORM_STORAGE_KEY = 'pigeonexpress_courier_form';

    /** @var CourierRequestService */
    private $service;

    /** @var DataPersistorInterface */
    private $dataPersistor;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        Context $context,
        CourierRequestService $service,
        DataPersistorInterface $dataPersistor,
        LoggerInterface $logger
    ) {
        $this->service = $service;
        $this->dataPersistor = $dataPersistor;
        $this->logger = $logger;
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $redirect = $this->resultRedirectFactory->create();
        $raw = $this->getRequest()->getParam('courier');
        $input = self::clean(is_array($raw) ? $raw : []);
        $back = (int) $this->getRequest()->getParam('back_order_id');

        try {
            $result = $this->service->create($input);
            $this->dataPersistor->clear(self::FORM_STORAGE_KEY);
            $this->messageManager->addSuccessMessage($result['message']);

            return $back > 0
                ? $redirect->setPath('sales/order/view', ['order_id' => $back])
                : $redirect->setPath('pigeonexpress/courier/index');
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('[PigeonExpress] Courier request failed: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(__('Could not reach Pigeon Express. Try again.'));
        }

        $this->dataPersistor->set(self::FORM_STORAGE_KEY, $input);

        return $redirect->setPath('pigeonexpress/courier/new', array_filter([
            'order_ids'     => implode(',', $input['orders']),
            'back_order_id' => $back > 0 ? $back : null,
        ]));
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public static function clean(array $raw): array
    {
        $text = static function (string $key) use ($raw): string {
            return isset($raw[$key]) && is_scalar($raw[$key]) ? trim(strip_tags((string) $raw[$key])) : '';
        };
        $number = static function (string $key) use ($text): float {
            $value = str_replace(',', '.', $text($key));

            return is_numeric($value) ? (float) $value : 0.0;
        };
        $orders = [];
        foreach ((isset($raw['orders']) && is_array($raw['orders']) ? $raw['orders'] : []) as $id) {
            if ((int) $id > 0) {
                $orders[] = (int) $id;
            }
        }

        $input = [
            'pickup_date'      => $text('pickup_date'),
            'time_type'        => $text('time_type'),
            'time_from'        => $text('time_from'),
            'time_to'          => $text('time_to'),
            'address_mode'     => $text('address_mode'),
            'saved_address_id' => (string) (int) $text('saved_address_id'),
            'city_id'          => (int) $text('city_id'),
            'city_label'       => $text('city_label'),
            'street_id'        => (int) $text('street_id'),
            'street_label'     => $text('street_label'),
            'street_number'    => $text('street_number'),
            'address_info'     => $text('address_info'),
            'contact_name'     => $text('contact_name'),
            'contact_phone'    => $text('contact_phone'),
            'contact_email'    => $text('contact_email'),
            'company_name'     => $text('company_name'),
            'orders'           => array_values(array_unique($orders)),
            'package_type'     => $text('package_type'),
            'weight'           => $number('weight'),
            'length'           => $number('length'),
            'width'            => $number('width'),
            'height'           => $number('height'),
            'instructions'     => $text('instructions'),
        ];
        if ($input['address_mode'] !== CourierRequestService::ADDRESS_SAVED) {
            $input['saved_address_id'] = '';
        }

        return $input;
    }
}
