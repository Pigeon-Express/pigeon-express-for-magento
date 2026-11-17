<?php
declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Total\Quote;

use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\Total;
use Magento\Quote\Model\Quote\Address\Total\AbstractTotal;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\Service\CodFeeResolver;

class CodFee extends AbstractTotal
{
    public const CODE = 'pigeonexpress_cod_fee';

    /** @var ConfigInterface */
    private $config;

    /** @var CodFeeResolver */
    private $codFeeResolver;

    public function __construct(ConfigInterface $config, CodFeeResolver $codFeeResolver)
    {
        $this->config = $config;
        $this->codFeeResolver = $codFeeResolver;
        $this->setCode(self::CODE);
    }

    public function collect(Quote $quote, ShippingAssignmentInterface $shippingAssignment, Total $total): self
    {
        parent::collect($quote, $shippingAssignment, $total);

        $address = $shippingAssignment->getShipping()->getAddress();
        $address->setData(self::CODE, 0);

        $shippingMethod = $address->getShippingMethod();
        if (!$shippingMethod || strpos($shippingMethod, ConfigInterface::CARRIER_CODE . '_') !== 0) {
            return $this;
        }

        if (!$this->isChargedFor((string) $shippingMethod, (int) $quote->getStoreId())) {
            return $this;
        }

        // Nothing charged for carriage, nothing charged for collecting the cash.
        // The shipping collector wrote the chosen rate's price here before this
        // one ran, so this reads what the customer is actually being asked for,
        // whether it fell to zero from a free shipping threshold, a cart price
        // rule, or a flat rate the merchant deliberately set to nothing.
        if ((float) $total->getBaseShippingAmount() <= 0.0) {
            return $this;
        }

        $storeId = (int) $quote->getStoreId();
        $codMethods = $this->config->getCodPaymentMethods($storeId);
        if (empty($codMethods)) {
            return $this;
        }

        try {
            $paymentMethod = (string) $quote->getPayment()->getMethod();
        } catch (\Throwable $e) {
            return $this;
        }

        if ($paymentMethod === '' || !in_array($paymentMethod, $codMethods, true)) {
            return $this;
        }

        // The fee comes from the API, never from a rate stored in this module.
        // On the goods after discounts, as the courier will collect them.
        $codAmount = $this->codFeeResolver->basisFor(
            $quote,
            $total->getBaseSubtotalWithDiscount() !== null ? (float) $total->getBaseSubtotalWithDiscount() : null
        );
        $fee = $this->codFeeResolver->resolve($storeId, $codAmount);

        if ($fee <= 0.0) {
            return $this;
        }

        // Merge the fee into shipping rather than showing a separate total row.
        $total->setShippingAmount((float) $total->getShippingAmount() + $fee);
        $total->setBaseShippingAmount((float) $total->getBaseShippingAmount() + $fee);
        $total->setTotalAmount('shipping', (float) ($total->getTotalAmount('shipping') ?? 0) + $fee);
        $total->setBaseTotalAmount('shipping', (float) ($total->getBaseTotalAmount('shipping') ?? 0) + $fee);

        // This collector runs after tax, so the tax pass has already derived
        // shipping_incl_tax from the pre fee shipping amount. Without the two
        // lines below every tax inclusive display of shipping, in checkout, in
        // the admin order view and in emails, would understate what the customer
        // is actually charged. The fee is added at face value, so it is not
        // itself taxed.
        $this->addToInclTax($total, $address, $fee);

        // Keep address value so ToOrderPlugin can pass cod_amount to the PE API.
        $address->setData(self::CODE, $fee);

        return $this;
    }

    /**
     * Keep the tax inclusive shipping figures in step with the fee.
     *
     * Written on both the total and the address, because different consumers
     * read from different objects: checkout totals come from the Total, while
     * order conversion copies from the address.
     */
    private function addToInclTax(Total $total, $address, float $fee): void
    {
        $inclTax = $total->getShippingInclTax();
        if ($inclTax !== null) {
            $total->setShippingInclTax((float) $inclTax + $fee);
        }

        $baseInclTax = $total->getBaseShippingInclTax();
        if ($baseInclTax !== null) {
            $total->setBaseShippingInclTax((float) $baseInclTax + $fee);
        }

        $addressInclTax = $address->getShippingInclTax();
        if ($addressInclTax !== null) {
            $address->setShippingInclTax((float) $addressInclTax + $fee);
        }

        $addressBaseInclTax = $address->getBaseShippingInclTax();
        if ($addressBaseInclTax !== null) {
            $address->setBaseShippingInclTax((float) $addressBaseInclTax + $fee);
        }
    }

    /**
     * No total segment is published unless Show COD Fee is on.
     *
     * The fee is merged into the shipping amount rather than shown as its own
     * checkout row. A separate row would need handling in invoices and credit
     * memos, which the API gives no way to control independently.
     *
     * With Show COD Fee on, the segment carries the fee the cart would pay so
     * the payment step can print it beside each COD method. It is not a row of
     * the order summary: Magento renders only the segments its layout names, so
     * this one is read by our payment title mixin and drawn nowhere else. It is
     * kept off by default because a third party theme that renders every
     * segment would show it as a line of its own.
     *
     * The fee is read back from the resolver's cache, which the rate collector
     * filled from the API, rather than from the collected total. The totals
     * request the payment step makes after a payment method is chosen loads the
     * quote without collecting, so nothing computed in collect() reaches it.
     */
    public function fetch(Quote $quote, Total $total): array
    {
        $storeId = (int) $quote->getStoreId();
        if (!$this->config->isShowCodFee($storeId) || empty($this->config->getCodPaymentMethods($storeId))) {
            return [];
        }

        // Same conditions as collect(): our carrier, and a delivery that is
        // actually charged, the fee being waived with free shipping.
        $shippingMethod = (string) $total->getData('shipping_method');
        if (strpos($shippingMethod, ConfigInterface::CARRIER_CODE . '_') !== 0
            || (float) $total->getData('base_shipping_amount') <= 0.0
            || !$this->isChargedFor($shippingMethod, $storeId)
        ) {
            return [];
        }

        $fee = $this->codFeeResolver->resolve($storeId, $this->codFeeResolver->basisFor($quote));
        if ($fee <= 0.0) {
            return [];
        }

        return [
            'code' => self::CODE,
            'title' => __('Cash on Delivery Fee'),
            'value' => $fee,
        ];
    }

    /**
     * Whether this delivery type passes the COD fee on to the customer. A flat
     * rate the merchant set up as all inclusive does not.
     */
    private function isChargedFor(string $shippingMethod, int $storeId): bool
    {
        $deliveryType = substr($shippingMethod, strlen(ConfigInterface::CARRIER_CODE . '_'));

        try {
            return $this->config->isCodFeeCharged($deliveryType, $storeId);
        } catch (\Throwable $e) {
            return true;
        }
    }
}
