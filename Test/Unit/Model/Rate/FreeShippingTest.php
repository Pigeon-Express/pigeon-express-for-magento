<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Rate;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Quote\Model\Quote\Address\RateRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\OrderAddressLocationPersistor;
use PigeonExpress\Shipping\Model\Rate\FreeShipping;

/**
 * The one place the threshold is decided. Everything downstream reads the price
 * the carrier produced rather than asking again, so a mistake here is a delivery
 * given away or a customer charged for one that was promised free.
 */
class FreeShippingTest extends TestCase
{
    private const STORE_ID = 1;

    /** @var ObjectManager */
    private $objectManager;

    /** @var ConfigInterface&MockObject */
    private $config;

    /** @var ScopeConfigInterface&MockObject */
    private $scopeConfig;

    /** @var OrderAddressLocationPersistor&MockObject */
    private $orderAddresses;

    /** @var FreeShipping */
    private $model;

    protected function setUp(): void
    {
        $this->objectManager = new ObjectManager($this);
        $this->config = $this->createMock(ConfigInterface::class);
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->orderAddresses = $this->createMock(OrderAddressLocationPersistor::class);

        $this->model = $this->objectManager->getObject(FreeShipping::class, [
            'config' => $this->config,
            'scopeConfig' => $this->scopeConfig,
            'orderAddresses' => $this->orderAddresses,
        ]);
    }

    public function testACartExactlyOnTheThresholdIsFree(): void
    {
        // The comparison is inclusive, as it is in every core carrier. A cart of
        // exactly the advertised amount qualifying is what a customer reading
        // "free over 50" expects.
        $this->threshold('office', true, 50.0);

        $this->assertTrue(
            $this->model->appliesToRequest($this->request(50.0), 'office', self::STORE_ID)
        );
    }

    public function testACartAPennyShortIsNotFree(): void
    {
        $this->threshold('office', true, 50.0);

        $this->assertFalse(
            $this->model->appliesToRequest($this->request(49.99), 'office', self::STORE_ID)
        );
    }

    public function testAStoredThresholdDoesNothingWhileTheToggleIsOff(): void
    {
        $this->threshold('office', false, 50.0);

        $this->assertFalse(
            $this->model->appliesToRequest($this->request(500.0), 'office', self::STORE_ID)
        );
    }

    public function testAThresholdOfZeroIsNoThresholdRatherThanFreeForEveryone(): void
    {
        // An empty field reads as zero. Treating that as "every cart qualifies"
        // would give the shop away the moment someone ticked the toggle without
        // filling the amount in.
        $this->threshold('office', true, 0.0);

        $this->assertFalse(
            $this->model->appliesToRequest($this->request(0.0), 'office', self::STORE_ID)
        );
        $this->assertFalse(
            $this->model->appliesToRequest($this->request(500.0), 'office', self::STORE_ID)
        );
    }

    public function testACartPriceRuleMakesItFreeWithNoThresholdConfigured(): void
    {
        // Magento's own Free Shipping action. It is answered before the config is
        // read at all, so a merchant does not have to enable anything for a rule
        // they wrote to work.
        $this->config->expects($this->never())->method('isFreeShippingThresholdEnabled');

        $request = $this->request(10.0);
        $request->setFreeShipping(1);

        $this->assertTrue($this->model->appliesToRequest($request, 'office', self::STORE_ID));
    }

    public function testAnAbsentCartRuleFlagIsNotMistakenForOne(): void
    {
        // Rates collected from an address, which is how the admin creates an
        // order, never set the flag at all.
        $this->threshold('office', false, 0.0);

        $request = $this->request(10.0);
        $this->assertNull($request->getFreeShipping());

        $this->assertFalse($this->model->appliesToRequest($request, 'office', self::STORE_ID));
    }

    public function testEachDeliveryTypeIsAskedAboutItself(): void
    {
        // The whole point of configuring this per type: office is free, address
        // is not, on the same cart.
        $this->config->method('isFreeShippingThresholdEnabled')->willReturnMap([
            ['office', self::STORE_ID, true],
            ['address', self::STORE_ID, false],
        ]);
        $this->config->method('getFreeShippingThreshold')->willReturnMap([
            ['office', self::STORE_ID, 50.0],
            ['address', self::STORE_ID, 0.0],
        ]);

        $request = $this->request(100.0);

        $this->assertTrue($this->model->appliesToRequest($request, 'office', self::STORE_ID));
        $this->assertFalse($this->model->appliesToRequest($request, 'address', self::STORE_ID));
    }

    public function testAnOrderReadsWhatWasRecordedRatherThanRecalculating(): void
    {
        // A threshold the merchant lowers next week must not rewrite who was
        // billed for an order already taken.
        $this->config->expects($this->never())->method('isFreeShippingThresholdEnabled');
        $this->orderAddresses->method('getByAddressId')->with(7)->willReturn(['free_shipping' => true]);

        $this->assertTrue($this->model->wasAppliedToOrder($this->order(7)));
    }

    public function testAnOrderWithNothingRecordedIsNotFree(): void
    {
        $this->orderAddresses->method('getByAddressId')->with(7)->willReturn(['free_shipping' => false]);

        $this->assertFalse($this->model->wasAppliedToOrder($this->order(7)));
    }

    public function testAnOrderWithNoRowAtAllIsNotFree(): void
    {
        // Orders placed before this module version have no row, and predate the
        // feature, so they were certainly not given away by it.
        $this->orderAddresses->method('getByAddressId')->willReturn(null);

        $this->assertFalse($this->model->wasAppliedToOrder($this->order(7)));
    }

    public function testAVirtualOrderWithNoShippingAddressIsNotFree(): void
    {
        $this->orderAddresses->expects($this->never())->method('getByAddressId');

        $order = $this->createMock(\Magento\Sales\Model\Order::class);
        $order->method('getShippingAddress')->willReturn(false);

        $this->assertFalse($this->model->wasAppliedToOrder($order));
    }

    /**
     * Toggle and amount for one delivery type.
     */
    private function threshold(string $deliveryType, bool $enabled, float $amount): void
    {
        $this->config->method('isFreeShippingThresholdEnabled')
            ->with($deliveryType, self::STORE_ID)
            ->willReturn($enabled);
        $this->config->method('getFreeShippingThreshold')
            ->with($deliveryType, self::STORE_ID)
            ->willReturn($amount);
    }

    private function request(float $packageValueWithDiscount): RateRequest
    {
        /** @var RateRequest $request */
        $request = $this->objectManager->getObject(RateRequest::class);
        $request->setPackageValueWithDiscount($packageValueWithDiscount);

        return $request;
    }

    /**
     * @return \Magento\Sales\Model\Order&MockObject
     */
    private function order(int $addressId)
    {
        $address = $this->createMock(\Magento\Sales\Model\Order\Address::class);
        $address->method('getId')->willReturn($addressId);

        $order = $this->createMock(\Magento\Sales\Model\Order::class);
        $order->method('getShippingAddress')->willReturn($address);

        return $order;
    }
}
