<?php
declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Payment\Model\Config as PaymentConfig;

class PaymentMethods implements OptionSourceInterface
{
    /** @var PaymentConfig */
    private $paymentConfig;

    public function __construct(PaymentConfig $paymentConfig)
    {
        $this->paymentConfig = $paymentConfig;
    }

    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->paymentConfig->getActiveMethods() as $code => $method) {
            $options[] = [
                'value' => $code,
                'label' => $method->getTitle() ?: $code,
            ];
        }
        return $options;
    }
}
