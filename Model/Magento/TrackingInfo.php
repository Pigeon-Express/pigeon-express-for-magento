<?php
/**
 * What Magento's tracking popup shows for a Pigeon Express tracking number:
 * the carrier, the status last read from Pigeon Express with its time, and a
 * link to the carrier's public tracking page. Used by the carrier's
 * getTrackingInfo(), so the customer's "Track your order" popup, the one
 * opened from the shipment email and the admin's all show the same.
 *
 * The status is the stored one (refreshed in the background), not read live:
 * the popup must open fast and must not depend on the carrier answering. With
 * the background check off the stored status goes stale, and a stale status
 * told to a customer is worse than none, so then only the link is shown, as in
 * WooCommerce.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Magento;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Shipping\Model\Tracking\Result\Status;
use Magento\Shipping\Model\Tracking\Result\StatusFactory;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\ShipmentCanceller;
use PigeonExpress\Shipping\Model\Status\StatusConfig;
use PigeonExpress\Shipping\Model\Status\StatusNames;

class TrackingInfo
{
    /** Data key holding the tracking page address on the Status. */
    public const URL_KEY = 'pigeonexpress_url';

    /** @var StatusFactory */
    private $statusFactory;

    /** @var ResourceConnection */
    private $resource;

    /** @var StatusConfig */
    private $statusConfig;

    /** @var StatusNames */
    private $statusNames;

    /** @var TrackingUrl */
    private $trackingUrl;

    /** @var TimezoneInterface */
    private $timezone;

    public function __construct(
        StatusFactory $statusFactory,
        ResourceConnection $resource,
        StatusConfig $statusConfig,
        StatusNames $statusNames,
        TrackingUrl $trackingUrl,
        TimezoneInterface $timezone
    ) {
        $this->statusFactory = $statusFactory;
        $this->resource = $resource;
        $this->statusConfig = $statusConfig;
        $this->statusNames = $statusNames;
        $this->trackingUrl = $trackingUrl;
        $this->timezone = $timezone;
    }

    public function forNumber(string $number, string $carrierTitle = NativeShipment::TRACK_TITLE): Status
    {
        $number = trim($number);
        $url = $this->trackingUrl->forNumber($number);

        /** @var Status $status */
        $status = $this->statusFactory->create();
        $status->setCarrier(ConfigInterface::CARRIER_CODE);
        $status->setCarrierTitle($carrierTitle !== '' ? $carrierTitle : NativeShipment::TRACK_TITLE);
        $status->setTracking($number);
        $status->setData(self::URL_KEY, $url);

        $text = $this->statusText($this->findRow($number));
        if ($text !== '') {
            // Magento's popup shows either a link or the fields, never both;
            // the link is then added under the table (shipping_tracking_popup.xml).
            $status->setStatus($text);
        } else {
            $status->setUrl($url);
        }

        return $status;
    }

    /**
     * "Delivered (16 Nov 2026, 14:05)", or '' when there is nothing to tell.
     *
     * @param array<string, mixed>|null $row
     */
    public function statusText(?array $row): string
    {
        if ($row === null) {
            return '';
        }

        if ((string) ($row['status'] ?? '') === ShipmentCanceller::STATUS_CANCELLED) {
            return (string) __('Cancelled');
        }

        $code = (string) ($row['status_code'] ?? '');
        if ($code === '' || !$this->statusConfig->isStatusSyncEnabled()) {
            return '';
        }

        $label = $this->statusNames->label($code, (string) ($row['status_name'] ?? ''));
        $at = (string) ($row['status_at'] ?? '');
        $date = $at !== '' ? $this->formatUtc($at) : '';

        return $date !== '' ? $label . ' (' . $date . ')' : $label;
    }

    /**
     * The newest waybill row with this number.
     *
     * @return array<string, mixed>|null
     */
    private function findRow(string $number): ?array
    {
        if ($number === '') {
            return null;
        }

        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->resource->getTableName(ShipmentLink::TABLE))
                ->where('reference_number = ?', $number)
                ->order('entity_id DESC')
                ->limit(1)
        );

        return is_array($row) && $row !== [] ? $row : null;
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
            return '';
        }
    }
}
