<?php
/**
 * The addresses saved in the merchant's Pigeon Express account.
 *
 * The "saved address" pickup type lets the courier collect from one of them.
 * The API is asked at most once an hour per environment, and the answer is
 * kept in the Magento cache, so the settings page and the checkout never wait
 * on it. A failed fetch keeps the last list that was fetched, and counts as
 * "no addresses" only when nothing was ever fetched.
 *
 * Read only: the API cannot add or change saved addresses.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Account;

use Magento\Framework\App\CacheInterface;
use PigeonExpress\Shipping\Model\Api\ClientFactory;
use PigeonExpress\Shipping\Model\Environment;
use Psr\Log\LoggerInterface;

class SavedAddresses
{
    private const CACHE_PREFIX = 'pigeonexpress_saved_addresses_';

    public const CACHE_TAG = 'PIGEONEXPRESS_SAVED_ADDRESSES';

    /** How long a fetched list is trusted, in seconds. */
    private const LIFETIME = 3600;

    /** Short API timeout: this runs on the settings page and at checkout. */
    private const TIMEOUT = 10;

    /** @var ClientFactory */
    private $clientFactory;

    /** @var CacheInterface */
    private $cache;

    /** @var Environment */
    private $environment;

    /** @var LoggerInterface */
    private $logger;

    /** @var array<int, array<string, mixed>>|null */
    private $loaded;

    /**
     * Whether $loaded is a list that was read (from the API, or the last one
     * fetched, kept in the cache), not the empty stand-in for "nothing could
     * be read".
     *
     * @var bool
     */
    private $listRead = false;

    public function __construct(
        ClientFactory $clientFactory,
        CacheInterface $cache,
        Environment $environment,
        LoggerInterface $logger
    ) {
        $this->clientFactory = $clientFactory;
        $this->cache = $cache;
        $this->environment = $environment;
        $this->logger = $logger;
    }

    /**
     * Every saved address, keyed by id, default first.
     *
     * @return array<int, array{id:int, label:string, is_default:bool, pickup_address:array<string, mixed>}>
     */
    public function all(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        $cached = $this->read();
        if ($cached !== null && $cached['fetched_at'] > time() - self::LIFETIME) {
            $this->listRead = true;

            return $this->loaded = $cached['rows'];
        }

        $rows = $this->refresh($cached['rows'] ?? []);
        // A failed fetch still has the last list fetched, if there is one.
        $this->listRead = $this->listRead || $cached !== null;

        return $this->loaded = $rows;
    }

    /**
     * Whether the account has at least one saved address.
     */
    public function hasAny(): bool
    {
        return $this->all() !== [];
    }

    /**
     * One saved address, or null when the account has none with this id.
     *
     * @return array{id:int, label:string, is_default:bool, pickup_address:array<string, mixed>}|null
     */
    public function find(int $id): ?array
    {
        return $this->all()[$id] ?? null;
    }

    /**
     * Whether the account lists this saved address, or null when that cannot
     * be told: no list could be read (no credentials, or the API failed and
     * nothing was fetched before). An empty list that was read is an account
     * without saved addresses, and answers false.
     */
    public function isKnown(int $id): ?bool
    {
        $rows = $this->all();
        if (isset($rows[$id])) {
            return true;
        }

        return $this->listRead ? false : null;
    }

    /**
     * Ask the API again now. Keeps the previous list when the API cannot answer.
     *
     * @param array<int, array<string, mixed>> $previous
     * @return array<int, array<string, mixed>>
     */
    public function refresh(array $previous = []): array
    {
        try {
            if (!$this->clientFactory->hasCredentials()) {
                return $previous;
            }
            $rows = [];
            foreach ($this->clientFactory->create(self::TIMEOUT)->addresses()->all() as $address) {
                $row = self::normalise($address->toArray());
                if ($row !== null) {
                    $rows[$row['id']] = $row;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[PigeonExpress] Saved addresses could not be fetched: ' . $e->getMessage());

            return $previous;
        }

        $this->cache->save(
            (string) json_encode(['fetched_at' => time(), 'rows' => array_values($rows)]),
            $this->key(),
            [self::CACHE_TAG],
            self::LIFETIME * 24
        );
        $this->loaded = $rows;
        $this->listRead = true;

        return $rows;
    }

    /**
     * One address as the API lists it, in the shape the module uses, or null
     * when it has no id or no city to be sent with.
     *
     * @param array<string, mixed> $row
     * @return array{id:int, label:string, is_default:bool, pickup_address:array<string, mixed>}|null
     */
    public static function normalise(array $row): ?array
    {
        $address = isset($row['address']) && is_array($row['address']) ? $row['address'] : [];
        $id = (int) ($row['id'] ?? 0);
        $cityId = (int) ($address['city_id'] ?? 0);
        if ($id <= 0 || $cityId <= 0) {
            return null;
        }

        $label = trim((string) ($row['label'] ?? ''));
        $text = trim(implode(', ', array_filter([
            (string) ($address['city_name'] ?? ''),
            trim((string) ($address['street_name'] ?? '') . ' ' . (string) ($address['street_number'] ?? '')),
        ])));
        if ($label === '') {
            $label = $text !== '' ? $text : (string) __('Address %1', $id);
        } elseif ($text !== '') {
            $label .= ' (' . $text . ')';
        }

        return [
            'id'             => $id,
            'label'          => $label,
            'is_default'     => !empty($row['is_default']),
            'pickup_address' => self::pickupAddress($address),
        ];
    }

    /**
     * The saved address as a pickup_address, for the price endpoint, which
     * does not take a saved address id. As WooCommerce builds it.
     *
     * @param array<string, mixed> $address
     * @return array<string, mixed>
     */
    public static function pickupAddress(array $address): array
    {
        $out = ['city_id' => (int) ($address['city_id'] ?? 0)];

        if ((int) ($address['street_id'] ?? 0) > 0) {
            $out['street_id'] = (int) $address['street_id'];
        }

        foreach (['street_number', 'postal_code'] as $key) {
            if ((string) ($address[$key] ?? '') !== '') {
                $out[$key] = (string) $address[$key];
            }
        }

        // Without a street the neighbourhood is part of the description.
        $info = trim(
            (isset($out['street_id']) ? '' : (string) ($address['neighborhood'] ?? ''))
            . ' ' . (string) ($address['additional_info'] ?? '')
        );
        if ($info === '') {
            $info = (string) ($address['street_number'] ?? '');
        }
        $out['additional_info'] = $info !== '' ? $info : '-';

        return $out;
    }

    /**
     * @return array{fetched_at:int, rows:array<int, array<string, mixed>>}|null
     */
    private function read(): ?array
    {
        $raw = $this->cache->load($this->key());
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['rows']) || !is_array($data['rows'])) {
            return null;
        }

        $rows = [];
        foreach ($data['rows'] as $row) {
            if (is_array($row) && isset($row['id'])) {
                $rows[(int) $row['id']] = $row;
            }
        }

        return ['fetched_at' => (int) ($data['fetched_at'] ?? 0), 'rows' => $rows];
    }

    private function key(): string
    {
        return self::CACHE_PREFIX . $this->environment->current();
    }
}
