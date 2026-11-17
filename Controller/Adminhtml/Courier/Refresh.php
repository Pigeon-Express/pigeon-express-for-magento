<?php
/**
 * Read a courier request's status from Pigeon Express. A read, so the
 * listing's plain link (GET) may call it as well as the panel's form.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Controller\Adminhtml\Courier;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;

class Refresh extends Cancel implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'PigeonExpress_Shipping::courier_requests';

    public function execute(): ResultInterface
    {
        return $this->run(function (string $number) {
            return $this->service->refresh($number);
        });
    }
}
