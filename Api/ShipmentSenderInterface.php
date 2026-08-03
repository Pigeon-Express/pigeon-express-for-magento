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

    /**
     * Ask Pigeon Express what it charges to carry this order as described.
     *
     * Nothing is created or saved. The order keeps whatever the customer was
     * quoted at checkout; this only answers what the shipping costs now, which
     * changes when a parcel is redirected to an office or a locker.
     *
     * @param array $fields Checked shipment form values
     * @return array{price: float, currency: string}
     * @throws ApiException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function priceFor(OrderInterface $order, array $fields): array;

    /**
     * The parcels this order becomes, by the same rules used for rating.
     *
     * Exposed so the admin can be shown the weight, size and count that will go
     * on the waybill rather than having to guess at them.
     *
     * @return array<int, array{weight:float, length:float, width:float, height:float}>
     */
    public function packagesFor(OrderInterface $order, string $deliveryType = ''): array;

    /**
     * How much the courier is to collect on delivery, zero when not a cash order.
     */
    public function codAmountFor(OrderInterface $order): float;
}
