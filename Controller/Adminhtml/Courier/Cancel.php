<?php
/**
 * Cancel a courier request, from the list or the order panel.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Controller\Adminhtml\Courier;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;
use PigeonExpress\Shipping\Model\Courier\CourierRequestService;

class Cancel extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'PigeonExpress_Shipping::courier_request_cancel';

    /** @var CourierRequestService */
    protected $service;

    public function __construct(Context $context, CourierRequestService $service)
    {
        $this->service = $service;
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        return $this->run(function (string $number) {
            return $this->service->cancel($number);
        });
    }

    /**
     * @param callable $action Takes the request number, returns ['message' => string]
     */
    protected function run(callable $action): ResultInterface
    {
        $number = trim((string) $this->getRequest()->getParam('number'));
        $orderId = (int) $this->getRequest()->getParam('order_id');

        try {
            $result = $action($number);
            $this->messageManager->addSuccessMessage($result['message']);
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Could not reach Pigeon Express. Try again.'));
        }

        $redirect = $this->resultRedirectFactory->create();

        return $orderId > 0
            ? $redirect->setPath('sales/order/view', ['order_id' => $orderId])
            : $redirect->setPath('pigeonexpress/courier/index');
    }
}
