<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Api\RateClientInterface;
use PigeonExpress\Shipping\Model\Package\PackageBuilder;
use PigeonExpress\Shipping\Model\Rate\LocationToApiIdResolver;
use PigeonExpress\Shipping\Model\Service\ServiceCodesBuilder;
use PigeonExpress\Shipping\Model\Shipment\AddressForm;
use PigeonExpress\Shipping\Model\ShipmentSender;

/**
 * The Recalculate button asks what the carriage costs. It must not quote a
 * figure that includes cash on delivery: that fee is charged to the customer
 * through the order total, so adding it here would show the merchant a number
 * nobody is being asked to pay, and it is exactly the double counting this
 * module was fixed to remove.
 */
class ShipmentSenderPriceTest extends TestCase
{
    /** @var array<string, mixed> Payload the sender handed to the rate client */
    private $payload = [];

    public function testCashOnDeliveryIsLeftOutOfTheQuote(): void
    {
        $codIncluded = null;

        $this->price(['delivery_type' => 'address'], function (...$args) use (&$codIncluded) {
            $codIncluded = $args[4];
            return [];
        });

        $this->assertFalse($codIncluded, 'the quote is what carriage costs, not what the customer pays');
    }

    public function testTheQuoteIsForTheDeliveryTypeOnTheForm(): void
    {
        // The whole point of the button is pricing a redirect, so it has to
        // follow the form rather than what the order was placed as.
        $this->price(['delivery_type' => 'office', 'location_id' => '7']);

        $this->assertSame('office', $this->payload['delivery_type']);
        $this->assertSame(149, $this->payload['delivery_office_id']);
        $this->assertArrayNotHasKey('delivery_address', $this->payload);
    }

    public function testALockerIsSentAsALocker(): void
    {
        // The API calls it "locker" while the module calls it "aps" throughout.
        $this->price(['delivery_type' => 'aps', 'location_id' => '7']);

        $this->assertSame('locker', $this->payload['delivery_type']);
    }

    public function testTheQuoteCarriesNoRecipientDetails(): void
    {
        // Calculate prices a parcel, not a person. Sending the contact would leak
        // customer data into a call that has no use for it.
        $this->price(['delivery_type' => 'office', 'location_id' => '7']);

        $this->assertArrayNotHasKey('receiver_name', $this->payload);
        $this->assertArrayNotHasKey('receiver_phone', $this->payload);
        $this->assertArrayNotHasKey('inventory_items', $this->payload);
    }

    public function testThePriceComesBackFromTheApi(): void
    {
        $result = $this->price(['delivery_type' => 'office', 'location_id' => '7'], null, [
            'total_price' => 14.68,
            'currency'    => 'EUR',
        ]);

        $this->assertSame(14.68, $result['price']);
        $this->assertSame('EUR', $result['currency']);
    }

    // -----------------------------------------------------------------------

    /**
     * @param array<string, string> $fields
     * @param callable|null $onServiceCodes Receives the ServiceCodesBuilder arguments
     * @param array<string, mixed> $calculation
     * @return array{price: float, currency: string}
     */
    private function price(array $fields, ?callable $onServiceCodes = null, array $calculation = []): array
    {
        $rateClient = $this->createMock(RateClientInterface::class);
        $rateClient->method('calculate')->willReturnCallback(
            function (array $payload) use (&$calculation) {
                $this->payload = $payload;

                return $calculation ?: ['total_price' => 1.0, 'currency' => 'EUR'];
            }
        );

        $services = $this->createMock(ServiceCodesBuilder::class);
        $services->method('build')->willReturnCallback(
            static function (...$args) use ($onServiceCodes) {
                return $onServiceCodes ? $onServiceCodes(...$args) : [];
            }
        );

        $config = $this->createMock(ConfigInterface::class);
        $config->method('getPickupType')->willReturn(ConfigInterface::PICKUP_TYPE_OFFICE);
        $config->method('getPickupOfficeId')->willReturn(149);

        $locations = $this->createMock(LocationToApiIdResolver::class);
        $locations->method('resolveToApiId')->willReturn(149);

        $packages = $this->createMock(PackageBuilder::class);
        $packages->method('build')->willReturn([['weight' => 1.0, 'length' => 10.0, 'width' => 10.0, 'height' => 10.0]]);

        $form = $this->createMock(AddressForm::class);
        $form->method('deliveryType')->willReturnCallback(
            static function (array $f): string {
                return $f['delivery_type'] ?? 'address';
            }
        );
        $form->method('toApiAddress')->willReturn(['city_id' => 1, 'additional_info' => 'Address']);

        $sender = (new ObjectManager($this))->getObject(ShipmentSender::class, [
            'config'                  => $config,
            'rateClient'              => $rateClient,
            'locationToApiIdResolver' => $locations,
            'addressForm'             => $form,
            'packageBuilder'          => $packages,
            'serviceCodesBuilder'     => $services,
        ]);

        return $sender->priceFor($this->order(), $fields);
    }

    private function order(): OrderInterface
    {
        $order = $this->getMockBuilder(OrderInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $order->method('getStoreId')->willReturn(1);
        $order->method('getSubtotal')->willReturn(100.0);
        $order->method('getWeight')->willReturn(1.0);
        $order->method('getItems')->willReturn([]);

        return $order;
    }
}
