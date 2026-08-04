<?php
/**
 * Puts the Pigeon Express options back into the order the merchant configured.
 *
 * Magento hands every rate from every carrier to Result::sortRatesByPrice()
 * before anything downstream sees them, so the order a carrier appends its
 * methods in is discarded. Left alone, the three delivery types would arrange
 * themselves by price, and would rearrange as a cart crossed a free shipping
 * threshold and one of them dropped to nothing.
 *
 * This runs after the grouping, so only the Pigeon Express group is touched and
 * the position of the carrier itself among other carriers is left as Magento
 * decided it.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Plugin\Quote;

use Magento\Quote\Model\Quote\Address;
use PigeonExpress\Shipping\Api\ConfigInterface;

class ShippingRateSortPlugin
{
    /** Delivery types this module knows how to place. */
    private const KNOWN_TYPES = [
        ConfigInterface::DELIVERY_TYPE_ADDRESS,
        ConfigInterface::DELIVERY_TYPE_OFFICE,
        ConfigInterface::DELIVERY_TYPE_APS,
    ];

    /** @var ConfigInterface */
    private $config;

    public function __construct(ConfigInterface $config)
    {
        $this->config = $config;
    }

    /**
     * @param Address $subject
     * @param array<string, array<int, \Magento\Quote\Model\Quote\Address\Rate>> $result
     * @return array<string, array<int, \Magento\Quote\Model\Quote\Address\Rate>>
     */
    public function afterGetGroupedAllShippingRates(Address $subject, array $result): array
    {
        $code = ConfigInterface::CARRIER_CODE;

        if (!isset($result[$code]) || count($result[$code]) < 2) {
            return $result;
        }

        $group = $result[$code];
        $storeId = $this->storeIdOf($subject);

        // Magento stamps the carrier's own position on the first rate of the
        // group and sorts the groups by it. That has already happened by now,
        // but the stamp is carried over so a third party sorting the groups
        // again does not find it missing.
        $carrierPosition = $group[0]->carrier_sort_order ?? null;

        usort(
            $group,
            function ($first, $second) use ($storeId): int {
                return $this->positionOf($first, $storeId) <=> $this->positionOf($second, $storeId);
            }
        );

        if ($carrierPosition !== null) {
            $group[0]->carrier_sort_order = $carrierPosition;
        }

        $result[$code] = $group;

        return $result;
    }

    /**
     * Where a rate sits among the carrier's options.
     *
     * A method this module does not recognise, which is what a rate stored by an
     * older version looks like, goes to the end rather than throwing.
     *
     * @param \Magento\Quote\Model\Quote\Address\Rate $rate
     */
    private function positionOf($rate, ?int $storeId): int
    {
        $method = (string) $rate->getMethod();

        if (!in_array($method, self::KNOWN_TYPES, true)) {
            return PHP_INT_MAX;
        }

        return $this->config->getDeliveryTypeSortOrder($method, $storeId);
    }

    /**
     * Null means the current store, which is the right answer everywhere except
     * an admin order, and is a better one than failing to render the checkout.
     */
    private function storeIdOf(Address $address): ?int
    {
        try {
            $quote = $address->getQuote();
            $storeId = $quote ? $quote->getStoreId() : null;

            return $storeId !== null ? (int) $storeId : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
