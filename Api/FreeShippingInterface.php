<?php
/**
 * Decides whether a delivery type is carried for nothing.
 *
 * Two independent reasons make a delivery free: a Magento cart price rule with
 * the Free Shipping action, which the carrier receives on the rate request, and
 * a per delivery type subtotal threshold configured in the admin. Either is
 * enough.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Api;

use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Sales\Api\Data\OrderInterface;

interface FreeShippingInterface
{
    /**
     * Whether this delivery type should be quoted at nothing for this cart.
     *
     * This is the only place the threshold is evaluated while rates are being
     * collected. Anything downstream that needs to know reads the price the
     * carrier produced, rather than asking the question a second time against a
     * cart that has moved on.
     *
     * @param RateRequest $request
     * @param string $deliveryType One of address, office, aps
     * @param int $storeId
     * @return bool
     */
    public function appliesToRequest(RateRequest $request, string $deliveryType, int $storeId): bool;

    /**
     * Whether free shipping is what made a placed order's delivery cost nothing.
     *
     * Read once, as the order is created, to record why the customer was not
     * charged. A zero shipping amount on its own does not answer this: a store
     * on receiver pays deliberately quotes zero and still expects the courier to
     * collect from the recipient.
     *
     * @param Address $address The quote shipping address, after totals
     * @return bool
     */
    public function wasAppliedToQuoteAddress(Address $address): bool;

    /**
     * What was recorded for a placed order when it was created.
     *
     * Read rather than recalculated, so a threshold the merchant changes after
     * the fact cannot rewrite who was billed for an order already taken.
     *
     * @param OrderInterface $order
     * @return bool
     */
    public function wasAppliedToOrder(OrderInterface $order): bool;
}
