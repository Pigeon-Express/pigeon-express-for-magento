<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Status;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Model\Shipment;
use PigeonExpress\Shipping\Model\ShipmentCanceller;
use PigeonExpress\Shipping\Model\Status\StatusRecorder;

/**
 * Recording is idempotent, and the Cancel button follows the carrier status.
 */
class StatusRecorderTest extends TestCase
{
    public function testTheSameStatusAgainChangesNothing(): void
    {
        $recorder = (new ObjectManager($this))->getObject(StatusRecorder::class);
        $row = $this->row('shipment_in_delivery', 'В доставка', '2026-11-11 07:00:00');

        self::assertFalse($recorder->differs($row, 'shipment_in_delivery', '', ''));
        self::assertFalse($recorder->differs($row, 'shipment_in_delivery', 'В доставка', '2026-11-11 07:00:00'));
        self::assertTrue($recorder->differs($row, 'shipment_delivered_to_recipient', '', ''));
        self::assertTrue($recorder->differs($row, 'shipment_in_delivery', '', '2026-11-12 07:00:00'));
    }

    public function testCarrierTimesAreStoredInUtc(): void
    {
        $recorder = (new ObjectManager($this))->getObject(StatusRecorder::class);

        self::assertSame('2026-11-11 09:32:02', $recorder->toUtc('2026-11-11T11:32:02+02:00'));
        self::assertSame('', $recorder->toUtc(''));
    }

    public function testCancelHidesOnceTheCourierHasTheParcel(): void
    {
        $canceller = (new ObjectManager($this))->getObject(ShipmentCanceller::class);

        self::assertTrue($canceller->isCancellable($this->row('', '', null)));
        self::assertTrue($canceller->isCancellable($this->row('shipment_registered', '', null)));
        self::assertFalse($canceller->isCancellable($this->row('shipment_courier_assigned', '', null)));
        self::assertFalse($canceller->isCancellable($this->row('shipment_delivered_to_recipient', '', null)));
    }

    private function row(string $code, string $name, ?string $at): Shipment
    {
        $row = $this->getMockBuilder(Shipment::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $row->setData([
            'status'      => ShipmentCanceller::STATUS_REGISTERED,
            'status_code' => $code !== '' ? $code : null,
            'status_name' => $name,
            'status_at'   => $at,
        ]);

        return $row;
    }
}
