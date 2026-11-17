<?php
/**
 * Save the changes made to an existing Pigeon Express shipment, keeping its
 * waybill number. The order panel's "Edit shipment" form posts here.
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
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;
use PigeonExpress\Shipping\Exception\NotEditableException;
use PigeonExpress\Shipping\Model\Shipment\AddressForm;
use PigeonExpress\Shipping\Model\Shipment\WhoPaysFacts;
use PigeonExpress\Shipping\Model\ShipmentEditor;
use Psr\Log\LoggerInterface;

class Edit extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'PigeonExpress_Shipping::edit_shipment';

    /** Request parameter that opens the shipment form in edit mode. */
    public const EDIT_PARAM = 'pe_edit';

    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var ShipmentEditor */
    private $editor;

    /** @var AddressForm */
    private $addressForm;

    /** @var WhoPaysFacts */
    private $whoPaysFacts;

    /** @var DataPersistorInterface */
    private $dataPersistor;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        Context $context,
        OrderRepositoryInterface $orderRepository,
        ShipmentEditor $editor,
        AddressForm $addressForm,
        WhoPaysFacts $whoPaysFacts,
        DataPersistorInterface $dataPersistor,
        LoggerInterface $logger
    ) {
        $this->orderRepository = $orderRepository;
        $this->editor = $editor;
        $this->addressForm = $addressForm;
        $this->whoPaysFacts = $whoPaysFacts;
        $this->dataPersistor = $dataPersistor;
        $this->logger = $logger;
        parent::__construct($context);
    }

    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create();
        $orderId = (int) $this->getRequest()->getParam('order_id');

        try {
            $order = $this->orderRepository->get($orderId);
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Order not found.'));

            return $redirect->setPath('sales/order/index');
        }

        $submitted = $this->getRequest()->getParam('pe');
        $fields = $this->addressForm->fromRequest(is_array($submitted) ? $submitted : []);
        $checked = $this->addressForm->validate(
            $fields,
            (int) $order->getStoreId(),
            $this->whoPaysFacts->nothingToCollect($order),
            $this->whoPaysFacts->forOrder($order)['fixed']
        );

        $backToForm = ['order_id' => $orderId, self::EDIT_PARAM => 1];

        if ($this->addressForm->hasErrors($checked['issues'])) {
            $this->keepForm($orderId, $checked['fields'], $checked['issues']);
            $this->messageManager->addErrorMessage(
                __('The shipment was not changed. %1', $this->addressForm->firstError($checked['issues']))
            );

            return $redirect->setPath('sales/order/view', $backToForm);
        }

        try {
            $shipment = $this->editor->update($order, $checked['fields']);
            $this->dataPersistor->clear(Send::FORM_STORAGE_KEY);
            $this->messageManager->addSuccessMessage(__(
                'Pigeon Express shipment %1 was changed. The waybill number stays the same. '
                . 'Print the label again.',
                (string) $shipment->getReferenceNumber()
            ));

            return $redirect->setPath('sales/order/view', ['order_id' => $orderId]);
        } catch (NotEditableException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());

            return $redirect->setPath('sales/order/view', ['order_id' => $orderId]);
        } catch (LocalizedException $e) {
            $this->keepForm($orderId, $checked['fields'], $checked['issues']);
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Throwable $e) {
            $this->keepForm($orderId, $checked['fields'], $checked['issues']);
            $this->logger->error('[PigeonExpress] Edit shipment failed for order #' . $orderId . ': '
                . $e->getMessage());
            $this->messageManager->addErrorMessage(
                __('Could not change the Pigeon Express shipment. Check the log for details.')
            );
        }

        return $redirect->setPath('sales/order/view', $backToForm);
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, array{severity:string, message:string}> $issues
     */
    private function keepForm(int $orderId, array $fields, array $issues): void
    {
        $this->dataPersistor->set(Send::FORM_STORAGE_KEY, [
            'order_id' => $orderId,
            'fields'   => $fields,
            'issues'   => $issues,
        ]);
    }
}
