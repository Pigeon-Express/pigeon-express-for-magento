<?php
/**
 * "Create Pigeon Express shipments" on Sales > Orders: a waybill for each
 * selected order that is sent with Pigeon Express and has none yet, from the
 * order's own defaults, exactly as the order panel's Create would with
 * nothing changed. Copied from the WooCommerce bulk action.
 *
 * Paid orders only, as WooCommerce. In Magento a cash on delivery order is
 * not paid until it is invoiced and stays "Pending" (state new) meanwhile, so
 * a new order paid by a cash on delivery method counts too; anything else
 * not in Processing is reported, not skipped silently, because the merchant
 * picked it on purpose.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Magento\Grid;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use PigeonExpress\Shipping\Api\ShipmentSenderInterface;
use PigeonExpress\Shipping\Model\ShipmentCanceller;

class MassCreate
{
    /** @var ShipmentSenderInterface */
    private $sender;

    /** @var ShipmentCanceller */
    private $shipmentFinder;

    public function __construct(ShipmentSenderInterface $sender, ShipmentCanceller $shipmentFinder)
    {
        $this->sender = $sender;
        $this->shipmentFinder = $shipmentFinder;
    }

    /**
     * @param iterable<OrderInterface> $orders
     * @return array{created:int, skipped:int, failed:array<string, string>} Failed keyed by order number
     */
    public function run(iterable $orders): array
    {
        $created = 0;
        $skipped = 0;
        $failed = [];

        foreach ($orders as $order) {
            if ($this->isSkipped($order)) {
                $skipped++;
                continue;
            }

            $refusal = $this->refusal($order);
            if ($refusal !== null) {
                $failed[(string) $order->getIncrementId()] = $refusal;
                continue;
            }

            try {
                $this->sender->send($order);
                $created++;
            } catch (\Throwable $e) {
                $failed[(string) $order->getIncrementId()] = $e->getMessage();
            }
        }

        return ['created' => $created, 'skipped' => $skipped, 'failed' => $failed];
    }

    /**
     * Not a Pigeon Express order, or it already has a live waybill.
     */
    public function isSkipped(OrderInterface $order): bool
    {
        if (strpos((string) $order->getShippingMethod(), 'pigeonexpress_') !== 0) {
            return true;
        }

        $shipment = $this->shipmentFinder->findShipment((int) $order->getEntityId());

        return $shipment !== null && $shipment->getStatus() !== ShipmentCanceller::STATUS_CANCELLED;
    }

    /**
     * Why a Pigeon Express order without a waybill is not shipped in bulk, or
     * null when it is.
     */
    public function refusal(OrderInterface $order): ?string
    {
        $state = (string) $order->getState();

        if ($state === Order::STATE_PROCESSING) {
            return null;
        }

        if ($state === Order::STATE_NEW && $this->sender->isCashOnDelivery($order)) {
            return null;
        }

        $label = $order instanceof Order ? (string) $order->getStatusLabel() : '';

        return (string) __(
            'not created because the order status is "%1". Only paid orders (Processing) and cash on '
            . 'delivery orders are shipped in bulk.',
            $label !== '' ? $label : (string) $order->getStatus()
        );
    }

    /**
     * The notice WooCommerce shows after the bulk action, in plain text.
     *
     * @param array{created:int, skipped:int, failed:array<string, string>} $summary
     */
    public function summaryText(array $summary): string
    {
        $line = (string) __(
            'Pigeon Express bulk create: %1 created, %2 skipped, %3 failed.',
            $summary['created'],
            $summary['skipped'],
            count($summary['failed'])
        );

        if ($summary['failed'] !== []) {
            $details = [];
            foreach ($summary['failed'] as $number => $message) {
                $details[] = '#' . $number . ' - ' . $message;
            }
            $line .= ' ' . implode('; ', $details);
        }

        return $line;
    }
}
