<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Observer\Email;

use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Sales\Api\Data\OrderAddressExtensionInterfaceFactory;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Model\OrderAddressLocationPersistor;
use PigeonExpress\Shipping\Observer\Email\OrderTemplateVars;

class OrderTemplateVarsTest extends TestCase
{
    /** @var OrderTemplateVars */
    private $observer;

    protected function setUp(): void
    {
        $persistor = $this->createMock(OrderAddressLocationPersistor::class);
        $persistor->method('getByAddressId')->willReturn(null);

        $this->observer = new OrderTemplateVars(
            $persistor,
            $this->createMock(OrderAddressExtensionInterfaceFactory::class)
        );
    }

    /**
     * The order has to be a real double rather than a DataObject: the observer
     * guards with method_exists(), which does not see magic getters.
     *
     * @param array<string, string|null> $peData
     */
    private function order(string $method, array $peData): \Magento\Sales\Model\Order
    {
        // The extension attribute object answers getters, which DataObject does
        // by converting camelCase to snake_case, matching the keys used here.
        $address = new DataObject(['id' => 1, 'entity_id' => 1]);
        $address->setExtensionAttributes(new DataObject($peData));

        $order = $this->createMock(\Magento\Sales\Model\Order::class);
        $order->method('getShippingMethod')->willReturn($method);
        $order->method('getShippingAddress')->willReturn($address);
        $order->method('getStoreId')->willReturn(1);

        return $order;
    }

    /**
     * @param array<string, string|null> $peData
     */
    private function fire(string $method, array $peData): DataObject
    {
        $transport = new DataObject(['order' => $this->order($method, $peData)]);
        $this->observer->execute(new Observer([
            'event' => new Event(['transport' => $transport, 'transportObject' => $transport]),
        ]));

        return $transport;
    }

    /**
     * Whatever goes in here is read back with {{var shipping_msg}}, which
     * escapes it. Tags do not style the message, they are shown to the customer
     * as the tags they are, which is exactly what happened.
     */
    public function testPutsNoMarkupInTheMessage(): void
    {
        $msg = (string) $this->fire('pigeonexpress_office', [
            'pigeonexpress_delivery_type' => 'office',
            'pigeonexpress_location_name' => 'Бизнес Парк',
            'pigeonexpress_location_address' => 'АЛ.МАЛИНОВ, София, 1000',
        ])->getData('shipping_msg');

        $this->assertSame('Бизнес Парк, АЛ.МАЛИНОВ, София, 1000', $msg);
        $this->assertStringNotContainsString('<', $msg);
        $this->assertStringNotContainsString('>', $msg);
    }

    /**
     * Magento prints shipping_description immediately above this, and that
     * already reads "Pigeon Express - Office Delivery".
     */
    public function testDoesNotRepeatWhatTheDescriptionAlreadySays(): void
    {
        $msg = (string) $this->fire('pigeonexpress_office', [
            'pigeonexpress_delivery_type' => 'office',
            'pigeonexpress_location_name' => 'Бизнес Парк',
            'pigeonexpress_location_address' => 'АЛ.МАЛИНОВ, София, 1000',
        ])->getData('shipping_msg');

        $this->assertStringNotContainsString('Pigeon Express', $msg);
        $this->assertStringNotContainsString('Office Delivery', $msg);
    }

    /**
     * The template escapes. Escaping here as well turns an ampersand in a street
     * name into "&amp;" in front of the customer.
     */
    public function testDoesNotEscapeWhatTheTemplateWillEscape(): void
    {
        $msg = (string) $this->fire('pigeonexpress_office', [
            'pigeonexpress_delivery_type' => 'office',
            'pigeonexpress_location_name' => 'Smith & Sons',
            'pigeonexpress_location_address' => 'High St',
        ])->getData('shipping_msg');

        $this->assertSame('Smith & Sons, High St', $msg);
        $this->assertStringNotContainsString('&amp;', $msg);
    }

    public function testAddsTheCourierNoteWhenThereIsOne(): void
    {
        $msg = (string) $this->fire('pigeonexpress_aps', [
            'pigeonexpress_delivery_type' => 'aps',
            'pigeonexpress_location_name' => 'Билла',
            'pigeonexpress_location_address' => 'София, 1000',
            'pigeonexpress_instructions' => 'ring twice',
        ])->getData('shipping_msg');

        $this->assertStringContainsString('Билла, София, 1000', $msg);
        $this->assertStringContainsString('ring twice', $msg);
    }

    /**
     * Address delivery has no counter to name, so there is nothing to add and
     * the stock description stands on its own.
     */
    public function testSaysNothingWhenThereIsNothingToAdd(): void
    {
        $transport = $this->fire('pigeonexpress_address', [
            'pigeonexpress_delivery_type' => 'address',
        ]);

        $this->assertNull($transport->getData('shipping_msg'));
        $this->assertFalse($transport->getData('pigeonexpress_show'));
    }

    public function testLeavesOtherCarriersAlone(): void
    {
        $transport = $this->fire('flatrate_flatrate', [
            'pigeonexpress_location_name' => 'should never be read',
        ]);

        $this->assertNull($transport->getData('shipping_msg'));
    }

    /**
     * Another module may have put something there first.
     */
    public function testKeepsWhatWasAlreadyInTheMessage(): void
    {
        $transport = new DataObject([
            'order' => $this->order('pigeonexpress_office', [
                'pigeonexpress_delivery_type' => 'office',
                'pigeonexpress_location_name' => 'Бизнес Парк',
            ]),
            'shipping_msg' => 'Delivered on weekdays.',
        ]);

        $this->observer->execute(new Observer([
            'event' => new Event(['transport' => $transport, 'transportObject' => $transport]),
        ]));

        $this->assertSame('Delivered on weekdays. Бизнес Парк', $transport->getData('shipping_msg'));
    }
}
