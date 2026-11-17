<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Model\OrderAddressLocationPersistor;
use PigeonExpress\Shipping\Model\Package\PackageBuilder;
use PigeonExpress\Shipping\Model\Rate\LocationToApiIdResolver;
use PigeonExpress\Shipping\Model\Service\ServiceCodesBuilder;
use PigeonExpress\Shipping\Model\Shipment\AddressForm;
use PigeonExpress\Shipping\Model\Shipment\WhoPaysFacts;
use PigeonExpress\Shipping\Model\ShipmentSender;

/**
 * A shipment created by cron or in bulk, with nobody looking at the form:
 * the same who-pays facts as the order screen, no guessed street, the
 * customer's email, and the goods valued as WooCommerce values them.
 */
class ShipmentSenderUnattendedTest extends TestCase
{
    /** @var array<int, mixed> */
    private $validateArgs = [];

    /** @var float|null */
    private $goodsValue;

    public function testAFixedPriceCodOrderIsCheckedAsAFixedPrice(): void
    {
        $facts = $this->createMock(WhoPaysFacts::class);
        $facts->method('forOrder')->willReturn(['free' => false, 'cod' => true, 'fixed' => true, 'rule' => 'fixed']);
        $facts->method('nothingToCollect')->willReturn(false);

        $this->sender(['whoPaysFacts' => $facts], 'office')->payloadFor($this->order());

        $this->assertFalse($this->validateArgs[2], 'something is collected at the door');
        $this->assertTrue($this->validateArgs[3], 'fixed price');
    }

    public function testTheCustomerEmailAndTheGoodsValueAreSent(): void
    {
        $payload = $this->sender([], 'office')->payloadFor($this->order())['payload'];

        $this->assertSame('ivan@example.com', $payload['receiver_email']);
        // 130 paid, 10 of it the delivery (incl. tax), 2 of that discounted.
        $this->assertSame(122.0, $this->goodsValue);
    }

    public function testAnUnusableEmailIsLeftOut(): void
    {
        $payload = $this->sender([], 'office')->payloadFor($this->order('not-an-email'))['payload'];

        $this->assertArrayNotHasKey('receiver_email', $payload);
    }

    public function testAStreetThatMatchedNothingIsNotSentUnreviewed(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Pick the destination street');

        $this->sender([], 'address', ['street_id' => ''], [
            'street' => ['severity' => 'warning', 'message' => 'no such street'],
        ])->payloadFor($this->order());
    }

    public function testAnAmbiguousStreetIsNotSentUnreviewed(): void
    {
        $this->expectException(LocalizedException::class);

        $this->sender([], 'address', ['street_id' => '55'], [
            'street' => ['severity' => 'warning', 'message' => 'more than one'],
        ])->payloadFor($this->order());
    }

    public function testAnExactMatchIsSent(): void
    {
        $result = $this->sender([], 'address', ['street_id' => '55'])->payloadFor($this->order());

        $this->assertSame('address', $result['payload']['delivery_type']);
    }

    public function testAnAddressGivenThroughThePigeonFieldsAtCheckoutIsSent(): void
    {
        // The order carries the city id from checkout: nothing was guessed.
        $result = $this->sender([], 'address', ['street_id' => ''], [
            'street' => ['severity' => 'warning', 'message' => 'free text'],
        ], ['city_id' => '759'])->payloadFor($this->order());

        $this->assertSame('address', $result['payload']['delivery_type']);
    }

