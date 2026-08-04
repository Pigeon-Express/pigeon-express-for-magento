<?php
/**
 * Whether a delivery type is carried for nothing.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Rate;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Model\ScopeInterface;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Api\FreeShippingInterface;
use PigeonExpress\Shipping\Model\OrderAddressLocationPersistor;

class FreeShipping implements FreeShippingInterface
{
    /** Magento's own switch for whether catalogue prices already carry tax. */
    private const XML_PATH_PRICE_INCLUDES_TAX = 'tax/calculation/price_includes_tax';

    /** @var ConfigInterface */
    private $config;

    /** @var ScopeConfigInterface */
    private $scopeConfig;

    /** @var OrderAddressLocationPersistor */
    private $orderAddresses;

    public function __construct(
        ConfigInterface $config,
        ScopeConfigInterface $scopeConfig,
        OrderAddressLocationPersistor $orderAddresses
    ) {
        $this->config = $config;
        $this->scopeConfig = $scopeConfig;
        $this->orderAddresses = $orderAddresses;
    }

    /**
     * @inheritdoc
     */
    public function appliesToRequest(RateRequest $request, string $deliveryType, int $storeId): bool
    {
        // A cart price rule outranks the threshold, and is answered first because
        // it needs no configuration to be true. The flag is an int on the quote
        // path and absent altogether when rates are collected from an address,
        // which is how the admin creates an order, so it is read loosely.
        if ((bool) $request->getFreeShipping()) {
            return true;
        }

        return $this->meetsThreshold(
            $deliveryType,
            (float) $request->getPackageValueWithDiscount(),
            $storeId
        );
    }

    /**
     * @inheritdoc
     */
    public function wasAppliedToQuoteAddress(Address $address): bool
    {
        $deliveryType = $this->deliveryTypeOf((string) $address->getShippingMethod());
        if ($deliveryType === null) {
            return false;
        }

        // Nothing was charged is a necessary condition, never a sufficient one.
        // Without it a store on receiver pays, which quotes zero on purpose and
        // lets the courier collect at the door, would be recorded as having
        // given the delivery away and would start billing itself for every
        // waybill.
        if ((float) $address->getBaseShippingAmount() > 0.0) {
            return false;
        }

        if ((bool) $address->getFreeShipping()) {
            return true;
        }

        try {
            $quote = $address->getQuote();
            $storeId = $quote ? (int) $quote->getStoreId() : 0;
        } catch (\Throwable $e) {
            $storeId = 0;
        }

        return $this->meetsThreshold($deliveryType, $this->subtotalWithDiscount($address, $storeId), $storeId);
    }

    /**
     * @inheritdoc
     */
    public function wasAppliedToOrder(OrderInterface $order): bool
    {
        // A virtual order has no shipping address, and Magento says so by
        // returning false rather than null, so this is checked loosely.
        $address = $order->getShippingAddress();
        if (!$address || !$address->getId()) {
            return false;
        }

        $data = $this->orderAddresses->getByAddressId((int) $address->getId());

        return !empty($data['free_shipping']);
    }

    /**
     * The configured threshold, met or exceeded.
     *
     * A threshold of zero is treated as no threshold rather than as free for
     * everyone, so an empty field cannot give the shop away.
     */
    private function meetsThreshold(string $deliveryType, float $subtotalWithDiscount, int $storeId): bool
    {
        if (!$this->config->isFreeShippingThresholdEnabled($deliveryType, $storeId)) {
            return false;
        }

        $threshold = $this->config->getFreeShippingThreshold($deliveryType, $storeId);
        if ($threshold <= 0.0) {
            return false;
        }

        return $subtotalWithDiscount >= $threshold;
    }

    /**
     * The figure the rate request would have compared against.
     *
     * Reproduced from Magento\Quote\Model\Quote\Address::requestShippingRates()
     * rather than taken from a ready made total, because the address totals that
     * look equivalent are written by collectors that run after the rates and do
     * not hold the same value at the two moments.
     */
    private function subtotalWithDiscount(Address $address, int $storeId): float
    {
        $includesTax = $this->scopeConfig->isSetFlag(
            self::XML_PATH_PRICE_INCLUDES_TAX,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        $baseSubtotal = $includesTax
            ? (float) $address->getBaseSubtotalTotalInclTax()
            : (float) $address->getBaseSubtotal();

        // The discount amount is stored negative.
        return $baseSubtotal + (float) $address->getBaseDiscountAmount();
    }

    /**
     * `pigeonexpress_office` is office. Anything else is not ours.
     *
     * The suffix is checked against the three known types rather than trusted,
     * because the config getters reject an unrecognised one by throwing, and a
     * stored method code from an older version of the module is not a reason to
     * fail an order.
     */
    private function deliveryTypeOf(string $shippingMethod): ?string
    {
        $prefix = ConfigInterface::CARRIER_CODE . '_';
        if ($shippingMethod === '' || strpos($shippingMethod, $prefix) !== 0) {
            return null;
        }

        $deliveryType = substr($shippingMethod, strlen($prefix));

        $known = [
            ConfigInterface::DELIVERY_TYPE_ADDRESS,
            ConfigInterface::DELIVERY_TYPE_OFFICE,
            ConfigInterface::DELIVERY_TYPE_APS,
        ];

        return in_array($deliveryType, $known, true) ? $deliveryType : null;
    }
}
