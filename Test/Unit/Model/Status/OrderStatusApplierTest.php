<?php
/**
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Test\Unit\Model\Status;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PigeonExpress\Shipping\Model\Magento\OrderComment;
use PigeonExpress\Shipping\Model\Status\OrderStatusApplier;
use PigeonExpress\Shipping\Model\Status\StatusCodes;
use PigeonExpress\Shipping\Model\Status\StatusConfig;
use PigeonExpress\Shipping\Model\Status\StatusNames;
use Psr\Log\LoggerInterface;

/**
 * Magento states and statuses are never set by hand: a note, or on a return
 * Magento's own hold and cancel.
 */
class OrderStatusApplierTest extends TestCase
{
    /** @var OrderRepositoryInterface&MockObject */
    private $repository;

    /** @var OrderComment&MockObject */
    private $comment;

    /** @var StatusConfig&MockObject */
    private $config;

    public function testOnlyAProcessingOrNewOrderIsChanged(): void
    {
        $applier = $this->applier();
        $order = $this->order(true, true);

        self::assertSame('', $applier->decide(StatusConfig::ACTION_CANCEL, Order::STATE_COMPLETE, $order));
        self::assertSame('', $applier->decide(StatusConfig::ACTION_HOLD, Order::STATE_HOLDED, $order));
        self::assertSame('cancel', $applier->decide(StatusConfig::ACTION_CANCEL, Order::STATE_NEW, $order));
        self::assertSame('hold', $applier->decide(StatusConfig::ACTION_HOLD, Order::STATE_PROCESSING, $order));
    }

    public function testAnInvoicedOrderIsNeverHeldOrCancelled(): void
    {
        $applier = $this->applier();
        $order = $this->order(true, true, Order::STATE_PROCESSING, true);

        self::assertSame('', $applier->decide(StatusConfig::ACTION_HOLD, Order::STATE_PROCESSING, $order));
        self::assertSame('', $applier->decide(StatusConfig::ACTION_CANCEL, Order::STATE_PROCESSING, $order));
    }

    public function testDontChangeLeavesTheOrder(): void
    {
        self::assertSame(
            '',
            $this->applier()->decide(StatusConfig::ACTION_NONE, Order::STATE_PROCESSING, $this->order(true, true))
        );
    }

    public function testHoldAndCancelOnlyWhenMagentoAllowsThem(): void
    {
        $applier = $this->applier();

        // An invoiced order cannot be cancelled or held: note only.
        self::assertSame('', $applier->decide(StatusConfig::ACTION_CANCEL, Order::STATE_PROCESSING, $this->order(true, false)));
        self::assertSame('', $applier->decide(StatusConfig::ACTION_HOLD, Order::STATE_PROCESSING, $this->order(false, true)));
    }

    public function testDeliveredOnlyAddsANoteWithoutSavingTheOrder(): void
    {
        $order = $this->order(true, true);
        $this->config->expects($this->never())->method('getOrderOnReturn');
        $this->repository->method('get')->willReturn($order);
        $this->repository->expects($this->never())->method('save');
        $order->expects($this->never())->method('hold');
        $order->expects($this->never())->method('cancel');
        $this->comment->expects($this->once())->method('add')
            ->with($order, $this->stringContains('X1'));

        $this->applier()->apply(42, 'X1', StatusCodes::DELIVERED, '', '');
    }

    public function testReturnedCancelsAndKeepsMagentosOwnStatus(): void
    {
        $order = $this->order(true, true, Order::STATE_PROCESSING);
        $this->config->method('getOrderOnReturn')->willReturn(StatusConfig::ACTION_CANCEL);
        $this->repository->method('get')->willReturn($order);
        $order->expects($this->once())->method('cancel');
        $order->expects($this->never())->method('hold');
        // false: the status cancel() set stays.
        $order->expects($this->once())->method('addCommentToStatusHistory')
            ->with($this->stringContains('X1'), false);
        $this->repository->expects($this->once())->method('save')->with($order);
        $this->comment->expects($this->never())->method('add');

        $this->applier()->apply(42, 'X1', StatusCodes::RETURNED, '', '');
    }

    public function testReturnedHoldsANewOrder(): void
    {
        $order = $this->order(true, true, Order::STATE_NEW);
        $this->config->method('getOrderOnReturn')->willReturn(StatusConfig::ACTION_HOLD);
        $this->repository->method('get')->willReturn($order);
        $order->expects($this->once())->method('hold');
        $order->expects($this->once())->method('addCommentToStatusHistory')
            ->with($this->anything(), false);
        $this->repository->expects($this->once())->method('save');

        $this->applier()->apply(42, 'X1', StatusCodes::RETURNED, '', '');
    }

    public function testReturnedInvoicedOrderOnlyGetsTheNote(): void
    {
        $order = $this->order(true, false, Order::STATE_PROCESSING);
        $this->config->method('getOrderOnReturn')->willReturn(StatusConfig::ACTION_CANCEL);
        $this->repository->method('get')->willReturn($order);
        $order->expects($this->never())->method('cancel');
        $this->repository->expects($this->never())->method('save');
        $this->comment->expects($this->once())->method('add');

        $this->applier()->apply(42, 'X1', StatusCodes::RETURNED, '', '');
    }

    public function testAFailedSaveStillLeavesTheNote(): void
    {
        $order = $this->order(true, true, Order::STATE_PROCESSING);
        $this->config->method('getOrderOnReturn')->willReturn(StatusConfig::ACTION_CANCEL);
        $this->repository->method('get')->willReturn($order);
        $this->repository->method('save')->willThrowException(new \RuntimeException('locked'));
        $this->comment->expects($this->once())->method('add');

        $this->applier()->apply(42, 'X1', StatusCodes::RETURNED, '', '');
    }

    /**
     * @return Order&MockObject
     */
    private function order(
        bool $canHold,
        bool $canCancel,
        string $state = Order::STATE_PROCESSING,
        bool $invoiced = false
    ): Order {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'canHold', 'canCancel', 'getState', 'getStoreId', 'hold', 'cancel', 'addCommentToStatusHistory',
                'hasInvoices', 'getTotalInvoiced',
            ])
            ->getMock();
        $order->method('hasInvoices')->willReturn($invoiced);
        $order->method('getTotalInvoiced')->willReturn($invoiced ? 10.0 : null);
        $order->method('canHold')->willReturn($canHold);
        $order->method('canCancel')->willReturn($canCancel);
        $order->method('getState')->willReturn($state);
        $order->method('getStoreId')->willReturn(1);

        return $order;
    }

    private function applier(): OrderStatusApplier
    {
        $names = $this->createMock(StatusNames::class);
        $names->method('label')->willReturn('Delivered');

        return new OrderStatusApplier(
            $this->repository,
            $this->comment,
            $this->config,
            $names,
            $this->createMock(TimezoneInterface::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    protected function setUp(): void
    {
        $this->repository = $this->createMock(OrderRepositoryInterface::class);
        $this->comment = $this->createMock(OrderComment::class);
        $this->config = $this->createMock(StatusConfig::class);
    }
}
