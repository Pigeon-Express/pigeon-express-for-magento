<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Total\Quote;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Quote\Api\Data\ShippingInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Address\Total;
use Magento\Quote\Model\Quote\Payment;
use Magento\Quote\Model\ShippingAssignment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\Service\CodFeeResolver;
use PigeonExpress\Shipping\Model\Total\Quote\CodFee;

class CodFeeTest extends TestCase
{
    private const BASE_SHIPPING = 5.00;
    private const FEE = 1.50;

    /** @var ObjectManager */
    private $objectManager;

    /** @var ConfigInterface&MockObject */
    private $config;

    /** @var CodFeeResolver&MockObject */
    private $resolver;

    /** @var CodFee */
    private $collector;

    protected function setUp(): void
    {
        $this->objectManager = new ObjectManager($this);
        $this->config = $this->createMock(ConfigInterface::class);
        $this->resolver = $this->createMock(CodFeeResolver::class);

        $this->collector = new CodFee($this->config, $this->resolver);
    }

    public function testFeeIsAppliedForAPigeonExpressRateWithACodPaymentMethod(): void
    {
        $this->config->method('getCodPaymentMethods')->willReturn(['cashondelivery']);
        $this->resolver->method('resolve')->willReturn(self::FEE);

        [$total, $address] = $this->collect('pigeonexpress_office', 'cashondelivery');

        $this->assertSame(self::BASE_SHIPPING + self::FEE, (float) $total->getShippingAmount());
        $this->assertSame(self::FEE, (float) $address->getData(CodFee::CODE));
    }

    public function testFeeIsAddedExactlyOnceToTheShippingTotal(): void
    {
        // The old code let the API bill COD inside the rate and then added its own
        // percentage on top. The rate must stay COD free and this is the only place
        // the fee is applied.
        $this->config->method('getCodPaymentMethods')->willReturn(['cashondelivery']);
        $this->resolver->method('resolve')->willReturn(self::FEE);

        [$total] = $this->collect('pigeonexpress_office', 'cashondelivery');

        $this->assertSame(
            self::BASE_SHIPPING + self::FEE,
            (float) $total->getTotalAmount('shipping'),
            'shipping total must carry the fee once, not twice'
        );
    }

    public function testTaxInclusiveShippingKeepsPaceWithTheFee(): void
    {
        // This collector runs after the tax pass, so shipping_incl_tax was left
        // showing the pre fee figure and every incl tax display understated it.
        $this->config->method('getCodPaymentMethods')->willReturn(['cashondelivery']);
        $this->resolver->method('resolve')->willReturn(self::FEE);

        [$total, $address] = $this->collect('pigeonexpress_office', 'cashondelivery');

        $this->assertSame(self::BASE_SHIPPING + self::FEE, (float) $total->getShippingInclTax());
        $this->assertSame(self::BASE_SHIPPING + self::FEE, (float) $total->getBaseShippingInclTax());
        $this->assertSame(self::BASE_SHIPPING + self::FEE, (float) $address->getShippingInclTax());
    }

    public function testFeeGoesInAtFaceValueSoItIsNotTaxed(): void
    {
        // Pigeon Express quotes gross prices. Net and incl tax shipping must move
        // by the same amount.
        $this->config->method('getCodPaymentMethods')->willReturn(['cashondelivery']);
        $this->resolver->method('resolve')->willReturn(self::FEE);

        [$total] = $this->collect('pigeonexpress_office', 'cashondelivery');

        $this->assertSame(
            (float) $total->getShippingAmount(),
            (float) $total->getShippingInclTax(),
            'no tax may be added on top of a Pigeon Express fee'
        );
    }

    public function testNoFeeForAnotherCarrier(): void
    {
        $this->config->method('getCodPaymentMethods')->willReturn(['cashondelivery']);
        $this->resolver->expects($this->never())->method('resolve');

        [$total, $address] = $this->collect('flatrate_flatrate', 'cashondelivery');

        $this->assertSame(self::BASE_SHIPPING, (float) $total->getShippingAmount());
        $this->assertSame(0.0, (float) $address->getData(CodFee::CODE));
    }

