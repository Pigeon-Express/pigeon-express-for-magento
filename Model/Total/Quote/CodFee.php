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

        // Merge fee into shipping — no separate line item in the summary.
        $total->setShippingAmount((float) $total->getShippingAmount() + $fee);
        $total->setBaseShippingAmount((float) $total->getBaseShippingAmount() + $fee);
        $total->setTotalAmount('shipping', (float) ($total->getTotalAmount('shipping') ?? 0) + $fee);
        $total->setBaseTotalAmount('shipping', (float) ($total->getBaseTotalAmount('shipping') ?? 0) + $fee);

        // Keep address value so ToOrderPlugin can pass cod_amount to the PE API.
        $address->setData(self::CODE, $fee);

        return $this;
    }

    /**
     * Expose the API resolved fee to the checkout so the summary can render it
     * without recomputing anything in JavaScript.
     */
    public function fetch(Quote $quote, Total $total): array
    {
        $address = $quote->getShippingAddress();
        $fee = $address ? (float) $address->getData(self::CODE) : 0.0;

        if ($fee <= 0.0) {
            return [];
        }

        return [
            'code' => self::CODE,
            'title' => __('Cash on Delivery Fee'),
            'value' => $fee,
        ];
    }
}
