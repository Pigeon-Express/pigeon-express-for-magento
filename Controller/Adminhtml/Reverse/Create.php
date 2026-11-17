<?php
/**
 * Create the return shipment of an order from the panel's form.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Controller\Adminhtml\Reverse;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PigeonExpress\Shipping\Block\Adminhtml\Order\ReturnShipment;
use PigeonExpress\Shipping\Model\ReturnShipment\NotEnabledException;
use PigeonExpress\Shipping\Model\ReturnShipment\ReturnBodyBuilder;
use PigeonExpress\Shipping\Model\ReturnShipment\ReturnShipmentService;
use Psr\Log\LoggerInterface;

class Create extends AbstractReverse implements HttpPostActionInterface
{
    /** @var ReturnBodyBuilder */
    private $builder;

    /** @var DataPersistorInterface */
    private $dataPersistor;

    public function __construct(
        Context $context,
        OrderRepositoryInterface $orderRepository,
        ReturnShipmentService $service,
        LoggerInterface $logger,
        ReturnBodyBuilder $builder,
        DataPersistorInterface $dataPersistor
    ) {
        $this->builder = $builder;
        $this->dataPersistor = $dataPersistor;
        parent::__construct($context, $orderRepository, $service, $logger);
    }

    public function execute(): ResultInterface
    {
        $order = $this->loadOrder();
        if ($order === null) {
            $this->messageManager->addErrorMessage(__('Order not found.'));

            return $this->back(null);
        }

        $raw = $this->getRequest()->getParam('rs');
        $form = $this->builder->fromRequest(is_array($raw) ? $raw : []);

        try {
            $row = $this->service->create($order, $form);
            $this->dataPersistor->clear(ReturnShipment::FORM_STORAGE_KEY);
            $this->messageManager->addSuccessMessage(
                __('The return shipment was created: %1.', (string) $row['reference_number'])
            );
        } catch (NotEnabledException $e) {
            // Refused for the account: the form goes, as WooCommerce's does.
            $this->dataPersistor->clear(ReturnShipment::FORM_STORAGE_KEY);
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Throwable $e) {
            $this->dataPersistor->set(ReturnShipment::FORM_STORAGE_KEY, [
                'order_id' => (int) $order->getEntityId(),
                'form'     => $form,
            ]);
            $this->fail($e, 'create');
        }

        return $this->back($order);
    }
}
