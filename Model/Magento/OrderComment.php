<?php
/**
 * Add a comment to an order's history without saving the order.
 *
 * The observers here run while another part of the module (or Magento itself)
 * holds the order; saving the whole order from them would write back whatever
 * that copy holds and fire every order save observer again. A history row is
 * all a comment is, so only that row is written.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Magento;

use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Status\HistoryFactory;
use Psr\Log\LoggerInterface;

class OrderComment
{
    /** @var HistoryFactory */
    private $historyFactory;

    /** @var OrderStatusHistoryRepositoryInterface */
    private $historyRepository;

    /** @var LoggerInterface */
    private $logger;

    /** @var ResourceConnection */
    private $resource;

    public function __construct(
        HistoryFactory $historyFactory,
        OrderStatusHistoryRepositoryInterface $historyRepository,
        LoggerInterface $logger,
        ResourceConnection $resource
    ) {
        $this->resource = $resource;
        $this->historyFactory = $historyFactory;
        $this->historyRepository = $historyRepository;
        $this->logger = $logger;
    }

    /**
     * @param string $comment Plain text; Magento escapes it when shown.
     */
    public function add(OrderInterface $order, string $comment): void
    {
        try {
            $history = $this->historyFactory->create();
            $history->setParentId((int) $order->getEntityId());
            $history->setComment($comment);
            $history->setIsCustomerNotified(false);
            $history->setIsVisibleOnFront(0);
            $history->setEntityName(Order::ENTITY);
            $history->setStatus($this->currentStatus($order));
            $this->historyRepository->save($history);
        } catch (\Throwable $e) {
            $this->logger->error('[PigeonExpress] Could not add an order comment: ' . $e->getMessage(), [
                'order_id' => $order->getEntityId(),
                'comment' => $comment,
            ]);
        }
    }

    /**
     * A comment the customer sees in their account, marked as notified. The
     * caller sends the email (OrderCommentSender), as Magento's own "Notify
     * Customer by Email" does. Returns false when the row could not be saved.
     *
     * @param string $comment Plain text; Magento escapes it when shown.
     */
    public function addForCustomer(OrderInterface $order, string $comment, bool $notified): bool
    {
        try {
            $history = $this->historyFactory->create();
            $history->setParentId((int) $order->getEntityId());
            $history->setComment($comment);
            $history->setIsCustomerNotified($notified);
            $history->setIsVisibleOnFront(1);
            $history->setEntityName(Order::ENTITY);
            $history->setStatus($this->currentStatus($order));
            $this->historyRepository->save($history);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('[PigeonExpress] Could not add a customer comment: ' . $e->getMessage(), [
                'order_id' => $order->getEntityId(),
            ]);

            return false;
        }
    }

    /**
     * The order's status as stored now. The copy in hand may predate a
     * shipment or invoice that moved the order on a moment ago.
     */
    private function currentStatus(OrderInterface $order): string
    {
        try {
            $connection = $this->resource->getConnection();
            $status = $connection->fetchOne(
                $connection->select()
                    ->from($this->resource->getTableName('sales_order'), ['status'])
                    ->where('entity_id = ?', (int) $order->getEntityId())
            );
            if (is_string($status) && $status !== '') {
                return $status;
            }
        } catch (\Throwable $e) {
            // Fall back to the copy in hand.
        }

        return (string) $order->getStatus();
    }
}
