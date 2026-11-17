<?php
/**
 * The facts about an order that decide which side the shipment form suggests
 * the carrier bills: was the delivery given away, was it the shop's fixed
 * price, and is the order cash on delivery.
 *
 * Asked in three places (the panel, Send and Recalculate), which must agree, or
 * the price shown would be for one side and the waybill created for the other.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Shipment;

use Magento\Sales\Api\Data\OrderInterface;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Api\FreeShippingInterface;
use PigeonExpress\Shipping\Api\ShipmentSenderInterface;
use PigeonExpress\Shipping\Model\OrderAddressLocationPersistor;

class WhoPaysFacts
{
    /** @var FreeShippingInterface */
    private $freeShipping;

    /** @var ShipmentSenderInterface */
    private $shipmentSender;

    /** @var ConfigInterface */
    private $config;

    /** @var OrderAddressLocationPersistor */
    private $locationPersistor;

    /** @var AddressForm */
    private $addressForm;

    /** @var array<int, array{free:bool, cod:bool, fixed:bool, rule:string}> */
    private $cache = [];

    public function __construct(
        FreeShippingInterface $freeShipping,
        ShipmentSenderInterface $shipmentSender,
        ConfigInterface $config,
        OrderAddressLocationPersistor $locationPersistor,
        AddressForm $addressForm
    ) {
        $this->freeShipping = $freeShipping;
        $this->shipmentSender = $shipmentSender;
        $this->config = $config;
        $this->locationPersistor = $locationPersistor;
        $this->addressForm = $addressForm;
    }

    /**
     * @return array{free:bool, cod:bool, fixed:bool, rule:string}
     */
    public function forOrder(OrderInterface $order): array
    {
        $key = (int) $order->getEntityId();
        if ($key > 0 && isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $free = $this->freeShipping->wasAppliedToOrder($order);
        $cod = $this->shipmentSender->isCashOnDelivery($order);
        $fixed = !$free && $this->isFixedPrice($order);

        $facts = [
            'free'  => $free,
            'cod'   => $cod,
            'fixed' => $fixed,
            'rule'  => $this->addressForm->whoPaysRule($cod, $free, $fixed),
        ];

        if ($key > 0) {
            $this->cache[$key] = $facts;
        }

        return $facts;
    }

    /**
     * What AddressForm::validate() takes as "nothing left for the customer to
     * pay for this delivery": given away, or not collected at the door.
     */
    public function nothingToCollect(OrderInterface $order): bool
    {
        $facts = $this->forOrder($order);

        return $facts['free'] || !$facts['cod'];
    }

    /**
     * Whether the customer was charged the shop's fixed price for the delivery
     * type they chose, rather than a price the carrier quoted.
     *
     * Read from the setting for that delivery type, the order recording no
     * more than the amount.
     */
    private function isFixedPrice(OrderInterface $order): bool
    {
        $type = '';
        $address = $order->getShippingAddress();
        if ($address && $address->getId()) {
            $data = $this->locationPersistor->getByAddressId((int) $address->getId());
            $type = (string) ($data['delivery_type'] ?? '');
        }

        if ($type === '') {
            $method = (string) $order->getShippingMethod();
            $prefix = ConfigInterface::CARRIER_CODE . '_';
            $type = strpos($method, $prefix) === 0 ? substr($method, strlen($prefix)) : '';
        }

        if (!in_array($type, [
            ConfigInterface::DELIVERY_TYPE_ADDRESS,
            ConfigInterface::DELIVERY_TYPE_OFFICE,
            ConfigInterface::DELIVERY_TYPE_APS,
        ], true)) {
            return false;
        }

        try {
            return $this->config->getDeliveryTypePricingMode($type, (int) $order->getStoreId()) !== 'dynamic';
        } catch (\Throwable $e) {
            return false;
        }
    }
}
