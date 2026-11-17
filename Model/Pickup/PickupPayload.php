<?php
/**
 * The pickup part of a calculate or create payload, from the Send From settings.
 *
 * One builder for rating and for shipment creation, so a customer is quoted
 * for the pickup the shipment is then created with:
 *
 *   office         pickup_type office, pickup_office_id
 *   address        pickup_type address, pickup_address from the typed fields
 *   saved_address  pickup_type address, sender_address_id (create); the price
 *                  endpoint does not know saved ids, so a quote sends the
 *                  saved address itself as pickup_address
 *   locker         pickup_type locker with the locker id in pickup_office_id,
 *                  or pickup_type office with the fallback office when the
 *                  shipment does not fit a locker (HandInResolver)
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Pickup;

use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Exception\ApiException;
use PigeonExpress\Shipping\Model\Account\SavedAddresses;
use PigeonExpress\Shipping\Model\Shipment\HandInResolver;

class PickupPayload
{
    /** @var ConfigInterface */
    private $config;

    /** @var HandInResolver */
    private $handIn;

    /** @var SavedAddresses */
    private $savedAddresses;

    public function __construct(
        ConfigInterface $config,
        HandInResolver $handIn,
        SavedAddresses $savedAddresses
    ) {
        $this->config = $config;
        $this->handIn = $handIn;
        $this->savedAddresses = $savedAddresses;
    }

    /**
     * @param array<int, array<string, mixed>> $packages
     * @param array<string, mixed> $serviceCodes
     * @param bool $forQuote For POST /shipments/calculate rather than create
     * @return array<string, mixed>
     * @throws ApiException When Send From is incomplete
     */
    public function build(int $storeId, array $packages = [], array $serviceCodes = [], bool $forQuote = false): array
    {
        $type = $this->config->getPickupType($storeId);

        if ($type === ConfigInterface::PICKUP_TYPE_LOCKER) {
            return $this->locker($storeId, $packages, $serviceCodes);
        }

        if ($type === ConfigInterface::PICKUP_TYPE_SAVED_ADDRESS) {
            return $this->savedAddress($storeId, $forQuote);
        }

        if ($type === ConfigInterface::PICKUP_TYPE_ADDRESS) {
            return ['pickup_type' => 'address', 'pickup_address' => $this->typedAddress($storeId)];
        }

        $officeId = $this->config->getPickupOfficeId($storeId);
        if ($officeId === null || $officeId <= 0) {
            throw new ApiException(__('Pickup office is not configured.'));
        }

        return ['pickup_type' => 'office', 'pickup_office_id' => $officeId];
    }

    /**
     * @param array<int, array<string, mixed>> $packages
     * @param array<string, mixed> $serviceCodes
     * @return array<string, mixed>
     * @throws ApiException
     */
    private function locker(int $storeId, array $packages, array $serviceCodes): array
    {
        if ($this->config->getPickupLockerId($storeId) === null) {
            throw new ApiException(__(
                'Send From is incomplete: choose the parcel locker you hand shipments in at. '
                . 'Prices and shipments fail until you do.'
            ));
        }
        if ($this->config->getPickupFallbackOfficeId($storeId) === null) {
            throw new ApiException(__(
                'Send From is incomplete: choose the office for shipments that do not fit a parcel locker. '
                . 'Prices and shipments fail until you do.'
            ));
        }

        $point = $this->handIn->resolve($packages, $storeId, $serviceCodes);

        return [
            'pickup_type'      => $point['type'] === HandInResolver::TYPE_LOCKER ? 'locker' : 'office',
            'pickup_office_id' => (int) $point['id'],
        ];
    }

    /**
     * Never together with a typed address: the carrier refuses both.
     *
     * @return array<string, mixed>
     * @throws ApiException
     */
    private function savedAddress(int $storeId, bool $forQuote): array
    {
        $id = $this->config->getPickupSavedAddressId($storeId);
        if ($id === null) {
            throw new ApiException(__(
                'Send From is incomplete: choose the saved address. Prices and shipments fail until you do.'
            ));
        }

        if (!$forQuote) {
            return ['pickup_type' => 'address', 'sender_address_id' => $id];
        }

        $entry = $this->savedAddresses->find($id);
        if ($entry === null) {
            throw new ApiException(__(
                'Send From: the saved address is no longer in your Pigeon Express account. '
                . 'Choose another, or another pickup type.'
            ));
        }

        return ['pickup_type' => 'address', 'pickup_address' => $entry['pickup_address']];
    }

    /**
     * The address typed in Send From.
     *
     * @return array<string, mixed>
     * @throws ApiException
     */
    public function typedAddress(int $storeId): array
    {
        $cityId = $this->config->getPickupAddressCityId($storeId);
        if ($cityId === null || $cityId === '') {
            throw new ApiException(__('Pickup address city is not configured.'));
        }
        $streetNumber = $this->config->getPickupAddressStreetNumber($storeId);
        $streetNumber = ($streetNumber !== null && $streetNumber !== '') ? $streetNumber : '1';
        $result = [
            'city_id'       => (int) $cityId,
            'street_number' => $streetNumber,
        ];
        $streetId = $this->config->getPickupAddressStreetId($storeId);
        if ($streetId !== null && $streetId !== '') {
            $result['street_id'] = (int) $streetId;
        } else {
            $streetLabel = $this->config->getPickupAddressStreetLabel($storeId);
            if ($streetLabel !== null && $streetLabel !== '') {
                $result['street_name'] = $streetLabel;
            }
            $additionalInfo = trim($streetLabel ?? '') !== '' ? trim((string) $streetLabel) : 'Address';
            if (strlen($additionalInfo) < 3) {
                $additionalInfo = 'Address';
            }
            $result['additional_info'] = $additionalInfo;
        }
        $postalCode = $this->config->getPickupAddressPostalCode($storeId);
        if ($postalCode !== null && $postalCode !== '') {
            $result['postal_code'] = $postalCode;
        }

        return $result;
    }
}
