<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Magento;

use Magento\Framework\DataObject;
use Magento\Sales\Api\Data\InvoiceInterface;
use Magento\Sales\Api\InvoiceOrderInterface;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\ResourceModel\Order\Invoice\Collection as InvoiceCollection;
use Magento\Sales\Model\ResourceModel\Order\Invoice\CollectionFactory as InvoiceCollectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\Magento\CompleteOnDelivery;
use PigeonExpress\Shipping\Model\Magento\NativeShipment;
use PigeonExpress\Shipping\Model\Magento\OrderComment;
use PigeonExpress\Shipping\Model\Magento\Settings;
use Psr\Log\LoggerInterface;

class CompleteOnDeliveryTest extends TestCase
{
    private const ROW = ['entity_id' => 7, 'reference_number' => 'X1'];

    /** @var InvoiceOrderInterface&MockObject */
    private $invoiceOrder;

    /** @var NativeShipment&MockObject */
    private $native;

    /** @var DataObject The invoice the collection finds after an error */
    private $savedInvoice;

    /** @var string[] */
    private $comments = [];

    private function service(bool $enabled = true): CompleteOnDelivery
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isCompleteOnDelivery')->willReturn($enabled);
        $settings->method('isInvoiceEmailEnabled')->willReturn(false);

        $config = $this->createMock(ConfigInterface::class);
        $config->method('getCodPaymentMethods')->willReturn(['cashondelivery']);

        $this->native = $this->createMock(NativeShipment::class);
        $this->invoiceOrder = $this->createMock(InvoiceOrderInterface::class);

        $invoice = $this->createMock(InvoiceInterface::class);
        $invoice->method('getIncrementId')->willReturn('000000055');
        $invoices = $this->createMock(InvoiceRepositoryInterface::class);
        $invoices->method('get')->willReturn($invoice);

