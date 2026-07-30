<?php
/**
 * Resolves the cash on delivery fee from the Pigeon Express API.
 *
 * The API does not publish a COD percentage anywhere. GET /additional-services
 * returns definitions without prices, so the only authoritative figure is what
 * POST /shipments/calculate actually charges. RateCollector performs that
 * comparison while it is already talking to the API and hands the result here;
 * the quote total collector then reads it back.
 *
 * There is deliberately no hardcoded percentage or minimum in this class. When
 * no figure can be obtained the fee resolves to zero and the reason is logged,
 * so a stale guess can never be charged to a customer.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Service;

use Magento\Framework\App\CacheInterface;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\AdditionalService;
use PigeonExpress\Shipping\Model\AdditionalServiceRepository;
use Psr\Log\LoggerInterface;

class CodFeeResolver
{
    private const CACHE_PREFIX = 'pigeonexpress_cod_fee_';

    /** Short lived: the fee only has to survive from rate collection to totals. */
    private const CACHE_LIFETIME = 900;

    /** @var AdditionalServiceRepository */
    private $serviceRepository;

    /** @var CacheInterface */
    private $cache;

    /** @var ConfigInterface */
    private $config;

    /** @var LoggerInterface */
    private $logger;

    /** @var array<string, float> */
    private $runtimeCache = [];

    public function __construct(
        AdditionalServiceRepository $serviceRepository,
        CacheInterface $cache,
        ConfigInterface $config,
        LoggerInterface $logger
    ) {
        $this->serviceRepository = $serviceRepository;
        $this->cache = $cache;
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Store a fee the API has just charged, and persist the ratio so it can be
     * reused if the API is unreachable on a later request.
     */
    public function rememberFee(int $storeId, float $codAmount, float $fee): void
    {
        if ($codAmount <= 0.0 || $fee < 0.0) {
            return;
        }

        $key = $this->cacheKey($storeId, $codAmount);
        $this->runtimeCache[$key] = $fee;

        try {
            $this->cache->save((string) $fee, $key, [], self::CACHE_LIFETIME);
        } catch (\Throwable $e) {
            $this->logger->warning('[PigeonExpress] Failed to cache COD fee: ' . $e->getMessage());
        }

        $this->serviceRepository->recordFee(AdditionalService::CODE_COD_AMOUNT, $fee, $codAmount);

        if ($this->config->isLoggingEnabled($storeId)) {
            $this->logger->info('[PigeonExpress] COD fee resolved from API', [
                'storeId' => $storeId,
                'cod_amount' => $codAmount,
                'fee' => $fee,
            ]);
        }
    }

    /**
     * Resolve the COD fee for the given amount.
     *
     * Order of preference:
     *   1. the figure the API returned during this request's rate collection,
     *   2. the ratio of the last API resolved fee stored in the database,
     *   3. zero, with a warning, because no fee may be invented locally.
     */
    public function resolve(int $storeId, float $codAmount): float
    {
        if ($codAmount <= 0.0) {
            return 0.0;
        }

        $key = $this->cacheKey($storeId, $codAmount);

        if (isset($this->runtimeCache[$key])) {
            return $this->runtimeCache[$key];
        }

        $cached = $this->cache->load($key);
        if ($cached !== false && $cached !== null && $cached !== '') {
            $fee = (float) $cached;
            $this->runtimeCache[$key] = $fee;
            return $fee;
        }

        $fallback = $this->resolveFromStoredRate($codAmount);
        if ($fallback !== null) {
            if ($this->config->isLoggingEnabled($storeId)) {
                $this->logger->info('[PigeonExpress] COD fee taken from last API derived rate', [
                    'storeId' => $storeId,
                    'cod_amount' => $codAmount,
                    'fee' => $fallback,
                ]);
            }
            return $fallback;
        }

        $this->logger->warning(
            '[PigeonExpress] COD fee could not be resolved from the API and no previous API derived '
            . 'rate is stored; charging no COD fee.',
            ['storeId' => $storeId, 'cod_amount' => $codAmount]
        );

        return 0.0;
    }

    /**
     * Apply the ratio of the last successful API resolution to the current amount.
     */
    private function resolveFromStoredRate(float $codAmount): ?float
    {
        $service = $this->serviceRepository->getByCode(AdditionalService::CODE_COD_AMOUNT);
        if ($service === null) {
            return null;
        }

        $rate = $service->getLastFeeRate();
        if ($rate === null) {
            return null;
        }

        return round($codAmount * $rate, 2);
    }

    private function cacheKey(int $storeId, float $codAmount): string
    {
        return self::CACHE_PREFIX . $storeId . '_' . str_replace('.', '_', (string) round($codAmount, 2));
    }
}
