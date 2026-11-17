<?php
/**
 * The carrier's own names for its status codes (GET /shipment-statuses),
 * kept for a day. Used only for a code the module has no name of its own for.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Status;

use Magento\Framework\App\CacheInterface;
use PigeonExpress\Shipping\Model\Api\ClientFactory;

class StatusNames
{
    private const CACHE_KEY = 'pigeonexpress_shipment_status_names';
    private const CACHE_TTL = 86400;
    private const CACHE_TTL_FAILED = 3600;

    /** @var CacheInterface */
    private $cache;

    /** @var ClientFactory */
    private $clientFactory;

    /** @var array<string, string>|null */
    private $names;

    public function __construct(CacheInterface $cache, ClientFactory $clientFactory)
    {
        $this->cache = $cache;
        $this->clientFactory = $clientFactory;
    }

    /**
     * What a status is called: the module's own (translated) name, else the
     * carrier's name from its status list, else the name read with the
     * status, else "Unknown status".
     */
    public function label(string $code, string $carrierName = ''): string
    {
        $known = StatusCodes::knownLabel($code);
        if ($known !== '') {
            return $known;
        }

        $listed = $this->all()[$code] ?? '';
        if ($listed !== '') {
            return $listed;
        }

        $carrierName = trim($carrierName);

        return $carrierName !== '' ? $carrierName : (string) __('Unknown status');
    }

    /**
     * @return array<string, string>
     */
    private function all(): array
    {
        if ($this->names !== null) {
            return $this->names;
        }

        $cached = $this->cache->load(self::CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return $this->names = array_map('strval', $decoded);
            }
        }

        $names = [];
        $ttl = self::CACHE_TTL;
        try {
            if ($this->clientFactory->hasCredentials()) {
                $names = $this->clientFactory->create()->shipmentStatuses()->namesByCode();
            }
        } catch (\Throwable $e) {
            $ttl = self::CACHE_TTL_FAILED;
        }

        $this->cache->save((string) json_encode($names), self::CACHE_KEY, [], $ttl);

        return $this->names = array_map('strval', $names);
    }
}