        $this->savedInvoice = new DataObject();
        $collection = $this->createMock(InvoiceCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getFirstItem')->willReturnCallback(function () {
            return $this->savedInvoice;
        });
        $collectionFactory = $this->getMockBuilder(InvoiceCollectionFactory::class)
            ->disableOriginalConstructor()->onlyMethods(['create'])->getMock();
        $collectionFactory->method('create')->willReturn($collection);

        $comment = $this->createMock(OrderComment::class);
        $comment->method('add')->willReturnCallback(function ($order, string $text) {
            $this->comments[] = $text;
        });

        return new CompleteOnDelivery(
            $settings,
            $config,
            $this->native,
            $this->invoiceOrder,
            $invoices,
            $collectionFactory,
            $comment,
            $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * @return Order&MockObject
     */
    private function order(
        string $method,
        bool $canShip,
        bool $canInvoice,
        string $state = Order::STATE_PROCESSING
    ): Order {
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn($method);

        $order = $this->createMock(Order::class);
        $order->method('getEntityId')->willReturn(42);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getState')->willReturn($state);
        $order->method('canShip')->willReturn($canShip);
        $order->method('canInvoice')->willReturn($canInvoice);

        return $order;
    }

    private function shipped(string $result = NativeShipment::RESULT_CREATED, string $error = ''): array
    {
        return ['result' => $result, 'shipment' => $result === NativeShipment::RESULT_CREATED ? '000000099' : '',
            'error' => $error];
    }

    public function testOffByDefaultDoesNothingAndSaysNothing(): void
    {
        $service = $this->service(false);
        $this->native->expects($this->never())->method('shipOnDelivery');
        $this->invoiceOrder->expects($this->never())->method('execute');

        $result = $service->onDelivered($this->order('cashondelivery', true, true), self::ROW);

        $this->assertSame(CompleteOnDelivery::RESULT_DISABLED, $result);
        $this->assertSame([], $this->comments);
    }

    public function testAnOrderAlreadyCompleteGetsNoNote(): void
    {
        $service = $this->service();
        $this->native->expects($this->never())->method('shipOnDelivery');

        $result = $service->onDelivered($this->order('checkmo', false, false, Order::STATE_COMPLETE), self::ROW);

        $this->assertSame(CompleteOnDelivery::RESULT_ALREADY_COMPLETE, $result);
        $this->assertSame([], $this->comments);
    }

    public function testACodOrderWithoutAShipmentIsShippedAndInvoiced(): void
    {
        $service = $this->service();
        $order = $this->order('cashondelivery', true, true, Order::STATE_NEW);
        $this->native->expects($this->once())->method('shipOnDelivery')->with($order, self::ROW)
            ->willReturn($this->shipped());
        // Second argument false: captured offline, never through a gateway.
        $this->invoiceOrder->expects($this->once())->method('execute')->with(42, false, [], false)->willReturn(9);

        $result = $service->onDelivered($order, self::ROW);

        $this->assertSame(CompleteOnDelivery::RESULT_COMPLETED, $result);
        $this->assertCount(1, $this->comments);
        $this->assertStringContainsString('X1', $this->comments[0]);
        $this->assertStringContainsString('000000099', $this->comments[0]);
        $this->assertStringContainsString('000000055', $this->comments[0]);
        $this->assertStringContainsString('The order is complete.', $this->comments[0]);
    }

    public function testAPaidOrderNotYetShippedIsOnlyShipped(): void
    {
        $service = $this->service();
        $this->native->expects($this->once())->method('shipOnDelivery')->willReturn($this->shipped());
        $this->invoiceOrder->expects($this->never())->method('execute');

        $result = $service->onDelivered($this->order('checkmo', true, false), self::ROW);

        $this->assertSame(CompleteOnDelivery::RESULT_COMPLETED, $result);
    }

    public function testAnUnpaidOrderOfAnotherMethodIsLeftToInvoiceByHand(): void
    {
        $service = $this->service();
        $this->native->expects($this->never())->method('shipOnDelivery');
        $this->invoiceOrder->expects($this->never())->method('execute');

        $result = $service->onDelivered($this->order('checkmo', false, true), self::ROW);

        $this->assertSame(CompleteOnDelivery::RESULT_NOT_COMPLETED, $result);
        $this->assertCount(1, $this->comments);
        $this->assertStringContainsString('invoice it by hand', $this->comments[0]);
    }

    public function testAnOrderOnHoldIsLeftWithANote(): void
    {
        $service = $this->service();
        $this->native->expects($this->never())->method('shipOnDelivery');
        $this->invoiceOrder->expects($this->never())->method('execute');

        $result = $service->onDelivered($this->order('cashondelivery', true, true, Order::STATE_HOLDED), self::ROW);

        $this->assertSame(CompleteOnDelivery::RESULT_NOT_COMPLETED, $result);
        $this->assertStringContainsString('on hold', $this->comments[0]);
    }

    public function testAFailedShipmentDoesNotStopTheInvoice(): void
    {
        $service = $this->service();
        $this->native->method('shipOnDelivery')
            ->willReturn($this->shipped(NativeShipment::RESULT_FAILED, 'boom'));
        $this->invoiceOrder->expects($this->once())->method('execute')->willReturn(9);

        $result = $service->onDelivered($this->order('cashondelivery', true, true), self::ROW);

        $this->assertSame(CompleteOnDelivery::RESULT_NOT_COMPLETED, $result);
        $this->assertCount(1, $this->comments);
        $this->assertStringContainsString('boom', $this->comments[0]);
        $this->assertStringContainsString('000000055', $this->comments[0]);
    }

    public function testAFailedInvoiceDoesNotStopTheShipment(): void
    {
        $service = $this->service();
        $this->native->expects($this->once())->method('shipOnDelivery')->willReturn($this->shipped());
        $this->invoiceOrder->method('execute')->willThrowException(new \RuntimeException('locked'));

        $result = $service->onDelivered($this->order('cashondelivery', true, true), self::ROW);

        $this->assertSame(CompleteOnDelivery::RESULT_NOT_COMPLETED, $result);
        $this->assertStringContainsString('the invoice could not be created: locked', $this->comments[0]);
        $this->assertStringContainsString('000000099', $this->comments[0]);
    }

    public function testAnErrorAfterTheInvoiceWasSavedIsNotReportedAsAFailure(): void
    {
        $service = $this->service();
        $this->invoiceOrder->method('execute')->willReturnCallback(function () {
            $this->savedInvoice->setData(['id' => 9, 'increment_id' => '000000056']);
            throw new \RuntimeException('mail server down');
        });

        $result = $service->onDelivered($this->order('cashondelivery', false, true), self::ROW);

        $this->assertSame(CompleteOnDelivery::RESULT_COMPLETED, $result);
        $this->assertStringContainsString('000000056', $this->comments[0]);
        $this->assertStringContainsString('mail server down', $this->comments[0]);
        $this->assertStringNotContainsString('could not be created', $this->comments[0]);
    }
}
