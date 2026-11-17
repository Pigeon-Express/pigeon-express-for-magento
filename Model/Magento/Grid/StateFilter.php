<?php
/**
 * The "Pigeon Express" filter of Sales > Orders: which state an order's
 * shipment is in, as one SQL expression over the order's newest waybill.
 *
 * The same states as the WooCommerce orders list filter, worked out from the
 * status code last read (never the status text):
 *
 * - delivered: delivered to the recipient;
 * - returned or refused: returned, cancelled by the carrier or abandoned (a
 *   refused parcel comes back as returned);
 * - waiting for courier: an open courier request made here holds the
 *   current waybill, and the courier has not collected it yet (as
 *   WooCommerce's CourierRequestAdmin filter);
 * - in transit: anything else not final (created, collected, at an office,
 *   out for delivery, waiting for the customer);
 * - no shipment: a Pigeon Express order with no waybill, or whose waybill was
 *   cancelled by the shop.
 *
 * Each order falls in exactly one state, so the select filter works with
 * Magento's plain "equals" condition: the expression is both the column's
 * value and its filter (addFilterToMap). WooCommerce counts "waiting" inside
 * "in delivery" too; here they are kept apart so the filter is one select.
 *
 * Orders not sent with Pigeon Express have no state (NULL) and match none.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Magento\Grid;

use PigeonExpress\Shipping\Model\ShipmentCanceller;
use PigeonExpress\Shipping\Model\Status\StatusCodes;

class StateFilter
{
    /** Name of the column and of its filter in sales_order_grid.xml. */
    public const FIELD = 'pigeonexpress_state';

    public const STATE_DELIVERED = 'delivered';
    public const STATE_TRANSIT = 'transit';
    public const STATE_FAILED = 'failed';
    public const STATE_WAITING = 'waiting';
    public const STATE_NONE = 'none';

    /** Not collected yet: the courier is still to come. */
    public const WAITING_CODES = [StatusCodes::REGISTERED, 'shipment_awaiting_courier_pickup'];

    /**
     * @return array<string, string> State => label
     */
    public function options(): array
    {
        return [
            self::STATE_DELIVERED => (string) __('Pigeon Express - delivered'),
            self::STATE_TRANSIT => (string) __('Pigeon Express - in delivery'),
            self::STATE_FAILED => (string) __('Pigeon Express - returned or refused'),
            self::STATE_WAITING => (string) __('Pigeon Express - waiting for courier'),
            self::STATE_NONE => (string) __('Pigeon Express - no shipment'),
        ];
    }

    /**
     * The state of an order, from the newest waybill and the order's method.
     *
     * Mirrors expression() for code that has the values in hand.
     */
    public function stateOf(
        string $shippingMethod,
        ?string $lifecycle,
        ?string $statusCode,
        bool $courierRequested = false
    ): ?string {
        if (strpos($shippingMethod, 'pigeonexpress_') !== 0 && $lifecycle === null) {
            return null;
        }

        if ($lifecycle === null || $lifecycle === ShipmentCanceller::STATUS_CANCELLED) {
            return self::STATE_NONE;
        }

        $code = (string) $statusCode;
        if ($code === StatusCodes::DELIVERED) {
            return self::STATE_DELIVERED;
        }
        if (in_array($code, StatusCodes::FAILED_CODES, true)) {
            return self::STATE_FAILED;
        }
        if ($courierRequested && self::isWaitingCode($code)) {
            return self::STATE_WAITING;
        }

        return self::STATE_TRANSIT;
    }

    /**
     * Whether a status code still means "the courier has not collected it".
     * No code yet counts as created.
     */
    public static function isWaitingCode(string $code): bool
    {
        return $code === '' || in_array($code, self::WAITING_CODES, true);
    }

    /**
     * The same as stateOf(), in SQL.
     *
     * @param string $shipment Alias of the newest pigeonexpress_shipment row
     * @param string $methodColumn Column holding the order's shipping method
     * @param bool $hasStatusCode Whether pigeonexpress_shipment has status_code yet
     * @param string $courierRequestedSql SQL that is true when an open courier
     *                                    request holds the waybill; empty for never
     */
    public function expression(
        string $shipment,
        string $methodColumn,
        bool $hasStatusCode = true,
        string $courierRequestedSql = ''
    ): string {
        $courier = $courierRequestedSql !== '' ? $courierRequestedSql : '1 = 0';
        $cancelled = $this->quote(ShipmentCanceller::STATUS_CANCELLED);
        $code = $hasStatusCode ? "COALESCE({$shipment}.status_code, '')" : "''";

        return 'CASE'
            . " WHEN {$shipment}.entity_id IS NULL AND ({$methodColumn} IS NULL"
            . " OR {$methodColumn} NOT LIKE 'pigeonexpress\\_%') THEN NULL"
            . " WHEN {$shipment}.entity_id IS NULL OR {$shipment}.status = {$cancelled}"
            . " THEN '" . self::STATE_NONE . "'"
            . " WHEN {$code} = " . $this->quote(StatusCodes::DELIVERED)
            . " THEN '" . self::STATE_DELIVERED . "'"
            . " WHEN {$code} IN (" . $this->quoteList(StatusCodes::FAILED_CODES) . ")"
            . " THEN '" . self::STATE_FAILED . "'"
            . " WHEN ({$courier}) AND {$code} IN (''," . $this->quoteList(self::WAITING_CODES) . ")"
            . " THEN '" . self::STATE_WAITING . "'"
            . " ELSE '" . self::STATE_TRANSIT . "' END";
    }

    /**
     * Codes are the module's own constants of [a-z_]; anything else is dropped
     * rather than trusted inside SQL.
     */
    private function quote(string $value): string
    {
        return "'" . preg_replace('/[^a-z0-9_]/', '', $value) . "'";
    }

    /**
     * @param string[] $values
     */
    private function quoteList(array $values): string
    {
        return implode(',', array_map([$this, 'quote'], $values));
    }
}
