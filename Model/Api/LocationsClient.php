<?php
/**
 * Locations API client: offices, lockers and cities, via the Pigeon Express PHP SDK.
 *
 * This previously used Magento Curl directly because the SDK ignored query
 * parameters in all() and so could not paginate or filter by type. The SDK now
 * walks every page, caps per_page at the API limit, retries a failed page and
 * honours Retry-After on a 429, so there is one HTTP path in this module again.
 *
 * The row shapes returned here are unchanged, because LocationSync consumes them.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Api;

use PigeonExpress\Exceptions\PigeonExpressException as SdkException;
use PigeonExpress\PigeonExpress;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Api\LocationsClientInterface;
use PigeonExpress\Shipping\Exception\ApiException;
use Psr\Log\LoggerInterface;

class LocationsClient implements LocationsClientInterface
{
    private const DEMO_BASE_URL = 'https://api-demo.pigeonexpress.com';

    /** Bulk syncs are not latency sensitive, so allow a generous timeout. */
    private const TIMEOUT = 120;

    /** @var ConfigInterface */
    private $config;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        ConfigInterface $config,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * @inheritdoc
     */
    public function fetchOffices(): array
    {
        $offices = $this->client()->offices()->offices();

        $rows = [];
        foreach ($offices as $office) {
            $rows[] = $this->normalizeOffice($office);
        }

        $this->log('offices', count($rows));

        return $rows;
    }

    /**
     * @inheritdoc
     */
    public function fetchAps(): array
    {
        $lockers = $this->client()->offices()->lockers();

        $rows = [];
        foreach ($lockers as $locker) {
            $rows[] = $this->normalizeOffice($locker);
        }

        $this->log('lockers', count($rows));

        return $rows;
    }

    /**
     * @inheritdoc
     */
    public function fetchCities(): array
    {
        $cities = $this->client()->cities()->all();

        $rows = [];
        foreach ($cities as $city) {
            $id = $city->getId();
            if (!$id) {
                continue;
            }
            $rows[] = [
                'id'          => (int) $id,
                'name'        => (string) $city->getName(),
                'name_en'     => $city->getNameEn(),
                'postal_code' => $city->getPostalCode(),
                // Bulgaria is one of the countries Magento will not accept an
                // address for without a region, and this is the only place a
                // region for a Pigeon Express city can come from.
                'district'    => $city->getDistrict(),
                'district_en' => $city->getDistrictEn(),
            ];
        }

        $this->log('cities', count($rows));

        return $rows;
    }

    /**
     * Build a configured SDK client.
     *
     * @throws ApiException
     */
    private function client(): PigeonExpress
    {
        $apiKey = $this->config->getApiKey();
        if ($apiKey === '') {
            throw new ApiException(__('Pigeon Express API key is not configured.'));
        }

        $apiSecret = $this->config->getApiSecret();
        if ($apiSecret === '') {
            $apiSecret = $apiKey;
        }

        $options = ['timeout' => self::TIMEOUT];
        if ($this->config->isTestMode()) {
            $options['base_url'] = self::DEMO_BASE_URL;
        }

        try {
            return new PigeonExpress($apiKey, $apiSecret, $options);
        } catch (SdkException $e) {
            throw new ApiException(__('Failed to reach Pigeon Express: %1', $e->getMessage()), $e);
        }
    }

    /**
     * Map an office or locker onto the row shape LocationSync expects.
     *
     * @param \PigeonExpress\DTO\Response\Office $office
     * @return array{id:?int, name:string, city_id:?int, address:string, type:string, latitude:?float, longitude:?float}
     */
    private function normalizeOffice($office): array
    {
        return [
            'id'        => $office->getId() ?: null,
            'name'      => (string) $office->getName(),
            'city_id'   => $office->getCityId(),
            'address'   => (string) $office->getAddress(),
            'type'      => (string) $office->getType(),
            'latitude'  => $office->getLatitude(),
            'longitude' => $office->getLongitude(),
        ];
    }

    private function log(string $what, int $count): void
    {
        if ($this->config->isLoggingEnabled()) {
            $this->logger->info('[PigeonExpress] Fetched ' . $count . ' ' . $what . ' from the API.');
        }
    }
}
