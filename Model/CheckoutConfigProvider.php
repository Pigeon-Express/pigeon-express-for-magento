<?php
declare(strict_types=1);

namespace PigeonExpress\Shipping\Model;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Store\Model\StoreManagerInterface;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\Total\Quote\CodFee;

class CheckoutConfigProvider implements ConfigProviderInterface
{
    /** @var ConfigInterface */
    private $config;

    /** @var StoreManagerInterface */
    private $storeManager;

    public function __construct(ConfigInterface $config, StoreManagerInterface $storeManager)
    {
        $this->config = $config;
        $this->storeManager = $storeManager;
    }

    public function getConfig(): array
    {
        $storeId = (int) $this->storeManager->getStore()->getId();
        return [
            'pigeonexpress' => [
                'cod_payment_methods' => $this->config->getCodPaymentMethods($storeId),
                'cod_fee_percent'     => CodFee::FEE_PERCENT * 100,
            ],
        ];
    }
}
