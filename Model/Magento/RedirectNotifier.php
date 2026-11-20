<?php
/**
 * Emails the customer the new tracking number after Pigeon Express redirected
 * the shipment of their order (a new waybill), once per new shipment.
 *
 * Copied from the WooCommerce plugin (QuickActions::move_to_redirect(), the
 * customer note). Always sent, whatever the store's "Order Comment" email
 * setting says (merchant decision; see RedirectNoticeIdentity), and never
 * twice: the new shipment row is claimed first in one conditional update
 * (ResourceModel\Shipment::claimCustomerNotification()), so of a quick change,
 * a replayed answer, Refresh and the status cron only one sends. A send that
 * fails gives the claim back, so Refresh can try again (retryPending()), and
 * the visible comment is written only once the email went, so a retry never
 * leaves a second one.
 *
 * Magento's own order comment email does the sending, so the store's sender,
 * template and copies apply, and it works from the admin and from cron alike
 * (it emulates the storefront itself). The text it carries is a finished
 * string, so it is worded here in the language of the order's store, not in
 * the admin's or the cron's; the private comments stay in the admin's.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Magento;

use Magento\Framework\App\Area;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderCommentSender;
use Magento\Sales\Model\Order\Email\Sender\OrderCommentSenderFactory;
use Magento\Store\Model\App\Emulation;
use PigeonExpress\Shipping\Model\ResourceModel\Shipment as ShipmentResource;
use PigeonExpress\Shipping\Model\Shipment;
use Psr\Log\LoggerInterface;

class RedirectNotifier
{
    /** @var ShipmentResource */
    private $resource;

    /** @var OrderComment */
    private $comments;

    /** @var TrackingUrl */
    private $trackingUrl;

    /** @var OrderCommentSenderFactory */
    private $senderFactory;

    /** @var RedirectNoticeIdentity */
    private $identity;

    /** @var LoggerInterface */
    private $logger;

    /** @var Emulation */
    private $emulation;

    /** @var OrderCommentSender|null */
    private $sender;

    public function __construct(
        ShipmentResource $resource,
        OrderComment $comments,
        TrackingUrl $trackingUrl,
        OrderCommentSenderFactory $senderFactory,
        RedirectNoticeIdentity $identity,
        LoggerInterface $logger,
        Emulation $emulation
    ) {
        $this->resource = $resource;
        $this->comments = $comments;
        $this->trackingUrl = $trackingUrl;
        $this->senderFactory = $senderFactory;
        $this->identity = $identity;
        $this->logger = $logger;
        $this->emulation = $emulation;
    }

    /**
     * Email the customer the number of this shipment, which replaced the one
     * they were given, unless somebody already did.
     *
     * @param Shipment $row The new shipment's row (redirected_from set)
     * @return bool Whether the email was sent now
     */
    public function notify(OrderInterface $order, Shipment $row): bool
    {
        $rowId = (int) $row->getId();
        $number = trim((string) $row->getReferenceNumber());
        if ($rowId <= 0 || $number === '' || !$order instanceof Order) {
            return false;
        }

        if (!$this->resource->claimCustomerNotification($rowId)) {
            return false;
        }

        $text = '';
        try {
            $text = $this->customerText($order, $number);
            $sent = (bool) $this->sender()->send($order, true, $text);
        } catch (\Throwable $e) {
            $sent = false;
            $this->logger->error('[PigeonExpress] Redirect email not sent: ' . $e->getMessage(), [
                'order_id' => $order->getEntityId(),
                'reference_number' => $number,
            ]);
        }

        if (!$sent) {
            // Not sent: the next Refresh may try again.
            $this->resource->releaseCustomerNotification($rowId);
            $row->setCustomerNotifiedAt(null);
            $this->comments->add($order, (string) __(
                'The customer could not be emailed the new Pigeon Express number %1. Use "Refresh status" to try '
                . 'again, or tell the customer yourself.',
                $number
            ));

            return false;
        }

        $row->setCustomerNotifiedAt(gmdate('Y-m-d H:i:s'));
        // In the customer's account too, marked as the email it was.
        $this->comments->addForCustomer($order, $text, true);

        return true;
    }

    /**
     * Send the email a redirect owes the customer when an earlier attempt
     * failed (the row moved here, nobody was emailed).
     *
     * @return bool Whether the email was sent now
     */
    public function retryPending(OrderInterface $order, Shipment $row): bool
    {
        if ($row->getRedirectedFrom() === null || $row->getCustomerNotifiedAt() !== null) {
            return false;
        }

        return $this->notify($order, $row);
    }

    /**
     * The customer's comment and email text.
     */
    public function text(string $number): string
    {
        return (string) __(
            'The delivery of your order was changed, and your Pigeon Express shipment has a new number: %1. '
            . 'You can track it here: %2',
            $number,
            $this->trackingUrl->forNumber($number)
        );
    }

    /**
     * text() in the language of the order's store. The emulation ends before
     * the email is sent: the sender emulates the store itself, and Magento
     * runs only one emulation at a time.
     */
    private function customerText(Order $order, string $number): string
    {
        $storeId = (int) $order->getStoreId();
        if ($storeId <= 0) {
            return $this->text($number);
        }

        try {
            $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);

            return $this->text($number);
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }
    }

    private function sender(): OrderCommentSender
    {
        if ($this->sender === null) {
            $this->sender = $this->senderFactory->create(['identityContainer' => $this->identity]);
        }

        return $this->sender;
    }
}
