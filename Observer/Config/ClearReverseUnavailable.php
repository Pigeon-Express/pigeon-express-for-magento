<?php
/**
 * Saving the Pigeon Express settings forgets that return shipments were
 * refused, so the orders offer them again and the next one tells whether
 * Pigeon Express has enabled them meanwhile. A switch of environment needs
 * nothing here: the refusal is remembered per environment.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Observer\Config;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use PigeonExpress\Shipping\Model\ReturnShipment\Availability;

class ClearReverseUnavailable implements ObserverInterface
{
    /** @var Availability */
    private $availability;

    public function __construct(Availability $availability)
    {
        $this->availability = $availability;
    }

    public function execute(Observer $observer): void
    {
        $this->availability->clear();
    }
}
