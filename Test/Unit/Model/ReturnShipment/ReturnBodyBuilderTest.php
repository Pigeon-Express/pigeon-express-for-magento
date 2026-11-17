<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\ReturnShipment;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\Pickup\PickupPayload;
use PigeonExpress\Shipping\Model\ReturnShipment\ReturnBodyBuilder;

/**
 * The body of a return shipment: is_reverse with the customer as sender_*,
 * where the courier collects it, and where it is delivered.
 */
class ReturnBodyBuilderTest extends TestCase
{
    public function testAnAddressPickupToAnOffice(): void
    {
        $builder = $this->builder();
        $form = $builder->fromRequest([
            'sender_name'        => ' Иван Петров ',
            'sender_phone'       => '0888123456',
            'sender_email'       => 'ivan@example.com',
            'pickup_type'        => 'address',
            'city_id'            => '759',
            'street_id'          => '434699',
            'additional_info'    => '10, бл. 2',
            'delivery_type'      => 'office',
            'delivery_office_id' => '149',
            'packages'           => [['weight' => '0,5', 'length' => '', 'width' => '20', 'height' => '15'], ['weight' => '']],
            'notify_customer'    => '1',
        ]);

        $body = $builder->build($this->order(), $form, 1);

        self::assertTrue($body['is_reverse']);
        self::assertSame('Иван Петров', $body['sender_name']);
        self::assertSame('+359888123456', $body['sender_phone']);
        self::assertSame('ivan@example.com', $body['sender_email']);
        self::assertSame('address', $body['pickup_type']);
        self::assertSame(['city_id' => 759, 'additional_info' => '10, бл. 2', 'street_id' => 434699], $body['pickup_address']);
        self::assertSame('office', $body['delivery_type']);
        self::assertSame(149, $body['delivery_office_id']);
        self::assertSame([['weight' => 0.5, 'length' => 30, 'width' => 20, 'height' => 15]], $body['packages']);
        self::assertSame([['description' => 'Bag', 'quantity' => 2]], $body['inventory_items']);
        self::assertSame('receiver', $body['who_pays']);
        self::assertSame('000000043', $body['external_reference']);
        self::assertTrue($form['notify_customer']);
    }

    public function testALockerPickupNeedsAllDimensions(): void
    {
        $builder = $this->builder(0.0);
        $form = $builder->fromRequest([
            'sender_name' => 'A', 'sender_phone' => '0888123456', 'pickup_type' => 'locker', 'pickup_locker_id' => '7',
            'delivery_office_id' => '149', 'packages' => [['weight' => '1']],
        ]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('APS lockers require weight + all three dimensions.');
        $builder->build($this->order(), $form, 1);
    }

    public function testEveryProblemIsReportedAtOnce(): void
    {
        $builder = $this->builder();
        $form = $builder->fromRequest(['sender_phone' => '123', 'sender_email' => 'nope', 'pickup_type' => 'office']);

        try {
            $builder->build($this->order(), $form, 1);
            self::fail('No exception');
        } catch (LocalizedException $e) {
            $message = $e->getMessage();
            self::assertStringContainsString('Sender name is required.', $message);
            self::assertStringContainsString('is not a valid Bulgarian number', $message);
            self::assertStringContainsString('is not a valid email address', $message);
            self::assertStringContainsString('Pick the office where the customer hands the shipment in.', $message);
            self::assertStringContainsString('Pick the office the return shipment is delivered to.', $message);
            self::assertStringContainsString('Enter at least one package with its weight.', $message);
        }
    }

    public function testDeliveryToTheShopSendsTheTypedAddress(): void
    {
        $builder = $this->builder(30.0, ConfigInterface::PICKUP_TYPE_ADDRESS);
        $form = $builder->fromRequest([
            'sender_name' => 'A', 'sender_phone' => '0888123456', 'pickup_type' => 'office', 'pickup_office_id' => '5',
            'delivery_type' => 'address', 'packages' => [['weight' => '1']], 'who_pays' => 'sender',
        ]);

        $body = $builder->build($this->order(), $form, 1);

        self::assertSame('address', $body['delivery_type']);
        self::assertSame(['city_id' => 1, 'street_number' => '2'], $body['delivery_address']);
        self::assertSame('sender', $body['who_pays']);
        self::assertSame(['type' => 'address', 'office_id' => ''], $builder->defaultDelivery(1));
    }

    public function testTheDefaultDeliveryIsTheShopsOffice(): void
    {
        self::assertSame(['type' => 'office', 'office_id' => '149'], $this->builder()->defaultDelivery(1));
    }

    private function builder(float $size = 30.0, string $pickupType = ConfigInterface::PICKUP_TYPE_OFFICE): ReturnBodyBuilder
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('getDefaultLength')->willReturn($size);
        $config->method('getDefaultWidth')->willReturn($size);
        $config->method('getDefaultHeight')->willReturn($size);
        $config->method('getPickupType')->willReturn($pickupType);
        $config->method('getPickupOfficeId')->willReturn(149);
        $config->method('isHideContentOnLabel')->willReturn(false);

        $pickup = $this->createMock(PickupPayload::class);
        $pickup->method('typedAddress')->willReturn(['city_id' => 1, 'street_number' => '2']);

        return new ReturnBodyBuilder($config, $pickup);
    }

    private function order(): OrderInterface
    {
        $item = $this->createMock(OrderItemInterface::class);
        $item->method('getParentItemId')->willReturn(null);
        $item->method('getName')->willReturn('Bag');
        $item->method('getQtyOrdered')->willReturn(2.0);

        $order = $this->createMock(OrderInterface::class);
        $order->method('getItems')->willReturn([$item]);
        $order->method('getIncrementId')->willReturn('000000043');

        return $order;
    }
}
