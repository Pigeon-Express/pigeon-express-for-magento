<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Block\Delivery;

use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address as OrderAddress;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Block\Delivery\Info;
use PigeonExpress\Shipping\Model\OrderAddressLocationPersistor;

/**
 * The shipment summary, where the merchant sees who is being billed for what.
 *
 * The two questions it has to get right are which side the carrier invoices, and
 * whether the figure beside that side is something the order actually knows.
 * Answering the second wrongly prints 0.00 for a cost that is about to arrive on
 * an invoice.
 */
class InfoTest extends TestCase
{
    private const ADDRESS_ID = 42;

    /** @var ObjectManager */
    private $objectManager;

    /** @var OrderAddressLocationPersistor&MockObject */
    private $persistor;

    protected function setUp(): void
    {
        $this->objectManager = new ObjectManager($this);
        $this->persistor = $this->createMock(OrderAddressLocationPersistor::class);
    }

    // -- Who is billed -------------------------------------------------------

    public function testTheBilledSideCarriesTheAmountAndTheOtherCarriesZero(): void
    {
        $block = $this->block(4.15, ConfigInterface::WHO_PAYS_RECEIVER);

        $this->assertSame('0.00', $block->getFormattedSenderFee());
        $this->assertSame('4.15', $block->getFormattedReceiverFee());
    }

    public function testTheSidesSwapWithTheForm(): void
    {
        $block = $this->block(4.15, ConfigInterface::WHO_PAYS_SENDER);

        $this->assertSame('4.15', $block->getFormattedSenderFee());
        $this->assertSame('0.00', $block->getFormattedReceiverFee());
    }

    public function testNoPriceAtAllRendersNothing(): void
    {
        // The template uses an empty string to decide whether to draw the rows.
        $block = $this->block(null, ConfigInterface::WHO_PAYS_SENDER);

        $this->assertSame('', $block->getFormattedSenderFee());
        $this->assertSame('', $block->getFormattedReceiverFee());
    }

    // -- Whether the figure is known -----------------------------------------

    public function testAZeroOnTheBilledSideIsNotKnownBecauseItWasNeverPriced(): void
    {
        // A free delivery: the customer paid nothing, so nothing is stored, but
        // the carrier still invoices the merchant. The template shows "Calculate".
        $block = $this->block(0.0, ConfigInterface::WHO_PAYS_SENDER);

        $this->assertFalse($block->isSenderFeeKnown());
    }

    public function testAZeroOnTheSideThatIsNotBilledIsSimplyTrue(): void
    {
        // The courier really does collect nothing when the sender is billed.
        $block = $this->block(0.0, ConfigInterface::WHO_PAYS_SENDER);

        $this->assertTrue($block->isReceiverFeeKnown());
    }

    public function testAPricedDeliveryIsKnownOnBothSides(): void
    {
        $block = $this->block(4.15, ConfigInterface::WHO_PAYS_RECEIVER);

        $this->assertTrue($block->isSenderFeeKnown());
        $this->assertTrue($block->isReceiverFeeKnown());
    }

    public function testAReceiverPaysStoreQuotingZeroIsAlsoUnknown(): void
    {
        // Not free shipping, but the same gap: the side being billed has no
        // figure on the order, so it must not be printed as nothing owed.
        $block = $this->block(0.0, ConfigInterface::WHO_PAYS_RECEIVER);

        $this->assertFalse($block->isReceiverFeeKnown());
        $this->assertTrue($block->isSenderFeeKnown());
    }

    public function testNothingIsKnownWhenThereIsNoPriceAtAll(): void
    {
        $block = $this->block(null, ConfigInterface::WHO_PAYS_SENDER);

        $this->assertFalse($block->isSenderFeeKnown());
        $this->assertFalse($block->isReceiverFeeKnown());
    }

    // -- Why Sender is preselected -------------------------------------------

    public function testAGivenAwayDeliveryIsReportedAsSuch(): void
    {
        $this->persistor->method('getByAddressId')->willReturn(['free_shipping' => true]);
        $block = $this->block(0.0, ConfigInterface::WHO_PAYS_SENDER);

        $this->assertTrue($block->isFreeShipping());
    }

    public function testAPaidDeliveryIsNot(): void
    {
        $this->persistor->method('getByAddressId')->willReturn(['free_shipping' => false]);
        $block = $this->block(4.15, ConfigInterface::WHO_PAYS_RECEIVER);

        $this->assertFalse($block->isFreeShipping());
    }

    public function testAnOrderPredatingTheFlagIsNotTreatedAsGivenAway(): void
    {
        $this->persistor->method('getByAddressId')->willReturn(null);
        $block = $this->block(0.0, ConfigInterface::WHO_PAYS_SENDER);

        $this->assertFalse($block->isFreeShipping());
    }

    public function testTheAnswerIsLookedUpOnlyOnceForTheWholeForm(): void
    {
        // The template asks twice, once for the highlight and once for the note.
        $this->persistor->expects($this->once())
            ->method('getByAddressId')
            ->willReturn(['free_shipping' => true]);

        $block = $this->block(0.0, ConfigInterface::WHO_PAYS_SENDER);

        $block->isFreeShipping();
        $block->isFreeShipping();
        $block->isFreeShipping();
    }

    // -----------------------------------------------------------------------

    /**
     * A block whose order reports the given delivery price, with the form
     * already resolved to the given side.
     *
     * Only the two collaborators that decide these answers are stubbed, so the
     * arithmetic runs for real.
     *
     * @return Info&MockObject
     */
    private function block(?float $deliveryPrice, string $whoPays)
    {
        $address = $this->createMock(OrderAddress::class);
        $address->method('getId')->willReturn(self::ADDRESS_ID);

        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getShippingAddress', 'getOrderCurrencyCode', 'formatPrice'])
            ->getMock();
        $order->method('getShippingAddress')->willReturn($address);
        // A currency code so formatMoney() takes the order branch, and a
        // formatter that returns something a test can assert on.
        $order->method('getOrderCurrencyCode')->willReturn('EUR');
        $order->method('formatPrice')->willReturnCallback(
            static function ($price): string {
                return number_format((float) $price, 2, '.', '');
            }
        );

        /** @var Info&MockObject $block */
        $block = $this->getMockBuilder(Info::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getDeliveryPrice', 'getFieldValue'])
            ->getMock();
        $block->method('getDeliveryPrice')->willReturn($deliveryPrice);
        $block->method('getFieldValue')->willReturnCallback(
            static function (string $field) use ($whoPays): string {
                return $field === 'who_pays' ? $whoPays : '';
            }
        );

        // The collaborators the real constructor would have supplied.
        $this->setPrivate($block, 'locationPersistor', $this->persistor);
        $this->setPrivate($block, 'orderOrQuote', $order);

        return $block;
    }

    private function setPrivate(object $target, string $property, $value): void
    {
        $ref = new \ReflectionProperty(Info::class, $property);
        $ref->setAccessible(true);
        $ref->setValue($target, $value);
    }
}
