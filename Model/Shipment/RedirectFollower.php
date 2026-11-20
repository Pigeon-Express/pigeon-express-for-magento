<?php
/**
 * Moves an order to the new shipment when Pigeon Express redirected the one it
 * holds (a change of delivery place once the courier has the parcel makes a
 * new waybill with a new number).
 *
 * Copied from the WooCommerce plugin (QuickActions::follow_redirect(),
 * active_redirect(), readable_target(), move_to_redirect()). Built the Magento
 * way: the order gets a NEW pigeonexpress_shipment row for the new number
 * (redirected_from = the old number), and the old row is marked with the
 * lifecycle `redirected`, so it stays in the order's history and is never
 * cancelled, edited, tracked or followed again. Then:
 *
 *  - the event pigeonexpress_shipment_redirected replaces the old tracking
 *    number on the Magento shipment with the new one
 *    (Observer\Shipment\ReplaceMagentoTrack);
 *  - a private order comment says what happened;
 *  - the customer is emailed the new tracking number, always and once
 *    (Model\Magento\RedirectNotifier).
 *
 * The new shipment must be readable (GET /shipments/{ref}, answering for that
 * number) before anything moves: the order never holds a waybill the module
 * cannot show, cancel or track, and the customer is never given one.
 *
 * Wherever the redirect was made (a quick change whose answer was lost, a
 * Pigeon Express office, its website), the reads of the shipment find it:
 * Refresh and the status check (StatusRefresher, when the raw status is
 * shipment_redirected), and the details read. The new number is only ever a
 * `redirected` link read from the shipment the order holds, followed through
 * redirects of redirects, at most MAX_HOPS of them.
 *
 * Every move holds the order's lock (scope "send", as create, edit, cancel and
 * the quick changes): while another request holds it, the order is left for
 * the next read.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Shipment;

use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PigeonExpress\Shipping\Api\ShipmentClientInterface;
use PigeonExpress\Shipping\Model\Magento\OrderComment;
use PigeonExpress\Shipping\Model\Magento\RedirectNotifier;
use PigeonExpress\Shipping\Model\ResourceModel\Shipment as ShipmentResource;
use PigeonExpress\Shipping\Model\Shipment;
use PigeonExpress\Shipping\Model\ShipmentCanceller;
use PigeonExpress\Shipping\Model\ShipmentFactory;
use PigeonExpress\Shipping\Model\Status\ShipmentDetailsStore;
use PigeonExpress\Shipping\Model\Status\StatusCodes;
use Psr\Log\LoggerInterface;

class RedirectFollower
{
    /** Dispatched after an order moved to a new shipment: order, shipment_row (new), old_row. */
    public const EVENT_REDIRECTED = 'pigeonexpress_shipment_redirected';

    /** Redirects of redirects followed at most, from the order's shipment to the active one. */
    public const MAX_HOPS = 5;

    /** The lock of create, edit, cancel and the quick changes. */
    private const LOCK_SCOPE = 'send';

    /** @var ShipmentClientInterface */
    private $client;

    /** @var ShipmentCanceller */
    private $finder;

    /** @var ShipmentDetailsStore */
    private $details;

    /** @var ShipmentResource */
    private $resource;

    /** @var ShipmentFactory */
    private $shipmentFactory;

    /** @var OrderLock */
    private $lock;

    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var EventManager */
    private $events;

    /** @var OrderComment */
    private $comments;

    /** @var RedirectNotifier */
    private $notifier;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        ShipmentClientInterface $client,
        ShipmentCanceller $finder,
        ShipmentDetailsStore $details,
        ShipmentResource $resource,
        ShipmentFactory $shipmentFactory,
        OrderLock $lock,
        OrderRepositoryInterface $orderRepository,
        EventManager $events,
        OrderComment $comments,
        RedirectNotifier $notifier,
        LoggerInterface $logger
    ) {
        $this->client = $client;
        $this->finder = $finder;
        $this->details = $details;
        $this->resource = $resource;
        $this->shipmentFactory = $shipmentFactory;
        $this->lock = $lock;
        $this->orderRepository = $orderRepository;
        $this->events = $events;
        $this->comments = $comments;
        $this->notifier = $notifier;
        $this->logger = $logger;
    }

    /**
     * Move the order to the shipment that carries the parcel now when the one
     * it holds was redirected. For the reads of the shipment (Refresh, the
     * status check, the details read).
     *
     * @param array<string, mixed> $data The carrier's `data` for $reference when read already, else []
     * @return string The number the order moved to, or '' when it did not move (also when the new
     *                shipment could not be read: the next read tries again)
     */
    public function follow(int $orderId, string $reference, array $data = [], int $storeId = 0): string
    {
        $reference = self::cleanReference($reference);
        if ($orderId <= 0 || $reference === '' || !$this->lock->acquire($orderId, self::LOCK_SCOPE)) {
            return '';
        }

        try {
            $row = $this->currentRow($orderId, $reference);
            if ($row === null) {
                return '';
            }

            $found = $this->activeRedirect($reference, $data, $storeId);
            if ($found['reference'] === $reference) {
                return '';
            }

            $lines = [(string) __(
                'Found when the shipment was read from Pigeon Express. The redirect may have been made at a '
                . 'Pigeon Express office, on its website, or by a change whose answer did not arrive.'
            )];
            if (count($found['path']) > 1) {
                $lines[] = (string) __(
                    'Redirected more than once: %1',
                    implode(' → ', array_merge([$reference], $found['path']))
                );
            }

            $target = $this->readableTarget($found['reference'], $found['data'], $storeId);
            if ($target === []) {
                $this->logger->info('[PigeonExpress] Redirect of ' . $reference . ' to ' . $found['reference']
                    . ' found, but the new shipment could not be read; order left as it is');

                return '';
            }

            return $this->move($row, $found['reference'], $lines, $storeId, $target) === null
                ? ''
                : $found['reference'];
        } finally {
            $this->lock->release($orderId, self::LOCK_SCOPE);
        }
    }

    /**
     * Move the order from the shipment row it holds to the new shipment, for a
     * caller that holds the order's lock already (a quick change).
     *
     * @param string[] $lines The private comment's lines after its first
     * @param array<string, mixed> $target The carrier's `data` for the new shipment when read already, else []
     * @param callable|null $adjust fn(array $payload, array $formFields): array{0: array, 1: array},
     *                              what the change that made the redirect sent, kept on the new row
     * @return bool|null Whether the customer was emailed; null when the order was not moved
     */
    public function move(
        Shipment $row,
        string $newReference,
        array $lines,
        int $storeId,
        array $target = [],
        ?callable $adjust = null
    ): ?bool {
        $reference = self::cleanReference((string) $row->getReferenceNumber());
        $newReference = self::cleanReference($newReference);
        $orderId = $row->getOrderId();

        if ($reference === '' || $newReference === '' || $newReference === $reference) {
            return null;
        }

        // Still the shipment the order holds: nothing moved it meanwhile.
        $current = $this->currentRow($orderId, $reference);
        if ($current === null || (int) $current->getId() !== (int) $row->getId()) {
            return null;
        }

        // Whoever calls reads the new shipment first; never moved without it.
        $target = $this->readableTarget($newReference, $target, $storeId);
        if ($target === []) {
            return null;
        }

        try {
            $order = $this->orderRepository->get($orderId);
        } catch (\Throwable $e) {
            return null;
        }

        $new = $this->insertRow($current, $newReference, $target, $adjust);
        if ($new === null) {
            return null;
        }

        $this->logger->info('[PigeonExpress] Order #' . $orderId . ' moved from shipment ' . $reference
            . ' to ' . $newReference . ' (redirect)');

        // The new shipment's details and status, read just now.
        try {
            $this->details->remember($new, $target);
        } catch (\Throwable $e) {
            $this->logger->warning('[PigeonExpress] Details of ' . $newReference . ' not kept: ' . $e->getMessage());
        }

        $this->dispatch($order, $current, $new);

        $lines[] = (string) __('The new label is fetched from Pigeon Express when you open or download it.');
        $this->comments->add($order, (string) __(
            'Pigeon Express shipment %1 was redirected; the new shipment is %2.',
            $reference,
            $newReference
        ) . "\n" . implode("\n", $lines));

        // The number the customer was given no longer follows the parcel.
        return $this->notifier->notify($order, $new);
    }

    /**
     * The carrier's `data` for a redirect target when it can be read and is
     * the shipment asked for, else [].
     *
     * @param array<string, mixed> $data The carrier's `data` for $target when read already, else []
     * @return array<string, mixed>
     */
    public function readableTarget(string $target, array $data = [], int $storeId = 0): array
    {
        $target = self::cleanReference($target);
        if ($target === '') {
            return [];
        }

        if ($data === []) {
            $data = $this->shipmentData($target, $storeId);
        }
        if ($data === []) {
            return [];
        }

        $own = self::cleanReference($data['reference_number'] ?? '');

        return $own === '' || $own === $target ? $data : [];
    }

    /**
     * The shipment that carries the parcel now, from the `redirected` link of
     * $reference and of each shipment it leads to, at most MAX_HOPS of them. A
     * longer chain stops there, and the next read goes on from where it
     * stopped.
     *
     * @param array<string, mixed> $data The carrier's `data` for $reference when read already, else []
     * @return array{reference: string, path: string[], data: array<string, mixed>} The last shipment
     *         ($reference itself when it is not redirected), the numbers after $reference, and the
     *         last one's `data` when it was read, else []
     */
    public function activeRedirect(string $reference, array $data = [], int $storeId = 0): array
    {
        $current = $reference;
        $path = [];

        for ($hop = 0; $hop <= self::MAX_HOPS; $hop++) {
            if ($data === []) {
                $data = $this->shipmentData($current, $storeId);
            }

            $next = self::redirectOf($data);

            if ($next === '' || $hop === self::MAX_HOPS || $next === $reference || in_array($next, $path, true)) {
                break;
            }

            $path[] = $next;
            $current = $next;
            $data = [];
        }

        return ['reference' => $current, 'path' => $path, 'data' => $data];
    }

    /**
     * The shipment a redirected one moved to, from the `redirected` link in
     * the carrier's `data` for it (GET /shipments/{ref}), or ''. Also takes
     * the trimmed `related` rows kept on a row.
     *
     * @param array<string, mixed> $data
     */
    public static function redirectOf(array $data): string
    {
        $links = isset($data['related_shipments']) && is_array($data['related_shipments'])
            ? $data['related_shipments']
            : (isset($data['related']) && is_array($data['related']) ? $data['related'] : []);

        foreach ($links as $link) {
            if (is_array($link) && (string) ($link['relation'] ?? '') === 'redirected') {
                return self::cleanReference($link['reference_number'] ?? ($link['reference'] ?? ''));
            }
        }

        return '';
    }

    /**
     * @param mixed $reference
     */
    public static function cleanReference($reference): string
    {
        return is_scalar($reference) ? (string) preg_replace('/[^A-Za-z0-9_-]/', '', (string) $reference) : '';
    }

    /**
     * The order's current shipment row when it still holds $reference and is
     * live, else null.
     */
    private function currentRow(int $orderId, string $reference): ?Shipment
    {
        $row = $this->finder->findShipment($orderId);

        if ($row === null
            || self::cleanReference((string) $row->getReferenceNumber()) !== $reference
            || $row->getStatus() === ShipmentCanceller::STATUS_CANCELLED
            || $row->getStatus() === Shipment::STATUS_REDIRECTED
        ) {
            return null;
        }

        return $row;
    }

    /**
     * The new shipment's row, written with the old one marked redirected in
     * one transaction: the order never holds both, or neither.
     *
     * @param array<string, mixed> $target
     */
    private function insertRow(Shipment $old, string $newReference, array $target, ?callable $adjust): ?Shipment
    {
        // The whole stored payload and form go with the order, so the return
        // destination recorded at create (return_office_id, return_address_id,
        // return_documents_*, return_at_my_expense) still shows ("Return to")
        // and a later Edit keeps it. A quick change only alters its own keys.
        $payload = json_decode((string) $old->getPayload(), true);
        $form = json_decode((string) $old->getFormFields(), true);
        $payload = is_array($payload) ? $payload : [];
        $form = is_array($form) ? $form : [];

        if ($adjust !== null) {
            [$payload, $form] = $adjust($payload, $form);
        }

        $pricing = isset($target['pricing']) && is_array($target['pricing']) ? $target['pricing'] : [];

        $new = $this->shipmentFactory->create();
        $new->setOrderId($old->getOrderId());
        $new->setReferenceNumber($newReference);
        $new->setTrackingNumber($this->trackingNumber($target, $newReference));
        $new->setStatus('sent');
        $new->setDeliveryPrice(is_numeric($pricing['total'] ?? null)
            ? (float) $pricing['total']
            : $old->getDeliveryPrice());
        $new->setPayload($payload !== [] ? (string) json_encode($payload) : $old->getPayload());
        $new->setFormFields($form !== [] ? (string) json_encode($form) : $old->getFormFields());
        $new->setResponse((string) json_encode([
            'reference_number' => $newReference,
            'redirected_from'  => (string) $old->getReferenceNumber(),
        ]));
        $new->setRedirectedFrom((string) $old->getReferenceNumber());

        $connection = $this->resource->getConnection();
        $connection->beginTransaction();
        try {
            $this->resource->save($new);
            $connection->update(
                $this->resource->getMainTable(),
                [
                    'status'            => Shipment::STATUS_REDIRECTED,
                    'status_code'       => StatusCodes::REDIRECTED,
                    'status_checked_at' => gmdate('Y-m-d H:i:s'),
                ],
                ['entity_id = ?' => (int) $old->getId()]
            );
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            $this->logger->error('[PigeonExpress] Redirect of ' . $old->getReferenceNumber() . ' to '
                . $newReference . ' not saved: ' . $e->getMessage());

            return null;
        }

        $old->setStatus(Shipment::STATUS_REDIRECTED);
        $old->setData('status_code', StatusCodes::REDIRECTED);

        return $new;
    }

    /**
     * The first package barcode of the new shipment, else its number.
     *
     * @param array<string, mixed> $target
     */
    private function trackingNumber(array $target, string $reference): string
    {
        foreach ((array) ($target['packages'] ?? []) as $package) {
            $barcode = is_array($package) ? self::cleanReference($package['tracking_number'] ?? '') : '';
            if ($barcode !== '') {
                return $barcode;
            }
        }

        return $reference;
    }

    private function dispatch(OrderInterface $order, Shipment $old, Shipment $new): void
    {
        try {
            $this->events->dispatch(self::EVENT_REDIRECTED, [
                'order'        => $order,
                'shipment_row' => $new->getData(),
                'old_row'      => $old->getData(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[PigeonExpress] Shipment redirected observer failed: ' . $e->getMessage());
        }
    }

    /**
     * The carrier's `data` for a shipment, or [] when it could not be read.
     *
     * @return array<string, mixed>
     */
    private function shipmentData(string $reference, int $storeId): array
    {
        try {
            $data = $this->client->get($reference, $storeId)->toArray();
        } catch (\Throwable $e) {
            return [];
        }

        return is_array($data) ? $data : [];
    }
}
