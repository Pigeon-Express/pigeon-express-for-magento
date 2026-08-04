<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Plugin\Quote;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Address\Rate;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Plugin\Quote\ShippingRateSortPlugin;

/**
 * Magento hands every rate to sortRatesByPrice() before the checkout sees it, so
 * without this the three delivery types arrange themselves by price and shuffle
 * as a cart crosses a free shipping threshold and one of them drops to nothing.
 */
class ShippingRateSortPluginTest extends TestCase
{
    private const STORE_ID = 1;

    /** @var ObjectManager */
    private $objectManager;

    /** @var ConfigInterface&MockObject */
    private $config;

    /** @var ShippingRateSortPlugin */
    private $plugin;

    protected function setUp(): void
    {
        $this->objectManager = new ObjectManager($this);
        $this->config = $this->createMock(ConfigInterface::class);

        $this->config->method('getDeliveryTypeSortOrder')->willReturnMap([
            ['address', self::STORE_ID, 30],
            ['office', self::STORE_ID, 10],
            ['aps', self::STORE_ID, 20],
        ]);

        $this->plugin = $this->objectManager->getObject(ShippingRateSortPlugin::class, [
            'config' => $this->config,
        ]);
    }

    public function testTheConfiguredOrderReplacesWhateverMagentoSortedBy(): void
    {
        $result = $this->plugin->afterGetGroupedAllShippingRates(
            $this->address(),
            ['pigeonexpress' => [$this->rate('address'), $this->rate('aps'), $this->rate('office')]]
        );

        $this->assertSame(['office', 'aps', 'address'], $this->methodsOf($result['pigeonexpress']));
    }

    public function testAFreeMethodDoesNotJumpToTheTop(): void
    {
        // The reason this plugin exists. Magento would have put the zero first.
        $result = $this->plugin->afterGetGroupedAllShippingRates(
            $this->address(),
            ['pigeonexpress' => [
                $this->rate('aps', 0.0),
                $this->rate('office', 4.50),
                $this->rate('address', 6.20),
            ]]
        );

        $this->assertSame(['office', 'aps', 'address'], $this->methodsOf($result['pigeonexpress']));
    }

    public function testOtherCarriersAreLeftExactlyAsTheyWere(): void
    {
        $flatrate = [$this->rate('flatrate'), $this->rate('bestway')];

        $result = $this->plugin->afterGetGroupedAllShippingRates(
            $this->address(),
            [
                'flatrate' => $flatrate,
                'pigeonexpress' => [$this->rate('aps'), $this->rate('office')],
            ]
        );

        $this->assertSame($flatrate, $result['flatrate']);
        $this->assertSame(['office', 'aps'], $this->methodsOf($result['pigeonexpress']));
    }

    public function testTheCarrierPositionStampSurvivesTheReorder(): void
    {
        // Magento stamps the carrier's own position on the first rate of the
        // group. Reordering moves a different rate into that slot, so the stamp
        // is carried across rather than lost.
        $first = $this->rate('aps');
        $first->carrier_sort_order = 40;

        $result = $this->plugin->afterGetGroupedAllShippingRates(
            $this->address(),
            ['pigeonexpress' => [$first, $this->rate('office')]]
        );

        $this->assertSame('office', $result['pigeonexpress'][0]->getMethod());
        $this->assertSame(40, $result['pigeonexpress'][0]->carrier_sort_order);
    }

    public function testASingleMethodIsReturnedUntouched(): void
    {
        $this->config->expects($this->never())->method('getDeliveryTypeSortOrder');

        $group = [$this->rate('office')];
        $result = $this->plugin->afterGetGroupedAllShippingRates(
            $this->address(),
            ['pigeonexpress' => $group]
        );

        $this->assertSame($group, $result['pigeonexpress']);
    }

    public function testNoPigeonExpressRatesIsNotAnError(): void
    {
        $result = $this->plugin->afterGetGroupedAllShippingRates($this->address(), []);

        $this->assertSame([], $result);
    }

    public function testAMethodThisVersionDoesNotKnowGoesLastRatherThanThrowing(): void
    {
        // A rate stored by an older version of the module. The config getters
        // reject an unknown delivery type by throwing, and a checkout that will
        // not render is worse than one option in the wrong place.
        $result = $this->plugin->afterGetGroupedAllShippingRates(
            $this->address(),
            ['pigeonexpress' => [$this->rate('legacy'), $this->rate('address'), $this->rate('office')]]
        );

        $this->assertSame(['office', 'address', 'legacy'], $this->methodsOf($result['pigeonexpress']));
    }

    /** @param Rate[] $rates @return string[] */
    private function methodsOf(array $rates): array
    {
        return array_map(
            static function (Rate $rate): string {
                return (string) $rate->getMethod();
            },
            $rates
        );
    }

    private function rate(string $method, float $price = 5.0): Rate
    {
        /** @var Rate $rate */
        $rate = $this->objectManager->getObject(Rate::class);
        $rate->setMethod($method);
        $rate->setPrice($price);

        return $rate;
    }

    /**
     * @return Address&MockObject
     */
    private function address()
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('getStoreId')->willReturn(self::STORE_ID);

        $address = $this->createMock(Address::class);
        $address->method('getQuote')->willReturn($quote);

        return $address;
    }
}
