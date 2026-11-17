<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Status;

use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Model\Status\StatusCodes;
use PigeonExpress\Shipping\Model\Status\TrackingInterpreter;

/**
 * Delivered, returned and refused as the WooCommerce plugin reads them.
 */
class StatusClassificationTest extends TestCase
{
    public function testFinalAndFailedCodes(): void
    {
        self::assertTrue(StatusCodes::isFinal('shipment_delivered_to_recipient'));
        self::assertTrue(StatusCodes::isFinal('shipment_returned'));
        self::assertTrue(StatusCodes::isFinal('shipment_cancelled'));
        self::assertTrue(StatusCodes::isFinal('shipment_abandoned'));
        self::assertFalse(StatusCodes::isFinal('shipment_in_delivery'));
        self::assertFalse(StatusCodes::isFinal('shipment_returning_to_sender'));

        self::assertTrue(StatusCodes::isFailed('shipment_returned'));
        self::assertFalse(StatusCodes::isFailed('shipment_delivered_to_recipient'));
    }

    public function testWaitingAtAnOfficeIsNotDelivered(): void
    {
        self::assertFalse(StatusCodes::isDelivered('shipment_delivered_to_office'));
        self::assertFalse(StatusCodes::isDelivered('shipment_left_in_locker'));
        self::assertTrue(StatusCodes::isDelivered('shipment_delivered_to_recipient'));
        self::assertSame(StatusCodes::TONE_PICKUP, StatusCodes::tone('shipment_left_in_locker'));
        self::assertSame(StatusCodes::TONE_ATTENTION, StatusCodes::tone('shipment_untracked'));
        self::assertSame(StatusCodes::TONE_TRANSIT, StatusCodes::tone('shipment_in_sorting_center'));
    }

    public function testOnlyARegisteredOrUnreadShipmentCanStillBeChanged(): void
    {
        self::assertTrue(StatusCodes::isStillRegistered(''));
        self::assertTrue(StatusCodes::isStillRegistered('shipment_registered'));
        self::assertFalse(StatusCodes::isStillRegistered('shipment_courier_assigned'));
    }

    public function testAPlainEntryGivesItsOwnStatusAndTheTimeOfThatEvent(): void
    {
        $status = (new TrackingInterpreter())->interpret([
            'found'       => true,
            'status_code' => 'shipment_in_delivery',
            'status'      => 'В доставка',
            'tracking'    => [
                ['status_code' => 'shipment_registered', 'created_at' => '2026-11-10T10:00:00+02:00'],
                ['status_code' => 'shipment_in_delivery', 'created_at' => '2026-11-11T09:00:00+02:00'],
            ],
        ]);

        self::assertSame('shipment_in_delivery', $status['code']);
        self::assertSame('В доставка', $status['name']);
        self::assertSame('2026-11-11T09:00:00+02:00', $status['at']);
    }

    public function testAnUnknownReferenceGivesNothing(): void
    {
        self::assertNull((new TrackingInterpreter())->interpret(['found' => false, 'error' => 'Not found']));
    }

    public function testARefusedParcelComingBackIsReturnedPendingConfirmation(): void
    {
        $status = (new TrackingInterpreter())->interpret([
            'found'            => true,
            'reference_number' => 'P1',
            'status_code'      => 'shipment_untracked',
            'chain_after'      => [[
                'reference_number' => 'P2',
                'status_code'      => 'shipment_delivered_to_recipient',
                'created_at'       => '2026-11-12T10:00:00+02:00',
                'delivery_date'    => '2026-11-14T12:00:00+02:00',
            ]],
        ]);

        self::assertSame(StatusCodes::RETURNED, $status['code']);
        self::assertTrue(StatusCodes::isReturned($status['code']));
        self::assertSame('P1', $status['confirm']['parent']);
        self::assertSame('P2', $status['confirm']['reference']);
        self::assertSame('shipment_untracked', $status['fallback']['code']);
    }

    public function testAParcelOnItsWayBackIsReturningToSender(): void
    {
        $status = (new TrackingInterpreter())->interpret([
            'status_code' => 'shipment_untracked',
            'chain_after' => [['reference_number' => 'P2', 'status_code' => 'shipment_in_delivery']],
        ]);

        self::assertSame(StatusCodes::RETURNING_TO_SENDER, $status['code']);
        self::assertFalse(StatusCodes::isFinal($status['code']));
    }

    public function testARedirectCarriesTheParcelOnToTheCustomer(): void
    {
        $status = (new TrackingInterpreter())->interpret([
            'status_code' => 'shipment_redirected',
            'chain_after' => [[
                'reference_number' => 'P2',
                'status_code'      => 'shipment_delivered_to_recipient',
                'delivery_date'    => '2026-11-14T12:00:00+02:00',
            ]],
        ]);

        self::assertSame(StatusCodes::DELIVERED, $status['code']);
        self::assertArrayNotHasKey('confirm', $status);
    }

    public function testADeliveredShipmentStaysDeliveredWhateverFollows(): void
    {
        $status = (new TrackingInterpreter())->interpret([
            'status_code' => 'shipment_delivered_to_recipient',
            'chain_after' => [['reference_number' => 'D1', 'status_code' => 'shipment_in_delivery']],
        ]);

        self::assertSame(StatusCodes::DELIVERED, $status['code']);
    }

    public function testDetailsWithALinkAfterThemLeaveTheStatusToTracking(): void
    {
        $interpreter = new TrackingInterpreter();

        self::assertNull($interpreter->fromDetails([
            'status'            => ['code' => 'shipment_untracked', 'name' => 'x'],
            'related_shipments' => [['reference_number' => 'P2', 'relation' => 'return_to_sender']],
        ]));

        $status = $interpreter->fromDetails([
            'status'     => ['code' => 'shipment_registered', 'name' => 'Създадена'],
            'created_at' => '2026-11-16T10:00:00+02:00',
        ]);
        self::assertSame('shipment_registered', $status['code']);
        self::assertSame('2026-11-16T10:00:00+02:00', $status['at']);
    }
}
