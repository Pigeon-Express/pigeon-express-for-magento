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
     * A refusal keeps the SDK exception as its cause:
     * ApiException::getHttpStatus() is 422 when Pigeon Express refused the
     * cancel (for example a return or a redirect, which cannot be
     * cancelled), and getApiErrorCode() / getApiErrorData() give its answer.
     *
     * @return array<string,mixed> Raw response data
     * @throws \PigeonExpress\Shipping\Exception\ApiException
     */
    public function cancel(string $referenceNumber, int $storeId): array;

    /**
     * What the sender can change on the shipment now
     * (GET /shipments/{ref}/actions), as Pigeon Express answers it:
     *
     *  - `actions`: always all four of change_delivery, services, cod and
     *    declared_value, each {allowed: bool, reason_code: string,
     *    reason: string}; `reason` is Bulgarian and meant to be shown;
     *  - `current` (delivery_type, delivery_office_id, delivery_address,
     *    services, cod_amount, allow_cod_card_payment, cod_from_items,
     *    cod_items_amount, declared_value), `addable_services` and
     *    `removable_services` ({code, name} rows), `pricing` (total,
     *    paid_amount, payment_mode, currency) and `active_reference_number`
     *    (set on a redirected shipment, else null).
     *
     * Throws ApiException with getHttpStatus() 404 / getApiErrorCode()
     * not_found for a shipment this account did not send and for every return
     * shipment, 502 / backend_unavailable when the courier's system cannot
     * answer, RateLimitedException on 429. The message is Pigeon Express's
     * reason. 15 second timeout.
     *
     * @return array<string, mixed>
     * @throws \PigeonExpress\Shipping\Exception\ApiException
     */
    public function actions(string $referenceNumber, int $storeId): array;

    /**
     * Take one quick action on a registered shipment, or only price it with
     * $dryRun (POST /shipments/{ref}/actions/{action}).
     *
     * $action: ShipmentClient::ACTION_* (change_delivery, services, cod,
     * declared_value) or the path spelling (change-delivery, declared-value).
     * $body: the action's own fields; `dry_run` is always set from $dryRun.
     * A null value is sent as null, which removes cod_amount or declared_value.
     *
     * Returns:
     *  - `data`: Pigeon Express's `data`: dry_run, price (previous_total,
     *    new_total, paid_amount, amount_due, payment_mode), result
     *    (reference_number, new_reference_number, expected_delivery_date,
     *    removed_services);
     *  - `message`: its message (Bulgarian);
     *  - `replay`: a ShipmentClient::REPLAY_* value, never set on a dry run;
     *    REPLAY_EARLIER means an identical change made less than 30 seconds
     *    earlier answered, and the change was made once, by that request;
     *  - `new_reference_number`: the new shipment after a redirect (a change
     *    of place once the shipment is past newly registered), else ''.
     *
     * A refusal throws ApiException whose message is Pigeon Express's
     * reason; decide by getApiErrorCode() (action_not_allowed,
     * requires_office_payment with getApiErrorData()['price']['amount_due'],
     * already_redirected with ['active_reference_number'], no_change,
     * fee_closed, validation_failed, service_not_allowed_for_locker,
     * backend_unavailable) and getHttpStatus(). A real action that may have
     * been made after all (no answer, or 409 after the repeats) throws
     * OutcomeUnknownException; 429 throws RateLimitedException. A dry run
     * is never repeated on 409 and has a 15 second timeout.
     *
     * Never confirm a real change_delivery on a shipment past
     * shipment_registered in a test: it creates a redirect that cannot be
     * cancelled.
     *
     * @param array<string, mixed> $body
     * @return array{data: array<string, mixed>, message: string, replay: string, new_reference_number: string}
     * @throws \PigeonExpress\Shipping\Exception\ApiException
     */
    public function runAction(
        string $referenceNumber,
        string $action,
        array $body,
        bool $dryRun,
        int $storeId
    ): array;

    /**
     * A parcel locker's boxes and the forecast of free boxes per size
     * (GET /lockers/{code}/availability), looked up by the locker's code
     * (LocationSearch::getApsCode()), never its id. A forecast, not a
     * reservation.
     *
     * Throws ApiException with getHttpStatus() 404 for an unknown, inactive
     * or office code, and with status 0 for an empty code. Not cached here.
     * 10 second timeout.
     *
     * @return array{code: string, name: string, is_blocked: bool, blocked_until: string, box_sizes: array<int, array{size: string, length: int, width: int, height: int, max_weight: float|null, total: int}>, today: array<string, int>, tomorrow: array<string, int>, today_date: string, tomorrow_date: string}
     *         today / tomorrow: box size => free boxes, one entry per size the locker has
     * @throws \PigeonExpress\Shipping\Exception\ApiException
     */
    public function lockerAvailability(string $code, int $storeId): array;

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