    public function testAFormTheAdminCheckedIsNotSecondGuessed(): void
    {
        $result = $this->sender([], 'address', ['street_id' => ''], [
            'street' => ['severity' => 'warning', 'message' => 'no such street'],
        ])->payloadFor($this->order(), ['address_fields' => ['delivery_type' => 'address']]);

        $this->assertSame('address', $result['payload']['delivery_type']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function phones(): array
    {
        return [
            'mobile'             => ['0881 234 567', '+359881234567'],
            'Sofia landline'     => ['021234567', '+35921234567'],
            'Plovdiv landline'   => ['032 123 456', '+35932123456'],
            'international'      => ['00359881234567', '+359881234567'],
            'with country code'  => ['+359 88 123 4567', '+359881234567'],
            'mobile without 0'   => ['881234567', '+359881234567'],
            'another country'    => ['+44 20 7946 0958', '+442079460958'],
        ];
    }

    /**
     * @dataProvider phones
     */
    public function testPhonesAreNormalizedAsWooCommerceDoes(string $typed, string $sent): void
    {
        $this->assertSame($sent, ShipmentSender::normalizePhone($typed));
    }

    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $args
     * @param array<string, mixed> $checkedFields
     * @param array<string, mixed> $issues
     * @param array<string, mixed> $fromOrder
     */
    private function sender(
        array $args,
        string $type,
        array $checkedFields = [],
        array $issues = [],
        array $fromOrder = []
    ): ShipmentSender {
        $form = $this->createMock(AddressForm::class);
        $form->method('fromOrder')->willReturn(array_merge(['delivery_type' => $type, 'city_id' => ''], $fromOrder));
        $form->method('validate')->willReturnCallback(
            function (...$validateArgs) use ($type, $checkedFields, $issues): array {
                $this->validateArgs = $validateArgs;

                return [
                    'fields' => array_merge([
                        'delivery_type' => $type,
                        'location_id'   => '7',
                        'contact_name'  => 'Иван Петров',
                        'contact_phone' => '+359881234567',
                        'note'          => '',
                        'who_pays'      => 'sender',
                        'street_id'     => '55',
                    ], $checkedFields),
                    'issues' => $issues,
                ];
            }
        );
        $form->method('hasErrors')->willReturn(false);
        $form->method('deliveryType')->willReturnCallback(static function (array $fields) {
            return (string) ($fields['delivery_type'] ?? 'address');
        });
        $form->method('toApiAddress')->willReturn(['city_id' => 759, 'street_id' => 55]);

        $persistor = $this->createMock(OrderAddressLocationPersistor::class);
        $persistor->method('getByAddressId')->willReturn(['delivery_type' => $type, 'location_id' => '7']);

        $locations = $this->createMock(LocationToApiIdResolver::class);
        $locations->method('resolveToApiId')->willReturn(149);

        $packages = $this->createMock(PackageBuilder::class);
        $packages->method('finish')->willReturn([['weight' => 1.0]]);

        $services = $this->createMock(ServiceCodesBuilder::class);
        $services->method('build')->willReturnCallback(function (...$buildArgs): array {
            $this->goodsValue = $buildArgs[5];

            return [];
        });

        return (new ObjectManager($this))->getObject(ShipmentSender::class, $args + [
            'addressForm'             => $form,
            'locationPersistor'       => $persistor,
            'locationToApiIdResolver' => $locations,
            'packageBuilder'          => $packages,
            'serviceCodesBuilder'     => $services,
        ]);
    }

    private function order(string $email = 'ivan@example.com'): OrderInterface
    {
        $address = $this->getMockBuilder(\Magento\Sales\Model\Order\Address::class)
            ->disableOriginalConstructor()->onlyMethods(['getId'])->getMock();
        $address->method('getId')->willReturn(3);

        $order = $this->getMockBuilder(\Magento\Sales\Model\Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getStoreId', 'getShippingAddress', 'getCustomerEmail', 'getGrandTotal', 'getShippingInclTax',
                'getShippingDiscountAmount', 'getIncrementId', 'getItems', 'getWeight',
            ])
            ->getMock();
        $order->method('getStoreId')->willReturn(1);
        $order->method('getShippingAddress')->willReturn($address);
        $order->method('getCustomerEmail')->willReturn($email);
        $order->method('getGrandTotal')->willReturn(130.0);
        $order->method('getShippingInclTax')->willReturn(10.0);
        $order->method('getShippingDiscountAmount')->willReturn(-2.0);
        $order->method('getIncrementId')->willReturn('000000045');
        $order->method('getItems')->willReturn([]);
        $order->method('getWeight')->willReturn(1.0);

        return $order;
    }
}
