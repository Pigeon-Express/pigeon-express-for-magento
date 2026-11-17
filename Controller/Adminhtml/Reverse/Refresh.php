<?php
/**
 * Read the return shipment's status from Pigeon Express.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Controller\Adminhtml\Reverse;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;

class Refresh extends AbstractReverse implements HttpPostActionInterface
{
    public function execute(): ResultInterface
    {
        $order = $this->loadOrder();
        if ($order === null) {
            $this->messageManager->addErrorMessage(__('Order not found.'));

            return $this->back(null);
        }

        try {
            $this->service->refresh($order);
            $this->messageManager->addSuccessMessage(__('The return shipment status was read from Pigeon Express.'));
        } catch (\Throwable $e) {
            $this->fail($e, 'refresh');
        }

        return $this->back($order);
    }
}
