<?php
/**
 * What Pigeon Express holds about a shipment, as last read: what it charged
 * and to whom, every fee, the cash on delivery, the linked shipments and who
 * it will hand the parcel to.
 *
 * Copied from the WooCommerce plugin (Labels/ShipmentDetails.php). Read with
 * GET /shipments/{ref} when the shipment is created, changed or refreshed, and
 * on request for a shipment that has none yet; never when an order is merely
 * opened. A trimmed copy is kept in pigeonexpress_shipment.details_json with
 * the time it was read, and the order panel shows that copy.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Status;

use PigeonExpress\Shipping\Api\ShipmentClientInterface;
use PigeonExpress\Shipping\Exception\ApiException;
use PigeonExpress\Shipping\Model\ResourceModel\Shipment as ShipmentResource;
use PigeonExpress\Shipping\Model\Shipment;

class ShipmentDetailsStore
{
    /** @var ShipmentClientInterface */
    private $client;

    /** @var ShipmentResource */
    private $resource;

    /** @var StatusRecorder */
    private $recorder;

    /** @var TrackingInterpreter */
    private $interpreter;

    public function __construct(
        ShipmentClientInterface $client,
        ShipmentResource $resource,
        StatusRecorder $recorder,
        TrackingInterpreter $interpreter
    ) {
        $this->client = $client;
        $this->resource = $resource;
        $this->recorder = $recorder;
        $this->interpreter = $interpreter;
    }

    /**
     * Read the shipment from Pigeon Express, keep the trimmed copy on the
     * row, and bring the status up to date from it.
     *
     * @return array<string, mixed> The trimmed copy
     * @throws ApiException
     */
    public function fetch(Shipment $row, int $storeId): array
    {
        $details = $this->client->get((string) $row->getReferenceNumber(), $storeId);

        return $this->remember($row, $details->toArray());
    }

    /**
     * Keep a carrier answer (GET or PUT /shipments/{ref}) on the row, and the
     * status it carries.
     *
     * @param array<string, mixed> $data The carrier's `data`
     * @return array<string, mixed> The trimmed copy
     */
    public function remember(Shipment $row, array $data): array
    {
        $trimmed = $this->trim($data, time());

        $this->resource->getConnection()->update(
            $this->resource->getMainTable(),
            ['details_json' => (string) json_encode($trimmed)],
            ['entity_id = ?' => (int) $row->getId()]
        );
        $row->setData('details_json', (string) json_encode($trimmed));

        $status = $this->interpreter->fromDetails($data);
        if ($status !== null) {
            $this->recorder->record($row, $status['code'], $status['name'], $status['at']);
        }

        return $trimmed;
    }

    /**
     * What the panel shows, in a fixed shape, from the carrier's `data`.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function trim(array $data, int $fetchedAt): array
    {
        $pricing = isset($data['pricing']) && is_array($data['pricing']) ? $data['pricing'] : [];
        $cod = isset($data['cod']) && is_array($data['cod']) ? $data['cod'] : [];
        $status = isset($data['status']) && is_array($data['status']) ? $data['status'] : [];
        $receiver = isset($data['receiver']) && is_array($data['receiver']) ? $data['receiver'] : [];
        $fees = [];
        $related = [];

        foreach ((array) ($pricing['fees'] ?? []) as $fee) {
            if (!is_array($fee)) {
                continue;
            }
            $fees[] = [
                'code'     => $this->code($fee['code'] ?? ''),
                'name'     => trim(strip_tags((string) ($fee['name'] ?? ''))),
                'amount'   => $this->number($fee['amount'] ?? null) ?? 0.0,
                'quantity' => max(1, (int) ($fee['quantity'] ?? 1)),
            ];
        }

        foreach ((array) ($data['related_shipments'] ?? []) as $link) {
            if (!is_array($link)) {
                continue;
            }
            $reference = (string) preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($link['reference_number'] ?? ''));
            if ($reference !== '') {
                $related[] = [
                    'reference' => $reference,
                    'relation'  => $this->code($link['relation'] ?? ''),
                ];
            }
        }

        return [
            'fetched_at'      => $fetchedAt,
            'status'          => [
                'code' => $this->code($status['code'] ?? ($data['status_code'] ?? '')),
                'name' => trim(strip_tags((string) ($status['name'] ?? ''))),
            ],
            'created_at'      => (string) ($data['created_at'] ?? ''),
            'delivery_date'   => (string) ($data['delivery_date'] ?? ''),
            'payer'           => $this->code($pricing['payer'] ?? ''),
            'courier_service' => $this->number($pricing['courier_service'] ?? null),
            'total'           => $this->number($pricing['total'] ?? null),
            'fees'            => $fees,
            'cod'             => [
                'amount'         => $this->number($cod['amount'] ?? null),
                'currency'       => (string) preg_replace('/[^A-Z]/', '', strtoupper((string) ($cod['currency'] ?? ''))),
                'payment_status' => $this->code($cod['payment_status'] ?? ''),
            ],
            'related'         => $related,
            // Who the carrier will hand the parcel to: the delivery contact,
            // else its customer record for the phone (for a locker, never the
            // name the shop sent). Either may be missing.
            'receiver'        => [
                'name'  => $this->text($receiver['name'] ?? ''),
                'phone' => $this->text($receiver['phone'] ?? ''),
            ],
        ];
    }

    /**
     * Whether a list of linked shipments names one this shipment was made
     * from (`original`): then it is a return or a redirect, which Pigeon
     * Express does not let the sender cancel. Takes the trimmed `related`
     * rows or the carrier's own `related_shipments`.
     *
     * @param mixed $related
     */
    public static function hasOriginal($related): bool
    {
        foreach ((array) $related as $link) {
            if (is_array($link) && (string) ($link['relation'] ?? '') === 'original') {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the shipment, as last read, is a return or a redirect of
     * another one. False when the details were never read.
     */
    public static function isDerived(?Shipment $row): bool
    {
        $stored = $row !== null ? $row->getDetails() : null;

        return $stored !== null && self::hasOriginal($stored['related'] ?? []);
    }

    /**
     * The receiver as one line, "name, phone", or '' when the carrier gave
     * neither.
     *
     * @param array<string, mixed>|null $stored The trimmed copy
     */
    public static function receiverLine(?array $stored): string
    {
        $receiver = is_array($stored['receiver'] ?? null) ? $stored['receiver'] : [];

        return implode(', ', array_filter([
            trim((string) ($receiver['name'] ?? '')),
            trim((string) ($receiver['phone'] ?? '')),
        ], 'strlen'));
    }

    /**
     * A fee as the merchant knows it: the module's name for the two it
     * names itself, else the carrier's name for the fee. Never the code.
     */
    public function feeLabel(string $code, string $carrierName): string
    {
        switch ($code) {
            case 'cod_fee':
                return (string) __('Cash on delivery fee');
            case 'fuel_fee':
                return (string) __('Fuel surcharge');
        }

        $carrierName = trim($carrierName);

        return $carrierName !== '' ? $carrierName : (string) __('Other fee');
    }

    public function payerLabel(string $payer): string
    {
        switch ($payer) {
            case 'sender':
                return (string) __('Sender pays');
            case 'receiver':
                return (string) __('Receiver pays');
            case '':
                return '';
            default:
                return (string) __('Another party');
        }
    }

    /**
     * Where the cash on delivery money is. An unknown value says nothing
     * rather than showing a code.
     */
    public function codStatusLabel(string $status): string
    {
        switch ($status) {
            case 'pending':
                return (string) __('Not paid out yet');
            case 'collected':
                return (string) __('Collected, not paid out yet');
            case 'paid':
            case 'completed':
            case 'transferred':
                return (string) __('Paid out to you');
            case 'cancelled':
            case 'canceled':
                return (string) __('Cancelled');
            default:
                return '';
        }
    }

    public function relationLabel(string $relation): string
    {
        $labels = [
            'original'         => __('Original shipment'),
            'return_to_sender' => __('Return to sender'),
            'return_document'  => __('Return documents'),
            'redirected'       => __('Redirected shipment'),
            'stored'           => __('Storage'),
            'partial_return'   => __('Partial return'),
        ];

        return (string) ($labels[$relation] ?? __('Linked shipment'));
    }

    /**
     * @param mixed $value
     */
    private function number($value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Plain text from the carrier: tags and surrounding space removed.
     *
     * @param mixed $value
     */
    private function text($value): string
    {
        return is_scalar($value) ? trim(strip_tags((string) $value)) : '';
    }

    /**
     * @param mixed $code
     */
    private function code($code): string
    {
        return is_scalar($code) ? (string) preg_replace('/[^A-Za-z0-9_]/', '', (string) $code) : '';
    }
}
