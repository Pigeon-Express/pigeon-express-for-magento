<?php
/**
 * Shipment creation client interface.
 *
 * @copyright Copyright (c). All rights reserved.
 */
declare(strict_types=1);

namespace PigeonExpress\Shipping\Api;

use PigeonExpress\Shipping\Exception\ApiException;

interface ShipmentClientInterface
{
    /**
     * Create a shipment via /shipments.
     *
     * Payload keys: receiver_name, receiver_phone, pickup_type, delivery_type,
     * service_type, who_pays, packages[] and optionally pickup_office_id,
     * delivery_office_id, delivery_address, sender_address, cod_amount, note,
     * sms_notification.
     *
     * @param array $payload
     * @param int $storeId
     * @return array{reference_number: string, tracking_number: string|null, status: string, delivery_price: float}
     * @throws ApiException
     */
    public function create(array $payload, int $storeId): array;

    /**
     * Cancel a shipment at Pigeon Express.
     *
     * Only possible while the shipment is still registered. Once the courier has
     * it, the API refuses and so must we.
     *
     * @return array<string,mixed> Raw response data
     * @throws \PigeonExpress\Shipping\Exception\ApiException
     */
    public function cancel(string $referenceNumber, int $storeId): array;

    /**
     * Fetch a shipping label for streaming to the browser.
     *
     * @param string|null $format One of default, pdf150, zpl300, a4
     * @param bool $download Ask for an attachment rather than inline display
     * @return array{body:string, content_type:string, filename:string}
     * @throws \PigeonExpress\Shipping\Exception\ApiException
     */
    public function label(string $referenceNumber, ?string $format, bool $download, int $storeId): array;
}
