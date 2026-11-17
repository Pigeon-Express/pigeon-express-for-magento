<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Model\Shipment\HandInResolver;
use PigeonExpress\Shipping\Model\ShipmentEditor;

/**
 * Where a shipment is handed in is fixed at create: a change after which a
 * locker shipment no longer fits a locker is refused before it is sent.
 */
class ShipmentEditorLockerTest extends TestCase
{
    public function testAChangeThatStillFitsTheLockerGoesThrough(): void
    {
        $this->editor(['reasons' => [], 'services' => []])
            ->refuseLockerMisfit(['pickup_type' => 'locker'], ['packages' => [['weight' => 1.0]]], 1);

        $this->addToAssertionCount(1);
    }

    public function testAChangeThatNoLongerFitsIsRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('no longer fits one (over 20 kg)');

        $this->editor(['reasons' => ['weight'], 'services' => []])
            ->refuseLockerMisfit(['pickup_type' => 'locker'], ['packages' => [['weight' => 25.0]]], 1);
    }

    public function testAShipmentHandedInElsewhereIsNotChecked(): void
    {
        $handIn = $this->createMock(HandInResolver::class);
        $handIn->expects($this->never())->method('decide');

        (new ObjectManager($this))->getObject(ShipmentEditor::class, ['handIn' => $handIn])
            ->refuseLockerMisfit(['pickup_type' => 'office'], ['packages' => [['weight' => 25.0]]], 1);
    }

    /**
     * @param array{reasons: string[], services: string[]} $decision
     */
    private function editor(array $decision): ShipmentEditor
    {
        $handIn = $this->createMock(HandInResolver::class);
        $handIn->method('decide')->willReturn($decision);
        $handIn->method('reasonText')->willReturn('over 20 kg');

        return (new ObjectManager($this))->getObject(ShipmentEditor::class, ['handIn' => $handIn]);
    }
}
