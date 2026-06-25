<?php
/**
 * Pigeon Express shipping carrier.
 * Extends Magento AbstractCarrier; supports delivery types Address, Office, APS.
 * Rate collection is placeholder for Stage 2 (no API calls in Stage 1).
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Carrier;

use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Rate\ResultFactory;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\Rate\RateCollector;
use Psr\Log\LoggerInterface;

class PigeonExpress extends AbstractCarrier implements CarrierInterface
{
    /**
     * @var string
     */
    protected $_code = ConfigInterface::CARRIER_CODE;

    /**
     * @var ResultFactory
     */
    private $rateResultFactory;

    /**
     * @var MethodFactory
     */
    private $rateMethodFactory;

    /**
     * @var ConfigInterface
     */
    private $config;

    /**
     * @var RateCollector
     */
    private $rateCollector;

    /**
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param ErrorFactory $rateErrorFactory
     * @param LoggerInterface $logger
     * @param ResultFactory $rateResultFactory
     * @param MethodFactory $rateMethodFactory
     * @param ConfigInterface $config
     * @param RateCollector $rateCollector
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        ErrorFactory $rateErrorFactory,
        LoggerInterface $logger,
        ResultFactory $rateResultFactory,
        MethodFactory $rateMethodFactory,
        ConfigInterface $config,
        RateCollector $rateCollector,
        array $data = []
    ) {
        $this->rateResultFactory = $rateResultFactory;
        $this->rateMethodFactory = $rateMethodFactory;
        $this->config = $config;
        $this->rateCollector = $rateCollector;
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
    }

    /**
     * Collect shipping rates. One method per enabled delivery type (address, office, aps).
     * If pricing mode is fixed → use flat rate from config; else placeholder 0 (no API in Stage 1).
     *
     * @param RateRequest $request
     * @return \Magento\Shipping\Model\Rate\Result|false
     */
    public function collectRates(RateRequest $request)
    {
        $storeId = $request->getStoreId();
        if ($storeId === null) {
            $storeId = $this->getStore();
            if ($storeId === null) {
                $storeId = 0;
            }
        }

        $active = $this->getConfigFlag('active');
        if ($this->config->isLoggingEnabled($storeId)) {
            $this->_logger->info('[PigeonExpress] collectRates called', [
                'carrier' => $this->_code,
                'storeId' => $storeId,
                'active_flag' => $active,
                'config_active_raw' => $this->getConfigData('active'),
            ]);
        }
        if (!$active) {
            if ($this->config->isLoggingEnabled($storeId)) {
                $this->_logger->info('[PigeonExpress] Carrier disabled in config, skipping rates');
            }
            return false;
        }

        $enabledTypes = $this->config->getEnabledDeliveryTypes($storeId);
        if ($this->config->isLoggingEnabled($storeId)) {
            $this->_logger->info('[PigeonExpress] Enabled delivery types from config', [
                'storeId' => $storeId,
                'enabled_types' => $enabledTypes,
                'address_enabled' => $this->config->isDeliveryTypeEnabled(ConfigInterface::DELIVERY_TYPE_ADDRESS, $storeId),
                'office_enabled' => $this->config->isDeliveryTypeEnabled(ConfigInterface::DELIVERY_TYPE_OFFICE, $storeId),
                'aps_enabled' => $this->config->isDeliveryTypeEnabled(ConfigInterface::DELIVERY_TYPE_APS, $storeId),
            ]);
        }

        /** @var \Magento\Shipping\Model\Rate\Result $result */
        $result = $this->rateResultFactory->create();

        foreach ($enabledTypes as $deliveryType) {
            try {
                $title = $this->config->getDeliveryTypeTitle($deliveryType, $storeId);
                $price = $this->getPriceForDeliveryType($request, $deliveryType, $storeId);
            } catch (\Throwable $e) {
                // If dynamic pricing is selected but product dimensions are missing, or any other
                // hard error that should make the method unavailable, skip this delivery type.
                if ($this->config->isLoggingEnabled($storeId)) {
                    $this->_logger->warning('[PigeonExpress] Skipping delivery type due to error', [
                        'deliveryType' => $deliveryType,
                        'storeId' => $storeId,
                        'error' => $e->getMessage(),
                    ]);
                }
                continue;
            }

            $method = $this->rateMethodFactory->create();
            $method->setCarrier($this->_code);
            $method->setCarrierTitle($this->getConfigData('title') ?: 'Pigeon Express');
            $method->setMethod($deliveryType);
            $method->setMethodTitle($title);
            $method->setPrice($price);
            $method->setCost($price);
            $result->append($method);
            if ($this->config->isLoggingEnabled($storeId)) {
                $this->_logger->info('[PigeonExpress] Rate added', [
                    'method' => $deliveryType,
                    'title' => $title,
                    'price' => $price,
                ]);
            }
        }

        if ($this->config->isLoggingEnabled($storeId)) {
            $this->_logger->info('[PigeonExpress] collectRates done', [
                'methods_count' => count($result->getAllRates() ?? []),
            ]);
        }

        return $result;
    }

    /**
     * Delivery price: fixed from config or 0 (placeholder for dynamic API in Stage 2).
     *
     * Falls back to flat rate from admin if API is unavailable.
     *
     * @param string $deliveryType address|office|aps
     * @param int $storeId
     * @return float
     */
    private function getPriceForDeliveryType(RateRequest $request, string $deliveryType, int $storeId): float
    {
        return (float) $this->rateCollector->getRatePrice($request, $deliveryType, $storeId);
    }

    /**
     * Return allowed delivery types as method codes (address, office, aps).
     * Used by Magento to know which methods exist for this carrier.
     * Uses current store from carrier when available (same as getConfigFlag).
     *
     * @return array<string, string> Carrier code => comma-separated method codes
     */
    public function getAllowedMethods(): array
    {
        $storeId = $this->getStore();
        if ($storeId === null) {
            $storeId = 0;
        }
        $enabled = $this->config->getEnabledDeliveryTypes($storeId);
        return [$this->_code => implode(',', $enabled)];
    }
}
