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

use PigeonExpress\Exceptions\RateLimitException as SdkRateLimitException;
use PigeonExpress\PigeonExpress;
use PigeonExpress\Resources\Offices;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Api\LocationsClientInterface;
use PigeonExpress\Shipping\Exception\ApiException;
use PigeonExpress\Shipping\Exception\RateLimitedException;
use PigeonExpress\Shipping\Model\Location\WorkingHours;
use Psr\Log\LoggerInterface;

class LocationsClient implements LocationsClientInterface
{
    /** Bulk syncs are not latency sensitive, so allow a generous timeout. */
    private const TIMEOUT = 120;

    /**
     * Seconds for a nearest search. It runs while a shopper waits at
     * checkout, and the normal list is always there to fall back on.
     */
    public const NEAREST_TIMEOUT = 5;

    /** Most locations one nearest search returns. */
    public const NEAREST_LIMIT_MAX = Offices::NEAREST_LIMIT_MAX;

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
     * @inheritdoc
     */
    public function nearest(float $latitude, float $longitude, string $type, int $limit = self::NEAREST_LIMIT_MAX): array
    {
        $apiType = $this->nearestType($type);
        if ($apiType === '') {
            throw new ApiException(__('Unknown location type.'));
        }

        if (!is_finite($latitude) || !is_finite($longitude)
            || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180
        ) {
            throw new ApiException(__('The location could not be read.'));
        }

        try {
            $offices = $this->clientFactory->create(self::NEAREST_TIMEOUT)
                ->offices()
                ->nearest($latitude, $longitude, $apiType, $limit);
        } catch (ApiException $e) {
            throw $e;
        } catch (SdkRateLimitException $e) {
            // Never the point itself: coordinates are not logged.
            $this->logNearestFailure('rate limited', $e);
            throw new RateLimitedException(__('Pigeon Express asked to slow down. Try again later.'), $e);
        } catch (\Exception $e) {
            $this->logNearestFailure('failed', $e);
            throw new ApiException(__('The nearest offices could not be found just now.'), $e);
        }

        $rows = [];
        foreach ($offices as $office) {
            $id = (int) $office->getId();
            if ($id <= 0) {
                continue;
            }
            $rows[] = [
                'id'          => $id,
                'code'        => trim((string) $office->getCode()),
                'type'        => (string) $office->getType(),
                'name'        => (string) $office->getName(),
                'distance_km' => $office->getDistanceKm(),
                'latitude'    => $office->getLatitude(),
                'longitude'   => $office->getLongitude(),
            ];
        }

        return $rows;
    }

    /**
     * The API's nearest type for a module delivery type, or '' for one it
     * does not have.
     */
    private function nearestType(string $type): string
    {
        switch (strtolower(trim($type))) {
            case ConfigInterface::DELIVERY_TYPE_OFFICE:
                return Offices::NEAREST_OFFICE;
            case ConfigInterface::DELIVERY_TYPE_APS:
            case Offices::NEAREST_LOCKER:
                return Offices::NEAREST_LOCKER;
            case Offices::NEAREST_ALL:
                return Offices::NEAREST_ALL;
            default:
                return '';
        }
    }

    private function logNearestFailure(string $what, \Throwable $e): void
    {
        if ($this->config->isLoggingEnabled()) {
            // A transport error names the URL it failed on, and its query
            // holds the shopper's point: never log a query string here.
            $message = (string) preg_replace('/\?\S*/', '', $e->getMessage());
            $this->logger->warning('[PigeonExpress] Nearest offices ' . $what . ': ' . $message);
        }
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
     * @return array{id:?int, code:?string, name:string, city_id:?int, address:string, type:string, latitude:?float, longitude:?float, working_hours:?array}
     */
    private function normalizeOffice($office): array
    {
        $code = trim((string) $office->getCode());

        return [
            'id'            => $office->getId() ?: null,
            // A locker's free boxes are looked up by it, never by the id.
            'code'          => $code !== '' ? $code : null,
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
