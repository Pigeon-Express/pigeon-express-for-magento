<?php
/**
 * Log when Pigeon Express carrier is (or is not) invoked during rate collection.
 * Helps debug: carrier enabled in admin but not showing in checkout.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Plugin\Shipping;

use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Shipping\Model\Shipping;
use PigeonExpress\Shipping\Api\ConfigInterface;
use Psr\Log\LoggerInterface;

class ShippingLogPlugin
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var ConfigInterface
     */
    private $config;

    public function __construct(LoggerInterface $logger, ConfigInterface $config)
    {
        $this->logger = $logger;
        $this->config = $config;
    }

    /**
     * Log list of carrier codes from config (when logging enabled) to verify pigeonexpress is in the list.
     *
     * @param Shipping $subject
     * @param RateRequest $request
     * @return array
     */
    public function beforeCollectRates(Shipping $subject, RateRequest $request): array
    {
        $storeId = $request->getStoreId();
        if ($storeId === null) {
            $storeId = 0;
        }
        if (!$this->config->isLoggingEnabled($storeId)) {
            return [$request];
        }
        $carriers = $subject->getConfig()->getActiveCarriers($storeId);
        $codes = array_keys($carriers);
        $this->logger->info('[PigeonExpress] Shipping::collectRates – active carriers list', [
            'storeId' => $storeId,
            'carrier_codes' => $codes,
            'pigeonexpress_in_list' => in_array(ConfigInterface::CARRIER_CODE, $codes, true),
        ]);
        return [$request];
    }

    /**
     * Log when pigeonexpress collectCarrierRates is called and if it throws.
     *
     * @param Shipping $subject
     * @param callable $proceed
     * @param string $carrierCode
     * @param mixed $request
     * @return Shipping
     */
    public function aroundCollectCarrierRates(Shipping $subject, callable $proceed, $carrierCode, $request)
    {
        if ($carrierCode !== ConfigInterface::CARRIER_CODE) {
            return $proceed($carrierCode, $request);
        }
        $storeId = $request instanceof RateRequest ? $request->getStoreId() : null;
        if ($storeId === null) {
            $storeId = 0;
        }
        if ($this->config->isLoggingEnabled($storeId)) {
            $this->logger->info('[PigeonExpress] collectCarrierRates started for pigeonexpress', [
                'storeId' => $storeId,
            ]);
        }
        try {
            $result = $proceed($carrierCode, $request);
            if ($this->config->isLoggingEnabled($storeId)) {
                $this->logger->info('[PigeonExpress] collectCarrierRates completed for pigeonexpress');
            }
            return $result;
        } catch (\Throwable $e) {
            if ($this->config->isLoggingEnabled($storeId)) {
                $this->logger->warning('[PigeonExpress] collectCarrierRates failed for pigeonexpress', [
                    'message' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
            throw $e;
        }
    }
}
