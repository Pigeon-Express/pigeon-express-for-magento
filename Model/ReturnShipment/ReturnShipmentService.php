<?php
/**
 * Create, cancel and read back the return shipment of an order.
 *
 * Kept apart from the order's own shipment (pigeonexpress_return_shipment),
 * created and cancelled under a lock of its own scope so it never waits on the
 * order's shipment, and read back only on request. As WooCommerce's
 * ReverseShipmentService.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\ReturnShipment;

use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderCommentSender;
use PigeonExpress\Shipping\Api\ShipmentClientInterface;
use PigeonExpress\Shipping\Model\Api\ShipmentClient;
use PigeonExpress\Shipping\Model\Environment;
use PigeonExpress\Shipping\Model\Magento\OrderComment;
use PigeonExpress\Shipping\Model\Magento\TrackingUrl;
use PigeonExpress\Shipping\Model\Shipment\OrderLock;
use PigeonExpress\Shipping\Model\Status\ShipmentDetailsStore;
use PigeonExpress\Shipping\Model\Status\StatusCodes;
use Psr\Log\LoggerInterface;

class ReturnShipmentService
{
    /** Lock scope, so a return never waits on the order's own shipment. */
    public const LOCK_SCOPE = 'reverse';

    /** @var ShipmentClientInterface */
    private $client;

    /** @var ReturnShipmentRepository */
    private $repository;

    /** @var ReturnBodyBuilder */
    private $builder;

    /** @var Availability */
    private $availability;

    /** @var OrderLock */
    private $lock;

    /** @var OrderComment */
    private $comments;

    /** @var OrderCommentSender */
    private $commentSender;

    /** @var TrackingUrl */
    private $trackingUrl;

    /** @var Environment */
    private $environment;

    /** @var ShipmentDetailsStore */
    private $detailsStore;

    /** @var AuthSession */
    private $authSession;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        ShipmentClientInterface $client,
        ReturnShipmentRepository $repository,
        ReturnBodyBuilder $builder,
        Availability $availability,
        OrderLock $lock,
        OrderComment $comments,
        OrderCommentSender $commentSender,
        TrackingUrl $trackingUrl,
        Environment $environment,
        ShipmentDetailsStore $detailsStore,
        AuthSession $authSession,
        LoggerInterface $logger
    ) {
        $this->client = $client;
        $this->repository = $repository;
        $this->builder = $builder;
        $this->availability = $availability;
        $this->lock = $lock;
        $this->comments = $comments;
        $this->commentSender = $commentSender;
        $this->trackingUrl = $trackingUrl;
        $this->environment = $environment;
        $this->detailsStore = $detailsStore;
        $this->authSession = $authSession;
        $this->logger = $logger;
    }

    /**
     * Create the return shipment and keep it on the order.
     *
     * @param array<string, mixed> $form As ReturnBodyBuilder::fromRequest() returns it
     * @return array<string, mixed> The stored row
     * @throws LocalizedException
     */
    public function create(OrderInterface $order, array $form): array
    {
        if (!$this->availability->isEnabled()) {
            throw new LocalizedException(__('Return shipments are switched off in the Pigeon Express settings.'));
        }

        $orderId = (int) $order->getEntityId();
        if (!$this->lock->acquire($orderId, self::LOCK_SCOPE)) {
            throw new LocalizedException(__(
                'The return shipment of this order is being created or cancelled right now. Reload the order in a moment.'
            ));
        }

        try {
            return $this->createLocked($order, $form);
        } finally {
            $this->lock->release($orderId, self::LOCK_SCOPE);
        }
    }

    /**
     * @param array<string, mixed> $form
     * @return array<string, mixed>
     * @throws LocalizedException
     */
    private function createLocked(OrderInterface $order, array $form): array
    {
        $orderId = (int) $order->getEntityId();
        $storeId = (int) $order->getStoreId();

        if ($this->repository->findActive($orderId) !== null) {
            throw new LocalizedException(__(
                'This order already has a return shipment. Cancel it before creating a new one.'
            ));
        }

        $body = $this->builder->build($order, $form, $storeId);

        try {
            $result = $this->client->create($body, $storeId);
        } catch (LocalizedException $e) {
            if (Availability::isNotEnabledRefusal($e)) {
                $this->availability->markUnavailable();

                throw new NotEnabledException(__(
                    'Pigeon Express refused the return shipment: return shipments are not enabled in your Pigeon Express account. '
                    . 'Contact your Pigeon Express representative to enable them, then save the Pigeon Express settings to check again.'
                ), $e);
            }

            throw $e;
        }

        $reference = (string) ($result['reference_number'] ?? '');
        $replay = (string) ($result['replay'] ?? ShipmentClient::REPLAY_NONE);
        if ($replay !== ShipmentClient::REPLAY_NONE && $this->isReplayOfCancelled($orderId, $reference, $storeId)) {
            throw new LocalizedException(__(
                'The same return shipment was sent again less than half a minute after the one that was cancelled. '
                . 'Nothing was saved. Wait 30 seconds and create it again.'
            ));
        }

        // Taken, so the account has them, whatever was remembered before.
        if ($this->availability->isUnavailable()) {
            $this->availability->clear();
        }

        $sent = $this->sentValues($form, $body);
        $user = $this->authSession->getUser();
        $row = [
            'order_id'         => $orderId,
            'environment'      => $this->environment->current(),
            'reference_number' => $reference,
            'tracking_number'  => (string) ($result['tracking_number'] ?? '') ?: null,
            'status'           => ReturnShipmentRepository::STATUS_CREATED,
            'status_code'      => StatusCodes::clean((string) ($result['status'] ?? StatusCodes::REGISTERED)),
            'payload'          => $body,
            'sent_json'        => $sent,
            'admin_user_id'    => $user ? (int) $user->getId() : null,
        ];
        try {
            $this->repository->insert($row);
        } catch (\Throwable $e) {
            // It exists at the carrier: creating it again would make a second one.
            $this->logger->critical('[PigeonExpress] Return shipment ' . $reference . ' created but not stored: '
                . $e->getMessage());
            throw new LocalizedException(__(
                'The return shipment was created at Pigeon Express as %1 but could not be saved here. '
                . 'Do not create it again; note the number to cancel or follow it in your Pigeon Express account.',
                $reference
            ), $e);
        }

        $this->comments->add($order, implode(' ', [
            (string) __('Pigeon Express return shipment %1 was created.', $reference),
            (string) __('The customer sends it from: %1.', self::pickupText($sent)),
            (string) __('Delivered to: %1.', self::deliveryText($sent)),
            $sent['who_pays'] === 'sender'
                ? (string) __('Courier paid by: Sender (customer).')
                : (string) __('Courier paid by: Receiver (store).'),
        ]));

        if (!empty($form['notify_customer'])) {
            $this->notifyCustomer($order, $this->customerNote($reference, $sent));
        }

        return $this->repository->findActive($orderId) ?? $row;
    }

    private function isReplayOfCancelled(int $orderId, string $reference, int $storeId): bool
    {
        if ($reference === '') {
            return false;
        }
        if ($this->repository->isCancelled($orderId, $reference)) {
            return true;
        }

        try {
            $status = $this->client->get($reference, $storeId)->getStatus();
            $code = is_object($status) && method_exists($status, 'getCode') ? (string) $status->getCode() : '';

            return $code === StatusCodes::CANCELLED;
        } catch (\Throwable $e) {
            // Kept: a real one left off the order would be created twice.
            return false;
        }
    }

    /**
     * The comment the customer sees in their account and gets by email, the
     * way Magento's own "Notify Customer by Email" sends an order comment.
     */
    private function notifyCustomer(OrderInterface $order, string $text): void
    {
        $saved = $this->comments->addForCustomer($order, $text, true);
        if (!$saved || !$order instanceof Order) {
            return;
        }

        try {
            $this->commentSender->send($order, true, $text);
        } catch (\Throwable $e) {
            $this->logger->error('[PigeonExpress] Return shipment email not sent: ' . $e->getMessage(), [
                'order_id' => $order->getEntityId(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $sent
     */
    public function customerNote(string $reference, array $sent): string
    {
        $text = (string) __(
            'We have created a Pigeon Express waybill for sending your order back to us. Waybill number: %1. Track it here: %2',
            $reference,
            $this->trackingUrl->forNumber($reference)
        );

        $place = trim((string) ($sent['pickup_label'] ?? ''));
        if ($place === '') {
            return $text;
        }

        return $text . "\n" . (($sent['pickup_type'] ?? '') === ReturnBodyBuilder::PICKUP_ADDRESS
            ? (string) __('Collection address: %1.', $place)
            : (string) __('Hand the parcel in at: %1.', $place));
    }

    /**
     * What the return was sent with, for the panel and the comments.
     *
     * @param array<string, mixed> $form
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function sentValues(array $form, array $body): array
    {
        $pickupType = (string) ($body['pickup_type'] ?? '');
        $sent = [
            'sender_name'    => (string) ($body['sender_name'] ?? ''),
            'sender_phone'   => trim((string) ($form['sender_phone'] ?? '')) ?: (string) ($body['sender_phone'] ?? ''),
            'sender_email'   => (string) ($body['sender_email'] ?? ''),
            'pickup_type'    => $pickupType,
            'pickup_label'   => '',
            'delivery_type'  => (string) ($body['delivery_type'] ?? ''),
            'delivery_label' => '',
            'who_pays'       => (string) ($body['who_pays'] ?? 'receiver'),
            'packages'       => (array) ($body['packages'] ?? []),
        ];

        if ($pickupType === ReturnBodyBuilder::PICKUP_ADDRESS) {
            $address = (array) ($body['pickup_address'] ?? []);
            $street = (int) ($address['street_id'] ?? 0) > 0 ? trim((string) ($form['street_label'] ?? '')) : '';
            $sent['pickup_label'] = implode(', ', array_filter([
                trim((string) ($form['city_label'] ?? '')),
                trim($street . ' ' . (string) ($address['additional_info'] ?? '')),
            ]));
        } else {
            $field = $pickupType === ReturnBodyBuilder::PICKUP_LOCKER ? 'pickup_locker' : 'pickup_office';
            $id = (string) (int) ($body['pickup_office_id'] ?? 0);
            $label = trim((string) ($form[$field . '_label'] ?? ''));
            $sent['pickup_label'] = $label !== '' ? $label : ($pickupType === ReturnBodyBuilder::PICKUP_LOCKER
                ? (string) __('Parcel locker %1', $id)
                : (string) __('Office %1', $id));
        }

        if ($sent['delivery_type'] === ReturnBodyBuilder::DELIVERY_OFFICE) {
            $label = trim((string) ($form['delivery_office_label'] ?? ''));
            $sent['delivery_label'] = $label !== ''
                ? $label
                : (string) __('Office %1', (string) (int) ($body['delivery_office_id'] ?? 0));
        } elseif (isset($body['delivery_address']) || isset($body['delivery_address_id'])) {
            $sent['delivery_label'] = (string) __('Shop address');
        }

        return $sent;
    }

    /**
     * @param array<string, mixed> $sent
     */
    public static function pickupText(array $sent): string
    {
        switch ((string) ($sent['pickup_type'] ?? '')) {
            case ReturnBodyBuilder::PICKUP_OFFICE:
                $kind = (string) __('Office');
                break;
            case ReturnBodyBuilder::PICKUP_LOCKER:
                $kind = (string) __('Parcel locker');
                break;
            default:
                $kind = (string) __('Address');
        }
        $place = trim((string) ($sent['pickup_label'] ?? ''));

        return $place !== '' ? $kind . ': ' . $place : $kind;
    }

    /**
     * @param array<string, mixed> $sent
     */
    public static function deliveryText(array $sent): string
    {
        $place = trim((string) ($sent['delivery_label'] ?? ''));
        if ((string) ($sent['delivery_type'] ?? '') === ReturnBodyBuilder::DELIVERY_OFFICE) {
            return $place !== '' ? (string) __('Office') . ': ' . $place : (string) __('Office');
        }
        if ($place !== '') {
            return $place;
        }

        return (string) __('The default address in your Pigeon Express account');
    }

    /**
     * Cancel the return shipment of the order.
     *
     * @return string The reference that was cancelled
     * @throws LocalizedException
     */
    public function cancel(OrderInterface $order): string
    {
        $orderId = (int) $order->getEntityId();
        if (!$this->lock->acquire($orderId, self::LOCK_SCOPE)) {
            throw new LocalizedException(__(
                'The return shipment of this order is being created or cancelled right now. Reload the order in a moment.'
            ));
        }

        try {
            $row = $this->repository->findActive($orderId);
            if ($row === null) {
                throw new LocalizedException(__('This order has no return shipment.'));
            }
            $reference = (string) $row['reference_number'];
            $storeId = (int) $order->getStoreId();
            $already = false;

            try {
                $this->client->cancel($reference, $storeId);
            } catch (LocalizedException $e) {
                // Voided elsewhere already: ask what the shipment is before
                // believing it, or the order could never drop it.
                if (!$this->isCancelledAtCarrier($reference, $storeId)) {
                    throw $e;
                }
                $already = true;
            }

            $this->repository->update((int) $row['entity_id'], [
                'status'      => ReturnShipmentRepository::STATUS_CANCELLED,
                'status_code' => StatusCodes::CANCELLED,
            ]);
            $this->comments->add($order, $already
                ? (string) __('Pigeon Express return shipment %1 had already been cancelled at the carrier, so it has been cleared from this order.', $reference)
                : (string) __('Pigeon Express return shipment %1 was cancelled.', $reference));

            return $reference;
        } finally {
            $this->lock->release($orderId, self::LOCK_SCOPE);
        }
    }

    private function isCancelledAtCarrier(string $reference, int $storeId): bool
    {
        try {
            $status = $this->client->get($reference, $storeId)->getStatus();

            return is_object($status) && method_exists($status, 'getCode')
                && (string) $status->getCode() === StatusCodes::CANCELLED;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Read the return shipment from Pigeon Express and keep its status and a
     * trimmed copy. Only on the merchant's click.
     *
     * @return array<string, mixed> The trimmed details
     * @throws LocalizedException
     */
    public function refresh(OrderInterface $order): array
    {
        $row = $this->repository->findActive((int) $order->getEntityId());
        if ($row === null) {
            throw new LocalizedException(__('This order has no return shipment.'));
        }

        $details = $this->client->get((string) $row['reference_number'], (int) $order->getStoreId())->toArray();
        $trimmed = $this->detailsStore->trim($details, time());
        $data = [
            'details_json'      => $trimmed,
            'status_checked_at' => gmdate('Y-m-d H:i:s'),
        ];
        if ($trimmed['status']['code'] !== '') {
            $data['status_code'] = $trimmed['status']['code'];
            $data['status_name'] = $trimmed['status']['name'];
        }
        $this->repository->update((int) $row['entity_id'], $data);

        return $trimmed;
    }
}
