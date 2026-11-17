<?php
/**
 * What the order gets when its shipment reaches a final status: a note, and
 * on a return what "When the shipment comes back" asks for.
 *
 * Magento has states as well as statuses, and its states are moved by its
 * own processes (invoice, shipment, credit memo, cancel, hold), so this never
 * sets a state or a status by hand:
 *
 * - Delivered: a note only. Completing the order is done before this, by
 *   Model\Magento\CompleteOnDelivery ("When the shipment is delivered").
 * - Returned: only an order in the "processing" or "new" state is changed (a
 *   cash on delivery order with no Magento shipment is still new); anything
 *   else was moved on purpose. "Put the order on hold" holds it the way
 *   Magento's own Hold button does and "Cancel the order" cancels it the way
 *   the Cancel button does, each only when Magento allows it (an order with
 *   an invoice cannot be cancelled or held). Otherwise the order only gets
 *   the note.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Status;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use PigeonExpress\Shipping\Model\Magento\OrderComment;
use Psr\Log\LoggerInterface;

class OrderStatusApplier
{
    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var OrderComment */
    private $comment;

    /** @var StatusConfig */
    private $config;

    /** @var StatusNames */
    private $names;

    /** @var TimezoneInterface */
    private $timezone;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        OrderComment $comment,
        StatusConfig $config,
        StatusNames $names,
        TimezoneInterface $timezone,
        LoggerInterface $logger
    ) {
        $this->orderRepository = $orderRepository;
        $this->comment = $comment;
        $this->config = $config;
        $this->names = $names;
        $this->timezone = $timezone;
        $this->logger = $logger;
    }

    /**
     * @param string $at UTC "Y-m-d H:i:s", or ''
     */
    public function apply(int $orderId, string $reference, string $code, string $carrierName, string $at): void
    {
        try {
            /** @var Order $order */
            $order = $this->orderRepository->get($orderId);
        } catch (\Throwable $e) {
            return;
        }

        $label = $this->names->label($code, $carrierName);
        $date = $at !== '' ? $this->formatUtc($at) : '';
        $note = (string) __(
            'Pigeon Express shipment %1: %2.',
            $reference,
            $date !== '' ? $label . ' (' . $date . ')' : $label
        );

        $action = StatusCodes::isReturned($code)
            ? $this->decide(
                $this->config->getOrderOnReturn((int) $order->getStoreId()),
                (string) $order->getState(),
                $order
            )
            : '';

        if ($action === '') {
            // A history row only: the order itself is not saved.
            $this->comment->add($order, $note);

            return;
        }

        try {
            if ($action === StatusConfig::ACTION_HOLD) {
                $order->hold();
            } else {
                $order->cancel();
            }

            // hold() and cancel() set their own status: the note keeps it.
            $order->addCommentToStatusHistory($note, false);
            $this->orderRepository->save($order);
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[PigeonExpress] Status ' . $code . ' of ' . $reference . ' not applied to order #'
                . $orderId . ': ' . $e->getMessage()
            );
            $this->comment->add($order, $note);
        }
    }

    /**
     * What to do with a returned order: StatusConfig::ACTION_HOLD,
     * StatusConfig::ACTION_CANCEL, or '' for the note only.
     *
     * @param string $action The "When the shipment comes back" setting
     */
    public function decide(string $action, string $state, Order $order): string
    {
        if (!in_array($state, [Order::STATE_PROCESSING, Order::STATE_NEW], true)) {
            return '';
        }

        // An invoiced order has been paid (or partly): it is never held or
        // cancelled on its own, as the setting says. The merchant decides.
        if ((float) $order->getTotalInvoiced() > 0 || $order->hasInvoices()) {
            return '';
        }

        if ($action === StatusConfig::ACTION_HOLD && $order->canHold()) {
            return StatusConfig::ACTION_HOLD;
        }
        if ($action === StatusConfig::ACTION_CANCEL && $order->canCancel()) {
            return StatusConfig::ACTION_CANCEL;
        }

        return '';
    }

    private function formatUtc(string $at): string
    {
        try {
            return (string) $this->timezone->formatDateTime(
                new \DateTime($at, new \DateTimeZone('UTC')),
                \IntlDateFormatter::MEDIUM,
                \IntlDateFormatter::SHORT
            );
        } catch (\Throwable $e) {
            return $at;
        }
    }
}
