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
    /**
     * Module lifecycle values of the `status` column (not the carrier status,
     * see getStatusCode()). A row created here holds the status the create
     * answered with (shipment_registered, or sent when none came); cancelled
     * is ShipmentCanceller::STATUS_CANCELLED.
     *
     * Redirected: Pigeon Express replaced this waybill with a new one (a
     * change of delivery place after shipment_registered). The order moved to
     * a newer row whose redirected_from is this row's reference; this row is
     * history and is never cancelled, edited or followed again.
     */
    public const STATUS_REDIRECTED = 'redirected';

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

    /**
     * The shipment form exactly as the admin sent it, as JSON.
     */
    public function getFormFields(): ?string
    {
        return $this->getData('form_fields');
    }

    public function setFormFields(?string $formFields): self
    {
        return $this->setData('form_fields', $formFields);
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

    /**
     * The carrier's status code as last read, or '' before the first read.
     * Not the same as getStatus(), which is the module's own lifecycle
     * (created or cancelled here).
     */
    public function getStatusCode(): string
    {
        return (string) $this->getData('status_code');
    }

    public function getStatusName(): string
    {
        return (string) $this->getData('status_name');
    }

    /** When the carrier recorded the status, UTC "Y-m-d H:i:s", or null. */
    public function getStatusAt(): ?string
    {
        $value = $this->getData('status_at');

        return $value !== null && $value !== '' ? (string) $value : null;
    }

    /** When the status was last read from the carrier, UTC "Y-m-d H:i:s", or null. */
    public function getStatusCheckedAt(): ?string
    {
        $value = $this->getData('status_checked_at');

        return $value !== null && $value !== '' ? (string) $value : null;
    }

    /**
     * The trimmed copy of the carrier's shipment details, or null when they
     * were never read. See Model\Status\ShipmentDetailsStore.
     *
     * @return array<string, mixed>|null
     */
    public function getDetails(): ?array
    {
        $decoded = json_decode((string) $this->getData('details_json'), true);

        return is_array($decoded) && isset($decoded['fetched_at']) ? $decoded : null;
    }

    /**
     * The reference of the shipment this one replaced by a redirect, or null
     * for a shipment created here.
     */
    public function getRedirectedFrom(): ?string
    {
        $value = $this->getData('redirected_from');

        return $value !== null && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    public function setRedirectedFrom(?string $reference): self
    {
        return $this->setData('redirected_from', $reference);
    }

    /**
     * When the customer was emailed this shipment's tracking number after a
     * redirect, UTC "Y-m-d H:i:s", or null when not yet. Set once; whoever
     * sets it first sends the email, nobody after (replay, Refresh, cron).
     */
    public function getCustomerNotifiedAt(): ?string
    {
        $value = $this->getData('customer_notified_at');

        return $value !== null && $value !== '' ? (string) $value : null;
    }

    public function setCustomerNotifiedAt(?string $utc): self
    {
        return $this->setData('customer_notified_at', $utc);
    }
}
