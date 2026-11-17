<?php
/**
 * "Download Pigeon Express labels" on Sales > Orders: the label of each
 * selected order's current waybill, in one ZIP named after the waybills, as
 * the WooCommerce bulk action. A ZIP rather than one merged PDF so each label
 * keeps the format Pigeon Express renders it in and its own name.
 *
 * Labels are not stored here (see the Label controller), so each one is
 * fetched from Pigeon Express; at most MAX_FETCHES per download, under the
 * label endpoint's limit. An order whose label could not be had is named,
 * never silently left out.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Magento\Grid;

use Magento\Sales\Api\Data\OrderInterface;
use PigeonExpress\Shipping\Api\ShipmentClientInterface;
use PigeonExpress\Shipping\Model\ShipmentCanceller;
use Psr\Log\LoggerInterface;

class LabelZip
{
    /** As WooCommerce (LabelService::BUNDLE_LABEL_FETCHES). */
    public const MAX_FETCHES = 15;

    /** @var ShipmentClientInterface */
    private $client;

    /** @var ShipmentCanceller */
    private $shipmentFinder;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        ShipmentClientInterface $client,
        ShipmentCanceller $shipmentFinder,
        LoggerInterface $logger
    ) {
        $this->client = $client;
        $this->shipmentFinder = $shipmentFinder;
        $this->logger = $logger;
    }

    /**
     * Write the ZIP to $path.
     *
     * @param iterable<OrderInterface> $orders
     * @return array{count:int, missing:string[], over:string[]} Labels added; orders whose label
     *         Pigeon Express did not send; orders left out over the limit
     */
    public function build(iterable $orders, string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not open the temporary ZIP file.');
        }

        $count = 0;
        $fetched = 0;
        $missing = [];
        $over = [];
        $names = [];

        foreach ($orders as $order) {
            $shipment = $this->shipmentFinder->findShipment((int) $order->getEntityId());
            if ($shipment === null || $shipment->getStatus() === ShipmentCanceller::STATUS_CANCELLED) {
                continue;
            }
            $reference = (string) $shipment->getReferenceNumber();
            if ($reference === '') {
                continue;
            }

            if ($fetched >= self::MAX_FETCHES) {
                $over[] = (string) $order->getIncrementId();
                continue;
            }
            $fetched++;

            try {
                $label = $this->client->label($reference, 'default', true, (int) $order->getStoreId());
                $body = (string) ($label['body'] ?? '');
            } catch (\Throwable $e) {
                $this->logger->warning('[PigeonExpress] Label for ZIP not fetched: ' . $e->getMessage(), [
                    'reference_number' => $reference,
                ]);
                $body = '';
            }

            if ($body === '') {
                $missing[] = (string) $order->getIncrementId();
                continue;
            }

            $name = preg_replace('/[^A-Za-z0-9_-]/', '', $reference) ?: 'order-' . $order->getIncrementId();
            if (isset($names[$name])) {
                $name .= '-' . $order->getIncrementId();
            }
            $names[$name] = true;

            $zip->addFromString($name . '.pdf', $body);
            $count++;
        }

        $zip->close();

        return ['count' => $count, 'missing' => $missing, 'over' => $over];
    }
}
