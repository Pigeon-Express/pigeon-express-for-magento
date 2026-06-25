<?php
declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Total\Quote;

use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\Total;
use Magento\Quote\Model\Quote\Address\Total\AbstractTotal;
use PigeonExpress\Shipping\Api\ConfigInterface;

class CodFee extends AbstractTotal
{
    public const CODE = 'pigeonexpress_cod_fee';
    public const FEE_PERCENT = 0.025;
    public const MIN_FEE = 0.80;

    /** @var ConfigInterface */
    private $config;

    public function __construct(ConfigInterface $config)
    {
        $this->config = $config;
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

        $fee = max(round($quote->getSubtotal() * self::FEE_PERCENT, 2), self::MIN_FEE);

        // Merge fee into shipping — no separate line item in the summary.
        $total->setShippingAmount((float) $total->getShippingAmount() + $fee);
        $total->setBaseShippingAmount((float) $total->getBaseShippingAmount() + $fee);
        $total->setTotalAmount('shipping', (float) ($total->getTotalAmount('shipping') ?? 0) + $fee);
        $total->setBaseTotalAmount('shipping', (float) ($total->getBaseTotalAmount('shipping') ?? 0) + $fee);

        // Keep address value so ToOrderPlugin can pass cod_amount to the PE API.
        $address->setData(self::CODE, $fee);

        return $this;
    }

    public function fetch(Quote $quote, Total $total): array
    {
        return [];
    }
}
