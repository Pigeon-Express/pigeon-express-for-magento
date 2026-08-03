<?php
/**
 * What Pigeon Express will carry, learned from Pigeon Express.
 *
 * There is no endpoint that publishes the limits, so they are discovered from
 * the API's own answers and remembered:
 *
 *   refused   {"packages.0.weight":["Максималното тегло на колет е 40 кг."]}
 *             the field name says which limit, the message carries the number
 *
 *   accepted  a parcel heavier or longer than we thought allowed proves the
 *             limit is at least that, so it moves up
 *
 * Nothing here is a rule the module invents. The seeds below are only a starting
 * point for a store that has not yet had an answer either way, and the moment
 * the carrier says otherwise, its answer wins. When Pigeon Express drops the
 * 250 cm rule or writes a tariff above 40 kg, this follows without a release.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Service;

use Magento\Framework\App\CacheInterface;
use PigeonExpress\Shipping\Api\ConfigInterface;
use Psr\Log\LoggerInterface;

class CarrierLimits
{
    private const CACHE_PREFIX = 'pigeonexpress_limits_';

    /** Own tag so limits can be dropped without flushing everything. */
    public const CACHE_TAG = 'PIGEONEXPRESS_LIMITS';

    /** Long, because a carrier's limits change rarely and we relearn on refusal. */
    private const CACHE_LIFETIME = 604800;

    /** The four things a parcel is measured by, as the API names them. */
    public const MEASURES = ['weight', 'length', 'width', 'height'];

    /**
     * Where to start before the carrier has said anything.
     *
     * Measured once, against the demo API, and deliberately not treated as
     * truth: the first answer from the carrier replaces them.
     */
    private const SEED = [
        ConfigInterface::DELIVERY_TYPE_ADDRESS => ['weight' => 40.0, 'length' => 250.0, 'width' => 180.0, 'height' => 150.0],
        ConfigInterface::DELIVERY_TYPE_OFFICE  => ['weight' => 40.0, 'length' => 250.0, 'width' => 180.0, 'height' => 150.0],
        ConfigInterface::DELIVERY_TYPE_APS     => ['weight' => 30.0, 'length' => 250.0, 'width' => 180.0, 'height' => 150.0],
    ];

    /** @var CacheInterface */
    private $cache;

    /** @var ConfigInterface */
    private $config;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(CacheInterface $cache, ConfigInterface $config, LoggerInterface $logger)
    {
        $this->cache = $cache;
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Everything currently known about one parcel for a delivery type.
     *
     * @return array{weight:float, length:float, width:float, height:float}
     */
    public function all(string $deliveryType, int $storeId = 0): array
    {
        $seed = self::SEED[$deliveryType] ?? self::SEED[ConfigInterface::DELIVERY_TYPE_ADDRESS];

        $stored = $this->cache->load($this->key($deliveryType, $storeId));
        if (is_string($stored) && $stored !== '') {
            $decoded = json_decode($stored, true);
            if (is_array($decoded)) {
                foreach (self::MEASURES as $measure) {
                    if (isset($decoded[$measure]) && (float) $decoded[$measure] > 0) {
                        $seed[$measure] = (float) $decoded[$measure];
                    }
                }
            }
        }

        return $seed;
    }

    /**
     * Heaviest single parcel this delivery type is known to carry.
     */
    public function maxWeight(string $deliveryType, int $storeId = 0): float
    {
        return $this->all($deliveryType, $storeId)['weight'];
    }

    /**
     * Take the carrier at its word after a refusal.
     *
     * The field name is what identifies the limit, so the Bulgarian text is
     * never parsed for meaning, only for the number it contains. A message that
     * changes wording still teaches us the same thing.
     *
     * @param array<string, array<int, string>|string> $errors Validation errors, keyed by field
     */
    public function learnFromRefusal(array $errors, string $deliveryType, int $storeId = 0): void
    {
        $learned = [];

        foreach ($errors as $field => $messages) {
            if (!preg_match('/packages\.\d+\.(weight|length|width|height)$/', (string) $field, $match)) {
                continue;
            }

            $text = is_array($messages) ? implode(' ', $messages) : (string) $messages;
            if (!preg_match('/(\d+(?:[.,]\d+)?)/', $text, $number)) {
                continue;
            }

            $value = (float) str_replace(',', '.', $number[1]);
            if ($value > 0) {
                $learned[$match[1]] = $value;
            }
        }

        if (!empty($learned)) {
            $this->remember($learned, $deliveryType, $storeId, 'refused');
        }
    }

    /**
     * A parcel the carrier accepted proves the limit is at least that big.
     *
     * This is the half that lets a limit go up. A refusal can only ever lower
     * what we believe; without this, a carrier that raised its ceiling would
     * never be noticed and parcels would go on being split for no reason.
     *
     * @param array<int, array<string, float>> $packages
     */
    public function learnFromAcceptance(array $packages, string $deliveryType, int $storeId = 0): void
    {
        $known = $this->all($deliveryType, $storeId);
        $learned = [];

        foreach ($packages as $package) {
            foreach (self::MEASURES as $measure) {
                $value = (float) ($package[$measure] ?? 0);
                if ($value > ($learned[$measure] ?? $known[$measure])) {
                    $learned[$measure] = $value;
                }
            }
        }

        if (!empty($learned)) {
            $this->remember($learned, $deliveryType, $storeId, 'accepted');
        }
    }

    /**
     * @param array<string, float> $learned
     */
    private function remember(array $learned, string $deliveryType, int $storeId, string $why): void
    {
        $current = $this->all($deliveryType, $storeId);
        $updated = array_merge($current, $learned);

        if ($updated === $current) {
            return;
        }

        $this->cache->save(
            (string) json_encode($updated),
            $this->key($deliveryType, $storeId),
            [self::CACHE_TAG],
            self::CACHE_LIFETIME
        );

        if ($this->config->isLoggingEnabled($storeId)) {
            $this->logger->info('[PigeonExpress] Carrier limits updated (' . $why . ')', [
                'delivery_type' => $deliveryType,
                'was'           => $current,
                'now'           => $updated,
            ]);
        }
    }

    private function key(string $deliveryType, int $storeId): string
    {
        return self::CACHE_PREFIX . $storeId . '_' . $deliveryType;
    }
}
