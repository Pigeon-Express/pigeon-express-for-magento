<?php
/**
 * Interface for sending orders to Pigeon Express API and persisting the result.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Api;

use Magento\Sales\Api\Data\OrderInterface;
use PigeonExpress\Shipping\Exception\ApiException;
use PigeonExpress\Shipping\Model\Shipment;

interface ShipmentSenderInterface
{
    /**
     * Build payload from order, call Pigeon Express API, save result to pigeonexpress_shipment.
     *
     * @param OrderInterface $order
     * @param array $overrides Optional overrides: ['receiver_phone' => '...']
     * @return Shipment
     * @throws ApiException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function send(OrderInterface $order, array $overrides = []): Shipment;
}
