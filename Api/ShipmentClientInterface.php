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
     * Payload keys are the API's create fields: receiver_name, receiver_phone,
     * pickup_type, delivery_type, service_type, who_pays, packages[] and any
     * optional one (pickup_office_id, delivery_office_id, delivery_address,
     * pickup_address or sender_address, note, service_codes, inventory_items,
     * external_reference, hide_content_on_label, ...). Every key is sent; none
     * is dropped.
     *
     * Sent once and never repeated on an error, except that a 409 is repeated
     * at once with the same body. `replay` in the result is one of the
     * ShipmentClient::REPLAY_* values: not empty when the answer was the
     * carrier's stored answer to an identical earlier create, whose shipment
     * may have been cancelled since.
     *
     * @param array $payload
     * @param int $storeId
     * @return array{reference_number: string, tracking_number: string|null, status: string, delivery_price: float, replay: string}
     * @throws \PigeonExpress\Shipping\Exception\OutcomeUnknownException When the shipment may or may not exist
     * @throws ApiException When Pigeon Express refused it, or it was never sent
     */
    public function create(array $payload, int $storeId): array;

    /**
     * Everything Pigeon Express holds about one shipment (GET /shipments/{ref}):
     * status, fees charged, cash on delivery, external reference.
     *
     * @throws ApiException
     */
    public function get(string $referenceNumber, int $storeId): \PigeonExpress\DTO\Response\ShipmentDetails;

    /**
     * Change a shipment that is still newly registered (PUT /shipments/{ref}),
     * keeping its waybill number. The payload is the whole shipment as it
     * should become, built as for create: whatever is left out is cleared.
     *
     * `replay` is one of the ShipmentClient::REPLAY_* values. REPLAY_EARLIER
     * means the carrier answered with its stored answer to the same change
     * sent less than 30 seconds earlier, without acting on this one.
     *
     * @param array<string, mixed> $payload
     * @return array{details: \PigeonExpress\DTO\Response\ShipmentDetails, replay: string}
     * @throws \PigeonExpress\Shipping\Exception\NotEditableException When the shipment has moved on
     * @throws ApiException
     */
    public function update(string $referenceNumber, array $payload, int $storeId): array;

    /**
     * Track up to ShipmentClient::TRACK_BULK_MAX shipments in one request
     * (POST /shipments/track/bulk, a read). Entries are keyed by reference,
     * each the SDK's normalised BulkTrackingEntry as an array (`found`,
     * `status_code`, `status`, `tracking`, `chain_after`, ...).
     *
     * @param string[] $references
     * @return array<string, array<string, mixed>>
     * @throws \PigeonExpress\Shipping\Exception\RateLimitedException
     * @throws ApiException
     */
    public function trackBulk(array $references, int $storeId): array;

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