    public function testNoFeeForANonCodPaymentMethod(): void
    {
        $this->config->method('getCodPaymentMethods')->willReturn(['cashondelivery']);
        $this->resolver->expects($this->never())->method('resolve');

        [$total] = $this->collect('pigeonexpress_office', 'checkmo');

        $this->assertSame(self::BASE_SHIPPING, (float) $total->getShippingAmount());
    }

    public function testNoFeeWhenNoCodMethodsAreConfigured(): void
    {
        $this->config->method('getCodPaymentMethods')->willReturn([]);
        $this->resolver->expects($this->never())->method('resolve');

        [$total] = $this->collect('pigeonexpress_office', 'cashondelivery');

        $this->assertSame(self::BASE_SHIPPING, (float) $total->getShippingAmount());
    }

    public function testShippingIsUntouchedWhenTheApiGivesNoFee(): void
    {
        $this->config->method('getCodPaymentMethods')->willReturn(['cashondelivery']);
        $this->resolver->method('resolve')->willReturn(0.0);

        [$total, $address] = $this->collect('pigeonexpress_office', 'cashondelivery');

        $this->assertSame(self::BASE_SHIPPING, (float) $total->getShippingAmount());
        $this->assertSame(0.0, (float) $address->getData(CodFee::CODE));
    }

    public function testFetchExposesTheFeeAsATotalSegment(): void
    {
        $this->config->method('getCodPaymentMethods')->willReturn(['cashondelivery']);
        $this->resolver->method('resolve')->willReturn(self::FEE);

        [$total, $address, $quote] = $this->collect('pigeonexpress_office', 'cashondelivery');

        $segment = $this->collector->fetch($quote, $total);

        $this->assertSame(CodFee::CODE, $segment['code']);
        $this->assertSame(self::FEE, (float) $segment['value']);
    }

    public function testFetchReturnsNothingWhenThereIsNoFee(): void
    {
        $this->config->method('getCodPaymentMethods')->willReturn(['cashondelivery']);
        $this->resolver->method('resolve')->willReturn(0.0);

        [$total, , $quote] = $this->collect('pigeonexpress_office', 'cashondelivery');

        $this->assertSame([], $this->collector->fetch($quote, $total));
    }

    // -----------------------------------------------------------------------

    /**
     * Run a collect() cycle and hand back the objects it touched.
     *
     * @return array{0: Total, 1: Address, 2: Quote}
     */
    private function collect(string $shippingMethod, string $paymentMethod): array
    {
        /** @var Address $address */
        $address = $this->objectManager->getObject(Address::class);
        $address->setShippingMethod($shippingMethod);
        $address->setShippingInclTax(self::BASE_SHIPPING);
        $address->setBaseShippingInclTax(self::BASE_SHIPPING);

        /** @var Total $total */
        $total = $this->objectManager->getObject(Total::class);
        $total->setShippingAmount(self::BASE_SHIPPING);
        $total->setBaseShippingAmount(self::BASE_SHIPPING);
        $total->setTotalAmount('shipping', self::BASE_SHIPPING);
        $total->setBaseTotalAmount('shipping', self::BASE_SHIPPING);
        $total->setShippingInclTax(self::BASE_SHIPPING);
        $total->setBaseShippingInclTax(self::BASE_SHIPPING);

        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn($paymentMethod);

        // getSubtotal() is a magic DataObject accessor, so it has to be declared
        // on the mock explicitly rather than stubbed like a real method.
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getStoreId', 'getPayment', 'getShippingAddress'])
            ->addMethods(['getSubtotal'])
            ->getMock();
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('getPayment')->willReturn($payment);
        $quote->method('getSubtotal')->willReturn(100.0);
        $quote->method('getShippingAddress')->willReturn($address);

        $shipping = $this->createMock(ShippingInterface::class);
        $shipping->method('getAddress')->willReturn($address);

        $assignment = $this->createMock(ShippingAssignment::class);
        $assignment->method('getShipping')->willReturn($shipping);

        $this->collector->collect($quote, $assignment, $total);

        return [$total, $address, $quote];
    }
}
