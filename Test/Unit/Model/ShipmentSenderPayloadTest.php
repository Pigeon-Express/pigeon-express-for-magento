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
use PigeonExpress\Shipping\Api\LocationSearchInterface;
use PigeonExpress\Shipping\Api\RateClientInterface;
use PigeonExpress\Shipping\Logger\Logger;
use PigeonExpress\Shipping\Model\Account\SavedAddresses;
use PigeonExpress\Shipping\Model\Package\PackageBuilder;
use PigeonExpress\Shipping\Model\Pickup\PickupPayload;
use PigeonExpress\Shipping\Model\Rate\LocationToApiIdResolver;
use PigeonExpress\Shipping\Model\Service\ServiceCodesBuilder;
use PigeonExpress\Shipping\Model\Shipment\AddressForm;
use PigeonExpress\Shipping\Model\Shipment\LabelContents;
use PigeonExpress\Shipping\Model\Shipment\ReturnDestination;
use PigeonExpress\Shipping\Model\ShipmentSender;

/**
 * The parts of the payload the settings and the shipment form add: returns,
 * the form's service choices, its package rows, and the shared pickup.
 */
class ShipmentSenderPayloadTest extends TestCase
{
    public function testReturnAtMyExpenseAndTheReturnOfficeAreSent(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('isReturnAtMyExpense')->willReturn(true);
        $config->method('getReturnOfficeId')->willReturn(149);

        $this->assertSame(
            ['return_at_my_expense' => true, 'return_office_id' => 149],
            $this->returnFields($config, [])
        );
    }

    public function testNothingIsSentWhenTheAccountDecides(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('getReturnOfficeId')->willReturn(null);
        $config->method('getReturnAddressId')->willReturn(null);

        $this->assertSame([], $this->returnFields($config, ['service_return_documents' => true]));
    }

    public function testASavedAddressTheAccountListsIsSent(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('getReturnAddressId')->willReturn(130);

        $this->assertSame(['return_address_id' => 130], $this->returnFields($config, [], [130]));
    }

    public function testASavedAddressTheAccountNoLongerListsIsLeftOut(): void
    {
        // The carrier would refuse the whole shipment; the account decides instead.
        $config = $this->createMock(ConfigInterface::class);
        $config->method('getReturnAddressId')->willReturn(131);

        $this->assertSame([], $this->returnFields($config, [], [130]));
    }

    public function testAnOfficeWinsOverAnAddressNeverBoth(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('getReturnOfficeId')->willReturn(149);
        $config->method('getReturnAddressId')->willReturn(130);

        $this->assertSame(['return_office_id' => 149], $this->returnFields($config, [], [130]));
    }

    public function testTheDocumentsDestinationGoesOnlyWithADocumentsService(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('getReturnOfficeId')->willReturn(149);
        $config->method('getReturnDocumentsOfficeId')->willReturn(77);

        $this->assertSame(['return_office_id' => 149], $this->returnFields($config, ['cod_amount' => 12.5]));
        $this->assertSame(
            ['return_office_id' => 149],
            $this->returnFields($config, ['service_return_documents' => false]),
            'a service switched off does not count'
        );
        foreach (ReturnDestination::DOCUMENT_SERVICE_CODES as $code) {
            $this->assertSame(
                ['return_office_id' => 149, 'return_documents_office_id' => 77],
                $this->returnFields($config, [$code => true]),
                $code
            );
        }
    }

    public function testADocumentsAddressIsCheckedAgainstTheAccountToo(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('getReturnDocumentsAddressId')->willReturn(131);

        $this->assertSame([], $this->returnFields($config, ['paper_return_receipt' => true], [130]));
    }

    public function testTheReturnDocumentsChoiceStaysSwitchedOff(): void
    {
        // The test environment refuses it, so the shop cannot choose it yet
        // and the settings getters never give a documents destination.
        $this->assertFalse(ConfigInterface::RETURN_DOCUMENTS_CHOICE);
    }

    public function testTheFormServiceKeysBecomeOverrides(): void
    {
        $overrides = $this->call($this->sender([]), 'serviceOverrides', [
            'declared_value'        => '1',
            'declared_value_amount' => '120,50',
            'review'                => 'view',
            'return_receipt'        => 'both',
        ]);

        $this->assertSame([
            'declared_value'        => true,
            'declared_value_amount' => 120.5,
            'review'                => 'view',
            'return_receipt'        => 'both',
        ], $overrides);
    }

    public function testAnOldFormWithoutTheKeysLeavesTheSettingsInCharge(): void
    {
        $this->assertSame([], $this->call($this->sender([]), 'serviceOverrides', ['note' => 'x', 'review' => '']));
    }

