<?php
/**
 * Address of the carrier's public tracking page for a waybill.
 *
 * The same page WooCommerce links to (Settings::tracking_url()), so a customer
 * of either shop lands in the same place.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Magento;

class TrackingUrl
{
    public const BASE = 'https://track.pigeonexpress.com/?tracking_number=';

    public function forNumber(string $number): string
    {
        return self::BASE . rawurlencode(trim($number));
    }
}
