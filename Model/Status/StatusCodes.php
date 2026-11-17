<?php
/**
 * What a Pigeon Express shipment status code means to the shop.
 *
 * Copied from the WooCommerce plugin (Labels/ShipmentStatus.php). Everything
 * is keyed on the carrier's status code, never on its text: the carrier names
 * the same code differently from one endpoint to the next, so a name is only
 * ever shown, never compared.
 *
 * The carrier publishes the codes without saying which of them end a
 * shipment. The reading here is ours: delivered to the recipient ends it
 * well; returned, cancelled and abandoned end it badly. Arrived at an office
 * or left in a locker is NOT delivered: the parcel is waiting for the
 * customer, who may never come. A refused or uncollected parcel comes back as
 * a linked return shipment; TrackingInterpreter turns that into RETURNED.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Status;

class StatusCodes
{
    /** The status every shipment starts in, and the only one that can be changed or cancelled. */
    public const REGISTERED = 'shipment_registered';
    public const DELIVERED = 'shipment_delivered_to_recipient';
    public const RETURNED = 'shipment_returned';
    public const RETURNING_TO_SENDER = 'shipment_returning_to_sender';
    public const CANCELLED = 'shipment_cancelled';
    public const ABANDONED = 'shipment_abandoned';
    public const REDIRECTED = 'shipment_redirected';

    /** Statuses after which nothing more happens to the shipment. */
    public const FINAL_CODES = [self::DELIVERED, self::RETURNED, self::CANCELLED, self::ABANDONED];

    /** Final statuses in which the customer did not get the parcel. */
    public const FAILED_CODES = [self::RETURNED, self::CANCELLED, self::ABANDONED];

    /** Waiting for the customer at an office or in a locker. Not delivered. */
    public const PICKUP_CODES = ['shipment_delivered_to_office', 'shipment_left_in_locker'];

    /** Not final, but something went wrong or is about to. */
    public const ATTENTION_CODES = [
        'shipment_delivery_attempt_failed',
        'shipment_delivery_problem',
        'shipment_locker_time_expired',
        'shipment_storage_expired',
        'shipment_untracked',
        'shipment_returning_to_sender',
        'shipment_held_by_sender',
        'shipment_redirection_rejected',
        'complaint',
    ];

    public const TONE_DELIVERED = 'delivered';
    public const TONE_FAILED = 'failed';
    public const TONE_ATTENTION = 'attention';
    public const TONE_PICKUP = 'pickup';
    public const TONE_TRANSIT = 'transit';

    public static function isFinal(string $code): bool
    {
        return in_array($code, self::FINAL_CODES, true);
    }

    /** The customer received the parcel. Waiting at an office or in a locker does not count. */
    public static function isDelivered(string $code): bool
    {
        return $code === self::DELIVERED;
    }

    /** The parcel came back to the shop (also after a refusal, see TrackingInterpreter). */
    public static function isReturned(string $code): bool
    {
        return $code === self::RETURNED;
    }

    /** Final, and the customer did not get the parcel: returned, cancelled or abandoned. */
    public static function isFailed(string $code): bool
    {
        return in_array($code, self::FAILED_CODES, true);
    }

    /**
     * Whether Pigeon Express still takes a change or a cancel. A status not
     * read yet is no reason to refuse: the carrier says so itself if the
     * shipment has moved on.
     */
    public static function isStillRegistered(string $code): bool
    {
        return $code === '' || $code === self::REGISTERED;
    }

    /** One of the TONE_* values, for colouring the status on the order screen. */
    public static function tone(string $code): string
    {
        if (self::isDelivered($code)) {
            return self::TONE_DELIVERED;
        }
        if (self::isFailed($code)) {
            return self::TONE_FAILED;
        }
        if (in_array($code, self::PICKUP_CODES, true)) {
            return self::TONE_PICKUP;
        }
        if (in_array($code, self::ATTENTION_CODES, true)) {
            return self::TONE_ATTENTION;
        }

        return self::TONE_TRANSIT;
    }

    /**
     * The module's own name for a code, or '' for a code it does not know.
     */
    public static function knownLabel(string $code): string
    {
        $labels = self::labels();

        return isset($labels[$code]) ? (string) $labels[$code] : '';
    }

    /**
     * Every status code the carrier lists, with its name.
     *
     * @return array<string, \Magento\Framework\Phrase>
     */
    public static function labels(): array
    {
        return [
            'shipment_registered'              => __('Shipment created'),
            'shipment_awaiting_courier_pickup' => __('Awaiting courier pickup'),
            'shipment_courier_assigned'        => __('Picked up by courier'),
            'shipment_accepted_by_courier'     => __('Accepted by courier'),
            'shipment_accepted_in_office'      => __('Accepted at office'),
            'shipment_in_sorting_center'       => __('At sorting centre'),
            'shipment_in_delivery'             => __('Out for delivery'),
            'shipment_delivery_attempt_failed' => __('Delivery attempt failed'),
            'shipment_delivered_to_office'     => __('Awaiting pickup at office or locker'),
            'shipment_left_in_locker'          => __('Left in parcel locker, awaiting pickup'),
            self::DELIVERED                    => __('Delivered'),
            'shipment_held_by_sender'          => __('Held at office'),
            'shipment_redirection_requested'   => __('Redirection requested'),
            'shipment_redirection_rejected'    => __('Redirection rejected'),
            self::REDIRECTED                   => __('Redirected'),
            'shipment_delivery_problem'        => __('Delivery problem'),
            'shipment_locker_time_expired'     => __('Parcel locker pickup time expired'),
            'shipment_storage_expired'         => __('Storage period expired'),
            'shipment_untracked'               => __('Not collected'),
            self::RETURNING_TO_SENDER          => __('Returning to sender'),
            self::RETURNED                     => __('Returned to the sender'),
            self::CANCELLED                    => __('Cancelled'),
            self::ABANDONED                    => __('Abandoned'),
            'complaint'                        => __('Complaint'),
        ];
    }

    public static function clean(string $code): string
    {
        return (string) preg_replace('/[^a-z0-9_]/', '', strtolower(trim($code)));
    }
}
