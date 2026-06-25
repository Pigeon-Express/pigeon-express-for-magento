<?php
/**
 * Pigeon Express Shipment model.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model;

use Magento\Framework\Model\AbstractModel;
use PigeonExpress\Shipping\Model\ResourceModel\Shipment as ShipmentResource;

class Shipment extends AbstractModel
{
    protected function _construct()
    {
        $this->_init(ShipmentResource::class);
    }

    public function getOrderId(): int
    {
        return (int) $this->getData('order_id');
    }

    public function setOrderId(int $orderId): self
    {
        return $this->setData('order_id', $orderId);
    }

    public function getReferenceNumber(): ?string
    {
        return $this->getData('reference_number');
    }

    public function setReferenceNumber(?string $referenceNumber): self
    {
        return $this->setData('reference_number', $referenceNumber);
    }

    public function getTrackingNumber(): ?string
    {
        return $this->getData('tracking_number');
    }

    public function setTrackingNumber(?string $trackingNumber): self
    {
        return $this->setData('tracking_number', $trackingNumber);
    }

    public function getStatus(): string
    {
        return (string) $this->getData('status');
    }

    public function setStatus(string $status): self
    {
        return $this->setData('status', $status);
    }

    public function getDeliveryPrice(): ?float
    {
        $v = $this->getData('delivery_price');
        return $v !== null ? (float) $v : null;
    }

    public function setDeliveryPrice(?float $deliveryPrice): self
    {
        return $this->setData('delivery_price', $deliveryPrice);
    }

    public function getPayload(): ?string
    {
        return $this->getData('payload');
    }

    public function setPayload(?string $payload): self
    {
        return $this->setData('payload', $payload);
    }

    public function getResponse(): ?string
    {
        return $this->getData('response');
    }

    public function setResponse(?string $response): self
    {
        return $this->setData('response', $response);
    }

    public function getSentAt(): ?string
    {
        return $this->getData('sent_at');
    }
}
