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
        $codAmount = round((float) $quote->getSubtotal(), 2);
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
     * No total segment is published.
     *
     * The fee is merged into the shipping amount rather than shown as its own
     * checkout row, so nothing consumes a segment. Publishing one anyway risks
     * a third party theme rendering an unexpected total line, and a separate row
     * would additionally need handling in invoices and credit memos, which the
     * API gives no way to control independently.
     *
     * The amount is still recorded on the address, and from there on the order,
     * so it remains visible to the merchant and reportable.
     */
    public function fetch(Quote $quote, Total $total): array
    {
        return [];
    }
}