    public function testTheFormPackageRowsReplaceTheOrderParcels(): void
    {
        $rows = [['weight' => 1.0, 'length' => 10, 'width' => 10, 'height' => 10, 'description' => 'А', 'fragile' => true]];
        $packages = $this->createMock(PackageBuilder::class);
        $packages->expects($this->once())->method('fromForm')->with($rows, 'office', 1)->willReturn([['weight' => 1.0]]);
        $packages->expects($this->never())->method('build');
        $packages->expects($this->once())->method('finish')
            ->with([['weight' => 1.0]], 'Дрехи', 1)
            ->willReturn([['weight' => 1.0, 'description' => 'Дрехи']]);

        $labels = $this->createMock(LabelContents::class);
        $labels->method('packageDescription')->willReturn('Дрехи');

        $result = $this->call(
            $this->sender(['packageBuilder' => $packages, 'labelContents' => $labels]),
            'buildPackages',
            $this->order(),
            1,
            'office',
            ['packages' => $rows]
        );

        $this->assertSame([['weight' => 1.0, 'description' => 'Дрехи']], $result);
    }

    public function testAQuoteAsksForThePickupAsTheCalculateEndpointTakesIt(): void
    {
        $pickup = $this->createMock(PickupPayload::class);
        $pickup->expects($this->once())->method('build')
            ->with(1, $this->anything(), $this->anything(), true)
            ->willReturn(['pickup_type' => 'address', 'pickup_address' => ['city_id' => 759]]);

        $sent = [];
        $rate = $this->createMock(RateClientInterface::class);
        $rate->method('calculate')->willReturnCallback(static function (array $payload) use (&$sent): array {
            $sent = $payload;

            return ['total_price' => 2.0, 'currency' => 'EUR'];
        });

        $form = $this->createMock(AddressForm::class);
        $form->method('deliveryType')->willReturn('office');
        $locations = $this->createMock(LocationToApiIdResolver::class);
        $locations->method('resolveToApiId')->willReturn(149);
        $packages = $this->createMock(PackageBuilder::class);
        $packages->method('finish')->willReturn([['weight' => 1.0]]);

        $this->sender([
            'pickupPayload'           => $pickup,
            'rateClient'              => $rate,
            'addressForm'             => $form,
            'locationToApiIdResolver' => $locations,
            'packageBuilder'          => $packages,
            'serviceCodesBuilder'     => $this->createMock(ServiceCodesBuilder::class),
        ])->priceFor($this->order(), ['delivery_type' => 'office', 'location_id' => '7']);

        $this->assertSame('address', $sent['pickup_type']);
        $this->assertSame(['city_id' => 759], $sent['pickup_address']);
    }

    // -----------------------------------------------------------------------

    /**
     * returnFields() of a sender whose return destinations come from these
     * settings and an account that lists these saved addresses.
     *
     * @param array<string, mixed> $serviceCodes
     * @param int[] $savedAddressIds
     * @return array<string, mixed>
     */
    private function returnFields(ConfigInterface $config, array $serviceCodes, array $savedAddressIds = []): array
    {
        $addresses = $this->createMock(SavedAddresses::class);
        $addresses->method('find')->willReturnCallback(static function (int $id) use ($savedAddressIds): ?array {
            return in_array($id, $savedAddressIds, true) ? ['id' => $id, 'label' => 'Address ' . $id] : null;
        });
        $destination = new ReturnDestination(
            $config,
            $addresses,
            $this->createMock(LocationSearchInterface::class),
            $this->createMock(Logger::class)
        );

        return $this->call(
            $this->sender(['config' => $config, 'returnDestination' => $destination]),
            'returnFields',
            1,
            $serviceCodes
        );
    }

    /**
     * @param array<string, mixed> $args
     */
    private function sender(array $args): ShipmentSender
    {
        return (new ObjectManager($this))->getObject(ShipmentSender::class, $args);
    }

    /**
     * @param mixed ...$args
     * @return mixed
     */
    private function call(ShipmentSender $sender, string $method, ...$args)
    {
        $reflection = (new \ReflectionClass(ShipmentSender::class))->getMethod($method);
        $reflection->setAccessible(true);

        return $reflection->invoke($sender, ...$args);
    }

    private function order(): OrderInterface
    {
        $order = $this->getMockBuilder(OrderInterface::class)->disableOriginalConstructor()->getMockForAbstractClass();
        $order->method('getStoreId')->willReturn(1);
        $order->method('getSubtotal')->willReturn(100.0);
        $order->method('getItems')->willReturn([]);

        return $order;
    }
}
