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

use PigeonExpress\PigeonExpress;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Api\LocationsClientInterface;
use PigeonExpress\Shipping\Exception\ApiException;
use PigeonExpress\Shipping\Model\Location\WorkingHours;
use Psr\Log\LoggerInterface;

class LocationsClient implements LocationsClientInterface
{
    /** Bulk syncs are not latency sensitive, so allow a generous timeout. */
    private const TIMEOUT = 120;

    /** @var ConfigInterface */
    private $config;

    /** @var LoggerInterface */
    private $logger;

    /** @var ClientFactory */
    private $clientFactory;

    /** @var WorkingHours */
    private $hoursFormatter;

    public function __construct(
        ConfigInterface $config,
        LoggerInterface $logger,
        ClientFactory $clientFactory,
        ?WorkingHours $hoursFormatter = null
    ) {
        $this->config = $config;
        $this->logger = $logger;
        $this->clientFactory = $clientFactory;
        $this->hoursFormatter = $hoursFormatter ?? new WorkingHours();
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
        return $this->clientFactory->create(self::TIMEOUT);
    }

    /**
     * Map an office or locker onto the row shape LocationSync expects.
     *
     * @param \PigeonExpress\DTO\Response\Office $office
     * @return array{id:?int, name:string, city_id:?int, address:string, type:string, latitude:?float, longitude:?float, working_hours:?array}
     */
    private function normalizeOffice($office): array
    {
        return [
            'id'            => $office->getId() ?: null,
            'name'          => (string) $office->getName(),
            'city_id'       => $office->getCityId(),
            'address'       => (string) $office->getAddress(),
            'type'          => (string) $office->getType(),
            'latitude'      => $office->getLatitude(),
            'longitude'     => $office->getLongitude(),
            'working_hours' => $this->workingHours($office),
        ];
    }

    /**
     * The office's opening hours as a list of {day, open, close} entries,
     * or null when the API gave none.
     *
     * @param \PigeonExpress\DTO\Response\Office $office
     * @return array<int, array<string, string>>|null
     */
    private function workingHours($office): ?array
    {
        $hours = method_exists($office, 'getWorkingHours') ? $office->getWorkingHours() : null;
        if (!is_object($hours)) {
            return null;
        }

        // The list as the API sent it, lunch breaks included. An SDK without
        // getEntries() keeps only one stretch a day, keyed by day.
        $raw = method_exists($hours, 'getEntries') ? $hours->getEntries() : [];
        if ($raw === [] && method_exists($hours, 'toArray')) {
            $raw = array_filter((array) $hours->toArray());
        }

        return $this->hoursFormatter->normalise($raw);
    }

    private function log(string $what, int $count): void
    {
        if ($this->config->isLoggingEnabled()) {
            $this->logger->info('[PigeonExpress] Fetched ' . $count . ' ' . $what . ' from the API.');
        }
    }
}
