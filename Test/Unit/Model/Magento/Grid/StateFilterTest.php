<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Magento\Grid;

use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Model\Magento\Grid\StateFilter;

class StateFilterTest extends TestCase
{
    /**
     * @return array<string, array{0:string, 1:?string, 2:?string, 3:?string, 4?:bool}>
     */
    public static function cases(): array
    {
        return [
            'not a Pigeon order' => ['flatrate_flatrate', null, null, null],
            'Pigeon order, no waybill' => ['pigeonexpress_office', null, null, StateFilter::STATE_NONE],
            'waybill cancelled by the shop' => ['pigeonexpress_office', 'cancelled', 'shipment_registered',
                StateFilter::STATE_NONE],
            // Waiting for courier means a courier request is open, as in WooCommerce.
            'created, not read yet, no request' => ['pigeonexpress_address', 'shipment_registered', null,
                StateFilter::STATE_TRANSIT],
            'created, no request' => ['pigeonexpress_address', 'shipment_registered', 'shipment_registered',
                StateFilter::STATE_TRANSIT],
            'created, courier requested' => ['pigeonexpress_address', 'sent', 'shipment_registered',
                StateFilter::STATE_WAITING, true],
            'not read yet, courier requested' => ['pigeonexpress_address', 'sent', null,
                StateFilter::STATE_WAITING, true],
            'awaiting courier, courier requested' => ['pigeonexpress_address', 'sent',
                'shipment_awaiting_courier_pickup', StateFilter::STATE_WAITING, true],
            'collected, request still open' => ['pigeonexpress_address', 'sent', 'shipment_courier_assigned',
                StateFilter::STATE_TRANSIT, true],
            'delivered, request still open' => ['pigeonexpress_address', 'sent', 'shipment_delivered_to_recipient',
                StateFilter::STATE_DELIVERED, true],
            'picked up' => ['pigeonexpress_aps', 'sent', 'shipment_courier_assigned', StateFilter::STATE_TRANSIT],
            'waiting at the office is not delivered' => ['pigeonexpress_office', 'sent',
                'shipment_delivered_to_office', StateFilter::STATE_TRANSIT],
            'delivered' => ['pigeonexpress_office', 'sent', 'shipment_delivered_to_recipient',
                StateFilter::STATE_DELIVERED],
            'returned' => ['pigeonexpress_office', 'sent', 'shipment_returned', StateFilter::STATE_FAILED],
            'abandoned' => ['pigeonexpress_office', 'sent', 'shipment_abandoned', StateFilter::STATE_FAILED],
            'cancelled by the carrier' => ['pigeonexpress_office', 'sent', 'shipment_cancelled',
                StateFilter::STATE_FAILED],
            'returning is still on its way' => ['pigeonexpress_office', 'sent', 'shipment_returning_to_sender',
                StateFilter::STATE_TRANSIT],
            'waybill on an order whose method changed' => ['flatrate_flatrate', 'sent',
                'shipment_in_delivery', StateFilter::STATE_TRANSIT],
        ];
    }

    /**
     * @dataProvider cases
     */
    public function testStateOf(
        string $method,
        ?string $lifecycle,
        ?string $code,
        ?string $expected,
        bool $courier = false
    ): void {
        $this->assertSame($expected, (new StateFilter())->stateOf($method, $lifecycle, $code, $courier));
    }

    public function testWaitingNeedsTheCourierCondition(): void
    {
        $without = (new StateFilter())->expression('pe', 'm');
        $with = (new StateFilter())->expression('pe', 'm', true, 'EXISTS (SELECT 1)');

        $this->assertStringContainsString('(1 = 0) AND', $without);
        $this->assertStringContainsString('(EXISTS (SELECT 1)) AND', $with);
    }

    public function testExpressionNamesEveryState(): void
    {
        $sql = (new StateFilter())->expression('pe', 'so.shipping_method');

        foreach (array_keys((new StateFilter())->options()) as $state) {
            $this->assertStringContainsString("'" . $state . "'", $sql);
        }
        $this->assertStringContainsString("'shipment_delivered_to_recipient'", $sql);
        $this->assertStringContainsString("'shipment_awaiting_courier_pickup'", $sql);
    }

    public function testEveryOptionIsAState(): void
    {
        $this->assertSame(
            [
                StateFilter::STATE_DELIVERED,
                StateFilter::STATE_TRANSIT,
                StateFilter::STATE_FAILED,
                StateFilter::STATE_WAITING,
                StateFilter::STATE_NONE,
            ],
            array_keys((new StateFilter())->options())
        );
    }

    public function testWithoutTheStatusColumnAWaybillIsWaiting(): void
    {
        $this->assertStringNotContainsString('status_code', (new StateFilter())->expression('pe', 'm', false));
    }
}
