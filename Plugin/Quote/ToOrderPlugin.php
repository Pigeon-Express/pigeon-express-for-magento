<?php
declare(strict_types=1);

namespace PigeonExpress\Shipping\Plugin\Quote;

use Magento\Quote\Model\Quote\Address\ToOrder;
use Magento\Quote\Model\Quote\Address as QuoteAddress;
use Magento\Sales\Api\Data\OrderInterface;
use PigeonExpress\Shipping\Model\Total\Quote\CodFee;

class ToOrderPlugin
{
    /**
     * After converting quote address to order, copy COD fee if present.
     */
    public function afterConvert(ToOrder $subject, OrderInterface $result, QuoteAddress $address): OrderInterface
    {
        $fee = $address->getData(CodFee::CODE);
        if ($fee !== null && (float) $fee > 0) {
            $result->setData(CodFee::CODE, round((float) $fee, 4));
        }
        return $result;
    }
}
