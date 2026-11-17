<?php
/**
 * Reads shipment statuses from Pigeon Express in batches and records them.
 *
 * Copied from the WooCommerce plugin (Cron/StatusSync.php, refresh()). One
 * bulk request per hundred shipments (the carrier's maximum, and one request
 * where a hundred single ones would eat into the per-minute allowance
 * checkout needs for its prices). One failing batch does not stop the rest; a
 * rate limit does, because the next request would be refused too.
 *
 * Used by the background check (Model\Cron\SyncStatuses), the order panel's
 * "Refresh" and the orders grid mass action alike.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Status;

use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Api\ShipmentClientInterface;
use PigeonExpress\Shipping\Exception\RateLimitedException;
use PigeonExpress\Shipping\Model\Api\ShipmentClient;
use PigeonExpress\Shipping\Model\ResourceModel\Shipment\CollectionFactory;
use PigeonExpress\Shipping\Model\Shipment;
use PigeonExpress\Shipping\Model\ShipmentCanceller;
use Psr\Log\LoggerInterface;

class StatusRefresher
{
    /** A shipment still not final this many days after it was created is no longer checked. */
    public const TRACK_DAYS = 60;

    /** @var ShipmentClientInterface */
    private $client;

    /** @var CollectionFactory */
    private $collectionFactory;

    /** @var TrackingInterpreter */
    private $interpreter;

    /** @var StatusRecorder */
    private $recorder;

    /** @var ShipmentDetailsStore */
    private $details;

    /** @var ConfigInterface */
    private $config;

    /** @var LoggerInterface */
    private $logger;

    /** @var int */
    private $batchSize;

    /**
     * Details reads left in this run. Each is one GET per shipment, so they
     * are kept for the changes that move money and capped; a rate limit
     * ends them for the run.
     *
     * @var int
     */
    private $detailsLeft = self::DETAILS_PER_RUN;

    /** Most details (GET /shipments/{ref}) one run reads after status changes. */
    public const DETAILS_PER_RUN = 20;

    public function __construct(
        ShipmentClientInterface $client,
        CollectionFactory $collectionFactory,
        TrackingInterpreter $interpreter,
        StatusRecorder $recorder,
        ShipmentDetailsStore $details,
        ConfigInterface $config,
        LoggerInterface $logger,
        int $batchSize = ShipmentClient::TRACK_BULK_MAX
    ) {
        $this->client = $client;
        $this->collectionFactory = $collectionFactory;
        $this->interpreter = $interpreter;
        $this->recorder = $recorder;
        $this->details = $details;
        $this->config = $config;
        $this->logger = $logger;
        $this->batchSize = max(1, min(ShipmentClient::TRACK_BULK_MAX, $batchSize));
    }

    /**
     * Read the status of these orders' current shipments now. Orders without
     * a live shipment are left out of the counts.
     *
     * With a limit, at most that many shipments are read and the rest are
     * counted in `left_out`, as WooCommerce's bulk refresh does.
     *
     * @param int[] $orderIds
     * @return array{updated:int, unchanged:int, failed:int, left_out:int}
     */
    public function refreshOrders(array $orderIds, int $limit = 0): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
        if ($ids === []) {
            return ['updated' => 0, 'unchanged' => 0, 'failed' => 0, 'left_out' => 0];
        }

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('order_id', ['in' => $ids]);
        $collection->addFieldToFilter('status', ['neq' => ShipmentCanceller::STATUS_CANCELLED]);
        $collection->addFieldToFilter('reference_number', ['notnull' => true]);
        $collection->setOrder('entity_id', 'DESC');

        // The newest live shipment of each order is the one it carries.
        $rows = [];
        /** @var Shipment $row */
        foreach ($collection->getItems() as $row) {
            if (!isset($rows[$row->getOrderId()])) {
                $rows[$row->getOrderId()] = $row;
            }
        }

        $rows = array_values($rows);
        $leftOut = 0;
        if ($limit > 0 && count($rows) > $limit) {
            $leftOut = count($rows) - $limit;
            $rows = array_slice($rows, 0, $limit);
        }

        $result = $this->refreshShipments($rows);

        return [
            'updated'   => $result['updated'],
            'unchanged' => $result['unchanged'],
            'failed'    => $result['failed'] + $result['not_found'],
            'left_out'  => $leftOut,
        ];
    }

    /**
     * Read and record the status of these shipment rows, a batch per request.
     *
     * A row that reached a final status (delivered, returned, ...) from a
     * status known before also has its details read again, so the charges
     * follow (a return adds its fees). See wantsDetails().
     *
     * @param Shipment[] $rows
     * @return array{updated:int, unchanged:int, failed:int, not_found:int, rate_limited:bool}
     */
    public function refreshShipments(array $rows): array
    {
        $summary = ['updated' => 0, 'unchanged' => 0, 'failed' => 0, 'not_found' => 0, 'rate_limited' => false];
        $this->detailsLeft = self::DETAILS_PER_RUN;

        $byReference = [];
        foreach ($rows as $row) {
            $reference = trim((string) $row->getReferenceNumber());
            if ($reference !== '' && $row->getStatus() !== ShipmentCanceller::STATUS_CANCELLED) {
                $byReference[$reference][] = $row;
            }
        }

        $batches = array_chunk(array_keys($byReference), $this->batchSize);
        foreach ($batches as $index => $references) {
            $references = array_map('strval', $references);
            $count = $this->countRows($byReference, $references);

            try {
                $entries = $this->client->trackBulk($references, 0);
            } catch (RateLimitedException $e) {
                // Every batch left, this one included, waits for the next run.
                $summary['rate_limited'] = true;
                for ($rest = $index; $rest < count($batches); $rest++) {
                    $summary['failed'] += $this->countRows($byReference, array_map('strval', $batches[$rest]));
                }
                $this->logger->warning('[PigeonExpress] Status check rate limited; the rest waits for the next run');
                break;
            } catch (\Throwable $e) {
                $summary['failed'] += $count;
                $this->logger->warning('[PigeonExpress] Status batch of ' . $count . ' failed: ' . $e->getMessage());
                continue;
            }

            foreach ($references as $reference) {
                $entry = $entries[$reference] ?? null;
                $status = is_array($entry) ? $this->interpreter->interpret($entry) : null;

                if ($status !== null && isset($status['confirm'])) {
                    $status = $this->confirmReturn($status, $reference, $byReference[$reference]);
                }

                foreach ($byReference[$reference] as $row) {
                    if ($status === null) {
                        $summary['not_found']++;
                        continue;
                    }

                    $oldCode = (string) $row->getStatusCode();
                    try {
                        $changed = $this->recorder->record($row, $status['code'], $status['name'], $status['at']);
                    } catch (\Throwable $e) {
                        $summary['failed']++;
                        $this->logger->warning(
                            '[PigeonExpress] Status of ' . $reference . ' not recorded: ' . $e->getMessage()
                        );
                        continue;
                    }

                    if ($changed) {
                        $summary['updated']++;
                        if ($this->wantsDetails($oldCode, StatusCodes::clean((string) $status['code']))) {
                            $this->refreshDetails($row);
                        }
                    } else {
                        $summary['unchanged']++;
                    }
                }
            }
        }

        if ($this->config->isLoggingEnabled()) {
            $this->logger->info('[PigeonExpress] Status check', $summary + ['shipments' => count($byReference)]);
        }

        return $summary;
    }

    /**
     * Every shipment still being followed: created here, not cancelled, not
     * in a final status, and younger than TRACK_DAYS. Oldest first.
     *
     * @return Shipment[]
     */
    public function openShipments(): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('status', ['neq' => ShipmentCanceller::STATUS_CANCELLED]);
        $collection->addFieldToFilter('reference_number', ['notnull' => true]);
        $collection->addFieldToFilter(
            'status_code',
            [['null' => true], ['nin' => StatusCodes::FINAL_CODES]]
        );
        $collection->addFieldToFilter(
            'sent_at',
            ['gteq' => gmdate('Y-m-d H:i:s', time() - self::TRACK_DAYS * 86400)]
        );
        $collection->setOrder('entity_id', 'ASC');

        return array_values($collection->getItems());
    }

    /**
     * Whether a status change is worth one GET of the shipment's details.
     *
     * Only a change into a final status changes the charges (a return adds
     * its fees, a delivery settles the cash on delivery). A row with no
     * status before was never checked (the first run after an update), and
     * reading every one of them would spend the per-minute allowance
     * checkout needs for its prices: its charges are read when the merchant
     * opens them ("Show charges").
     */
    public function wantsDetails(string $oldCode, string $newCode): bool
    {
        return $oldCode !== '' && $oldCode !== $newCode && StatusCodes::isFinal($newCode);
    }

    /**
     * Read the details again after a status change. Failure changes nothing:
     * the box keeps the copy it has.
     */
    private function refreshDetails(Shipment $row): void
    {
        if ($this->detailsLeft <= 0) {
            return;
        }
        $this->detailsLeft--;

        try {
            $this->details->fetch($row, 0);
        } catch (RateLimitedException $e) {
            // The next would be refused too; the statuses are recorded anyway.
            $this->detailsLeft = 0;
            $this->logger->warning('[PigeonExpress] Details read rate limited; the rest waits');
        } catch (\Throwable $e) {
            $this->logger->info('[PigeonExpress] Details of ' . $row->getReferenceNumber() . ' not refreshed: '
                . $e->getMessage());
        }
    }

    /**
     * A "returned" read from the shipment chain counts only once the carrier
     * confirms the link is a return to sender, in the details of the shipment
     * it was made from (one request). Otherwise the first shipment's own
     * status is recorded. Unanswered, the parcel is recorded as on its way
     * back, and asked about again next time.
     *
     * @param array<string, mixed> $status What TrackingInterpreter::interpret() returned
     * @param Shipment[] $rows
     * @return array{code:string, name:string, at:string}
     */
    private function confirmReturn(array $status, string $reference, array $rows): array
    {
        $confirm = is_array($status['confirm'] ?? null) ? $status['confirm'] : [];
        $fallback = is_array($status['fallback'] ?? null) ? $status['fallback'] : [];
        $returned = ['code' => (string) $status['code'], 'name' => (string) $status['name'], 'at' => (string) $status['at']];
        $root = [
            'code' => (string) ($fallback['code'] ?? ''),
            'name' => (string) ($fallback['name'] ?? ''),
            'at'   => (string) ($fallback['at'] ?? ''),
        ];

        // Confirmed when it was first recorded.
        foreach ($rows as $row) {
            if ($row->getStatusCode() === StatusCodes::RETURNED) {
                return $returned;
            }
        }

        $parent = (string) ($confirm['parent'] ?? '');
        $link = (string) ($confirm['reference'] ?? '');
        $parent = $parent !== '' ? $parent : $reference;
        if ($link === '') {
            return $root;
        }

        try {
            $details = $this->client->get($parent, 0)->toArray();
        } catch (\Throwable $e) {
            return [
                'code' => StatusCodes::RETURNING_TO_SENDER,
                'name' => '',
                'at'   => (string) ($confirm['started'] ?? ''),
            ];
        }

        $relation = '';
        foreach ((array) ($details['related_shipments'] ?? []) as $related) {
            if (is_array($related) && (string) ($related['reference_number'] ?? '') === $link) {
                $relation = (string) ($related['relation'] ?? '');
            }
        }

        if ($relation !== 'return_to_sender') {
            return $root;
        }

        if ($returned['at'] === '') {
            $returned['at'] = (string) ($confirm['started'] ?? '');
        }

        return $returned;
    }

    /**
     * @param array<string, Shipment[]> $byReference
     * @param string[] $references
     */
    private function countRows(array $byReference, array $references): int
    {
        $count = 0;
        foreach ($references as $reference) {
            $count += count($byReference[$reference] ?? []);
        }

        return $count;
    }
}
