<?php
declare(strict_types=1);

namespace PigeonExpress\Shipping\Model;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use Magento\Store\Model\StoreManagerInterface;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\Config\Source\CheckoutLogo;
use PigeonExpress\Shipping\Model\Total\Quote\CodFee;

class CheckoutConfigProvider implements ConfigProviderInterface
{
    /** @var ConfigInterface */
    private $config;

    /** @var StoreManagerInterface */
    private $storeManager;

    /** @var AssetRepository */
    private $assetRepository;

    /** @var CheckoutLogo */
    private $logos;

    public function __construct(
        ConfigInterface $config,
        StoreManagerInterface $storeManager,
        AssetRepository $assetRepository,
        CheckoutLogo $logos
    ) {
        $this->config = $config;
        $this->storeManager = $storeManager;
        $this->assetRepository = $assetRepository;
        $this->logos = $logos;
    }

    public function getConfig(): array
    {
        $storeId = (int) $this->storeManager->getStore()->getId();
        $logo = $this->config->getCheckoutLogo($storeId);

        return [
            'pigeonexpress' => [
                'cod_payment_methods' => $this->config->getCodPaymentMethods($storeId),
                // No fee rate is published here on purpose. The COD fee is whatever
                // the API charges, and reaches the checkout as a total segment.
                'logo_url' => $this->logoUrl($logo),
                'logo_needs_dark_backdrop' => $this->logos->needsDarkBackdrop($logo),
                'override_address_fields' => $this->config->isOverrideAddressFields($storeId),
            ],
        ];
    }

    /**
     * The chosen logo as a URL, or an empty string when none is chosen.
     *
     * Resolved here rather than in the template so the URL carries the static
     * content version. Static files are served with a one year expiry and sit
     * behind a CDN, so replacing the artwork at the same URL would leave the old
     * one pinned for a year, while a versioned URL is picked up at once.
     */
    private function logoUrl(string $code): string
    {
        $file = $this->logos->fileFor($code);
        if ($file === '') {
            return '';
        }

        try {
            return $this->assetRepository->getUrlWithParams(
                'PigeonExpress_Shipping::images/' . $file,
                ['_secure' => true]
            );
        } catch (\Throwable $e) {
            // A missing asset is not worth breaking a checkout over.
            return '';
        }
    }
}
