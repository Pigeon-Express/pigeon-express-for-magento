<?php
/**
 * Cancel the return shipment of an order.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Controller\Adminhtml\Reverse;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;

class Cancel extends AbstractReverse implements HttpPostActionInterface
{
    public function execute(): ResultInterface
    {
        $order = $this->loadOrder();
        if ($order === null) {
            $this->messageManager->addErrorMessage(__('Order not found.'));

            return $this->back(null);
        }

        try {
            $reference = $this->service->cancel($order);
            $this->messageManager->addSuccessMessage(__('Return shipment %1 was cancelled.', $reference));
        } catch (\Throwable $e) {
            $this->fail($e, 'cancel');
        }

        return $this->back($order);
    }
}
