<?php
/**
 * "When the shipment is delivered: Complete the order".
 *
 * Magento's Complete is a state Magento reaches by itself once an order is
 * both shipped and invoiced; it is never set by hand. So, the first time a
 * waybill of the order is delivered:
 *
 * a) the order is shipped in Magento with the waybill number, if it still has
 *    items to ship (for example "Create the Magento shipment" is No), without
 *    the shipment email: the parcel has already arrived;
 * b) a cash on delivery order is invoiced offline: the courier collected the
 *    money. An order paid any other way that has no invoice yet is left for
 *    the merchant to invoice by hand, since nothing says it was paid.
 *
 * The two steps are independent: one failing never stops the other. The order
 * then gets exactly one note saying what happened, or none at all when it was
 * already complete before the delivery.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Magento;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\InvoiceOrderInterface;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\Invoice\CollectionFactory as InvoiceCollectionFactory;
use PigeonExpress\Shipping\Api\ConfigInterface;
use Psr\Log\LoggerInterface;

class CompleteOnDelivery
{
    public const RESULT_DISABLED = 'disabled';
    public const RESULT_ALREADY_COMPLETE = 'already_complete';
    public const RESULT_COMPLETED = 'completed';
    public const RESULT_NOT_COMPLETED = 'not_completed';

    /** @var Settings */
    private $settings;

    /** @var ConfigInterface */
    private $config;

    /** @var NativeShipment */
    private $nativeShipment;

    /** @var InvoiceOrderInterface */
    private $invoiceOrder;

    /** @var InvoiceRepositoryInterface */
    private $invoiceRepository;

    /** @var InvoiceCollectionFactory */
    private $invoiceCollectionFactory;

    /** @var OrderComment */
    private $comment;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        Settings $settings,
        ConfigInterface $config,
        NativeShipment $nativeShipment,
        InvoiceOrderInterface $invoiceOrder,
        InvoiceRepositoryInterface $invoiceRepository,
        InvoiceCollectionFactory $invoiceCollectionFactory,
        OrderComment $comment,
        LoggerInterface $logger
    ) {
        $this->settings = $settings;
        $this->config = $config;
        $this->nativeShipment = $nativeShipment;
        $this->invoiceOrder = $invoiceOrder;
        $this->invoiceRepository = $invoiceRepository;
        $this->invoiceCollectionFactory = $invoiceCollectionFactory;
        $this->comment = $comment;
        $this->logger = $logger;
    }

    /**
     * @param array<string, mixed> $row The pigeonexpress_shipment row just delivered
     * @return string One of the RESULT_* values
     */
    public function onDelivered(OrderInterface $order, array $row): string
    {
        $storeId = (int) $order->getStoreId();
        if (!$this->settings->isCompleteOnDelivery($storeId) || !$order instanceof Order) {
            return self::RESULT_DISABLED;
        }

        $state = (string) $order->getState();
        if (in_array($state, [Order::STATE_COMPLETE, Order::STATE_CLOSED], true)) {
            return self::RESULT_ALREADY_COMPLETE;
        }

        $number = trim((string) ($row['reference_number'] ?? ''));
        $done = [];
        $problems = [];

        if ($state === Order::STATE_HOLDED) {
            $problems[] = (string) __('it is on hold.');
        } elseif ($state === Order::STATE_CANCELED) {
            $problems[] = (string) __('it is cancelled.');
        } else {
            // Both read before either step: shipping must not change whether
            // the order is to be invoiced, or the other way round.
            $toShip = $order->canShip();
            $toInvoice = $order->canInvoice();

            if ($toShip) {
                $this->ship($order, $row, $done, $problems);
            }
            if ($toInvoice) {
                $this->invoice($order, $number, $done, $problems);
            }
            if (!$toShip && !$toInvoice) {
                $problems[] = (string) __('Magento cannot ship or invoice it in its current state.');
            }
        }

        $lines = [(string) __('Pigeon Express waybill %1 was delivered.', $number)];
        array_push($lines, ...$done);
        $lines[] = $problems === []
            ? (string) __('The order is complete.')
            : (string) __('The order was not completed, because %1', implode(' ', $problems));

        $this->comment->add($order, implode(' ', $lines));

        return $problems === [] ? self::RESULT_COMPLETED : self::RESULT_NOT_COMPLETED;
    }

    /**
     * @param array<string, mixed> $row
     * @param string[] $done
     * @param string[] $problems
     */
    private function ship(Order $order, array $row, array &$done, array &$problems): void
    {
        $shipped = $this->nativeShipment->shipOnDelivery($order, $row);

        switch ($shipped['result']) {
            case NativeShipment::RESULT_CREATED:
            case NativeShipment::RESULT_REUSED:
                $done[] = $shipped['error'] === ''
                    ? (string) __('Magento shipment #%1 was created with this tracking number.', $shipped['shipment'])
                    : (string) __(
                        'Magento shipment #%1 was created with this tracking number, '
                        . 'but Magento then reported an error: %2',
                        $shipped['shipment'],
                        $shipped['error']
                    );

                return;
            case NativeShipment::RESULT_ALREADY_LINKED:
                // The waybill is on a shipment already, yet items are left.
                $problems[] = (string) __('some of its items are not shipped in Magento yet.');

                return;
        }

        $problems[] = $shipped['error'] !== ''
            ? (string) __('the Magento shipment could not be created: %1', $shipped['error'])
            : (string) __('the Magento shipment could not be created.');
    }

    /**
     * @param string[] $done
     * @param string[] $problems
     */
    private function invoice(Order $order, string $number, array &$done, array &$problems): void
    {
        $storeId = (int) $order->getStoreId();
        $payment = $order->getPayment();
        $method = $payment !== null ? (string) $payment->getMethod() : '';

        if ($method === '' || !in_array($method, $this->config->getCodPaymentMethods($storeId), true)) {
            $problems[] = (string) __(
                'it is not paid by cash on delivery, so it was not invoiced automatically. '
                . 'Check that it is paid and invoice it by hand.'
            );

            return;
        }

        // Only an invoice newer than this one can be the one just created.
        $before = $this->newestInvoice((int) $order->getEntityId());

        try {
            $invoiceId = (int) $this->invoiceOrder->execute(
                (int) $order->getEntityId(),
                false,
                [],
                $this->settings->isInvoiceEmailEnabled($storeId)
            );
            $done[] = (string) __(
                'The courier collected the cash on delivery, so invoice #%1 was created (captured offline).',
                $this->invoiceNumber($invoiceId)
            );
        } catch (\Throwable $e) {
            $this->logger->error('[PigeonExpress] Delivered COD order not invoiced: ' . $e->getMessage(), [
                'order_id' => $order->getEntityId(),
                'reference_number' => $number,
            ]);

            // Magento saves the invoice before it sends the invoice email, so
            // an error there comes after the invoice exists.
            $after = $this->newestInvoice((int) $order->getEntityId());
            if ($after !== null && ($before === null || $after['id'] > $before['id'])) {
                $saved = $after['number'];
                $done[] = (string) __(
                    'Invoice #%1 was created (captured offline), but Magento then reported an error: %2',
                    $saved,
                    $e->getMessage()
                );

                return;
            }

            $problems[] = (string) __('the invoice could not be created: %1', $e->getMessage());
        }
    }

    private function invoiceNumber(int $invoiceId): string
    {
        try {
            $increment = (string) $this->invoiceRepository->get($invoiceId)->getIncrementId();

            return $increment !== '' ? $increment : (string) $invoiceId;
        } catch (\Throwable $e) {
            return (string) $invoiceId;
        }
    }

    /**
     * The order's newest invoice as id and number, null when it has none.
     *
     * @return array{id: int, number: string}|null
     */
    private function newestInvoice(int $orderId): ?array
    {
        try {
            $collection = $this->invoiceCollectionFactory->create();
            $collection->addFieldToFilter('order_id', $orderId);
            $collection->setOrder('entity_id', 'DESC');
            $collection->setPageSize(1);
            $invoice = $collection->getFirstItem();
            $id = (int) $invoice->getId();
            if ($id <= 0) {
                return null;
            }
            $increment = (string) $invoice->getIncrementId();

            return ['id' => $id, 'number' => $increment !== '' ? $increment : (string) $id];
        } catch (\Throwable $e) {
            return null;
        }
    }
}
