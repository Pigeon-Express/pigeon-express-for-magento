<?php
/**
 * Create the Pigeon Express shipment from what the admin filled in.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Sales\Api\OrderRepositoryInterface;
use PigeonExpress\Shipping\Api\ShipmentSenderInterface;
use PigeonExpress\Shipping\Model\Shipment\AddressForm;
use Psr\Log\LoggerInterface;

class Send extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'PigeonExpress_Shipping::send_shipment';

    /** Where a rejected form is kept so the admin does not retype it. */
    public const FORM_STORAGE_KEY = 'pigeonexpress_shipment_form';

    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var ShipmentSenderInterface */
    private $shipmentSender;

    /** @var AddressForm */
    private $addressForm;

    /** @var DataPersistorInterface */
    private $dataPersistor;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        Context $context,
        OrderRepositoryInterface $orderRepository,
        ShipmentSenderInterface $shipmentSender,
        AddressForm $addressForm,
        DataPersistorInterface $dataPersistor,
        LoggerInterface $logger
    ) {
        $this->orderRepository = $orderRepository;
        $this->shipmentSender = $shipmentSender;
        $this->addressForm = $addressForm;
        $this->dataPersistor = $dataPersistor;
        $this->logger = $logger;
        parent::__construct($context);
    }

    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed(self::ADMIN_RESOURCE);
    }

    public function execute(): Redirect
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $orderId = (int) $this->getRequest()->getParam('order_id');

        if ($orderId <= 0) {
            $this->messageManager->addErrorMessage(__('Invalid order ID.'));
            return $resultRedirect->setPath('sales/order/index');
        }

        try {
            $order = $this->orderRepository->get($orderId);
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            $this->messageManager->addErrorMessage(__('Order #%1 not found.', $orderId));
            return $resultRedirect->setPath('sales/order/index');
        }

        $back = $resultRedirect->setPath('sales/order/view', ['order_id' => $orderId]);

        // What the admin typed. It applies to this shipment only and is never
        // written back to the order, so the customer's original address stays on
        // record if the delivery is later disputed.
        $submitted = $this->getRequest()->getParam('pe');
        $fields = $this->addressForm->fromRequest(is_array($submitted) ? $submitted : []);

        $checked = $this->addressForm->validate($fields, (int) $order->getStoreId());

        if ($this->addressForm->hasErrors($checked['issues'])) {
            // Hand the form back with the values and the reasons intact, rather
            // than resetting it to the order and losing the admin's corrections.
            $this->keepForm($orderId, $checked['fields'], $checked['issues']);
            $this->messageManager->addErrorMessage(
                __('The shipment was not created. %1', $this->addressForm->firstError($checked['issues']))
            );

            return $back;
        }

        try {
            $shipment = $this->shipmentSender->send($order, ['address_fields' => $checked['fields']]);

            $this->dataPersistor->clear(self::FORM_STORAGE_KEY);

            $referenceNumber = $shipment->getReferenceNumber();
            $trackingNumber = $shipment->getTrackingNumber();

            $msg = __('Shipment sent to Pigeon Express. Reference: %1', $referenceNumber);
            if ($trackingNumber) {
                $msg = __(
                    'Shipment sent to Pigeon Express. Reference: %1, Tracking: %2',
                    $referenceNumber,
                    $trackingNumber
                );
            }
            $this->messageManager->addSuccessMessage($msg);
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->keepForm($orderId, $checked['fields'], $checked['issues']);
            $this->messageManager->addErrorMessage($e->getMessage());
            $this->logger->warning(
                '[PigeonExpress] Send shipment failed for order #' . $orderId . ': ' . $e->getMessage()
            );
        } catch (\Throwable $e) {
            $this->keepForm($orderId, $checked['fields'], $checked['issues']);
            $this->messageManager->addErrorMessage(
                __('Failed to send shipment to Pigeon Express. Please check the logs.')
            );
            $this->logger->error(
                '[PigeonExpress] Send shipment unexpected error for order #' . $orderId . ': ' . $e->getMessage()
            );
        }

        return $back;
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, array{severity:string, message:string}> $issues
     */
    private function keepForm(int $orderId, array $fields, array $issues): void
    {
        $this->dataPersistor->set(self::FORM_STORAGE_KEY, [
            'order_id' => $orderId,
            'fields'   => $fields,
            'issues'   => $issues,
        ]);
    }

}
