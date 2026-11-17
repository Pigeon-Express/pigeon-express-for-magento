<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Ui\Component\Listing\Column;

use Magento\Framework\Escaper;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Model\Magento\Grid\GridFacts;
use PigeonExpress\Shipping\Model\Magento\Grid\StateFilter;
use PigeonExpress\Shipping\Model\Status\StatusNames;
use PigeonExpress\Shipping\Ui\Component\Listing\Column\PigeonExpress;

/**
 * "Courier requested" under the waybill while an open request holds it and
 * the courier has not collected it, as WooCommerce shows it.
 */
class PigeonExpressColumnTest extends TestCase
{
    public function testAnOpenRequestShowsCourierRequested(): void
    {
        $cell = $this->cell('shipment_registered', ['P1' => ['request_number' => 'CR-1', 'pickup_date' => '2026-11-17',
            'time_type' => 'interval', 'time_from' => '10:00', 'time_to' => '17:00']]);

        $this->assertStringContainsString('Courier requested', $cell);
        $this->assertStringContainsString('CR-1 · 17.11.2026, 10:00 - 17:00', $cell);
    }

    public function testNoLineWithoutARequest(): void
    {
        $this->assertStringNotContainsString('Courier requested', $this->cell('shipment_registered', []));
    }

    public function testNoLineOnceTheCourierHasTheParcel(): void
    {
        $cell = $this->cell('shipment_courier_assigned', ['P1' => ['request_number' => 'CR-1']]);

        $this->assertStringNotContainsString('Courier requested', $cell);
    }

    /**
     * @param array<string, array<string, mixed>> $requests
     */
    private function cell(string $code, array $requests): string
    {
        $facts = $this->createMock(GridFacts::class);
        $facts->method('shippingMethods')->willReturn([10 => 'pigeonexpress_address']);
        $facts->method('openFor')->willReturn($requests);

        $names = $this->createMock(StatusNames::class);
        $names->method('label')->willReturn('Status');

        $escaper = $this->createMock(Escaper::class);
        $escaper->method('escapeHtml')->willReturnCallback(static function ($v) {
            return htmlspecialchars((string) $v);
        });
        $escaper->method('escapeHtmlAttr')->willReturnCallback(static function ($v) {
            return htmlspecialchars((string) $v);
        });

        /** @var PigeonExpress $column */
        $column = (new ObjectManager($this))->getObject(PigeonExpress::class, [
            'statusNames' => $names,
            'escaper'     => $escaper,
            'gridFacts'   => $facts,
            'stateFilter' => new StateFilter(),
            'data'        => ['name' => 'pigeonexpress_state'],
        ]);

        $source = $column->prepareDataSource(['data' => ['items' => [[
            'entity_id'                 => 10,
            'pigeonexpress_reference'   => 'P1',
            'pigeonexpress_lifecycle'   => 'sent',
            'pigeonexpress_status_code' => $code,
        ]]]]);

        return (string) $source['data']['items'][0]['pigeonexpress_state'];
    }
}
