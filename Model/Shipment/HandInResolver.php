<?php
/**
 * Where a shipment is handed in to Pigeon Express, when that depends on the
 * shipment itself.
 *
 * With the "parcel locker" pickup type the shop leaves its shipments in a
 * locker, and the carrier takes at a locker only what fits one: a single
 * package within the locker limits, and only the services lockers take.
 * Every other shipment goes to the office the shop chose for that. This is
 * the one place it is decided, for the checkout quote, the order panel and
 * shipment creation alike, so a customer is quoted for the hand-in point the
 * shipment then goes to. As in WooCommerce (Labels\PickupPoint).
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Shipment;

use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\AdditionalServiceRepository;
use PigeonExpress\Shipping\Model\Service\CarrierLimits;

class HandInResolver
{
    public const TYPE_LOCKER = 'locker';
    public const TYPE_OFFICE = 'office';

    public const REASON_WEIGHT = 'weight';
    public const REASON_SIZE = 'size';
    public const REASON_PACKAGES = 'packages';
    public const REASON_SERVICES = 'services';

    /** A locker takes one package. */
    private const LOCKER_MAX_PACKAGES = 1;

    /**
     * Services, as a payload names them, that a locker hand-in takes. Anything
     * else, declared value and return receipts included, goes to the office.
     */
    public const LOCKER_SERVICES = [
        'cod_amount',
        'cod_card_fee',
        'pay_with_pos',
        'sms_notification_receiver',
    ];

    /** @var ConfigInterface */
    private $config;

    /** @var CarrierLimits */
    private $limits;

    /** @var AdditionalServiceRepository */
    private $services;

    public function __construct(
        ConfigInterface $config,
        CarrierLimits $limits,
        AdditionalServiceRepository $services
    ) {
        $this->config = $config;
        $this->limits = $limits;
        $this->services = $services;
    }

    /**
     * The hand-in point for a shipment of these packages.
     *
     * @param array<int, array<string, mixed>> $packages Rows of weight (kg), length, width, height (cm)
     * @param array<string, mixed> $serviceCodes The service_codes the shipment carries, when known
     * @return array{type:string, id:int|null, reason:string}
     */
    public function resolve(array $packages, ?int $storeId = null, array $serviceCodes = []): array
    {
        if ($this->config->getPickupType($storeId) !== ConfigInterface::PICKUP_TYPE_LOCKER) {
            return [
                'type'   => self::TYPE_OFFICE,
                'id'     => $this->config->getPickupOfficeId($storeId),
                'reason' => '',
            ];
        }

        $decision = $this->decide($packages, $serviceCodes, (int) $storeId);

        if ($decision['reasons'] === []) {
            return [
                'type'   => self::TYPE_LOCKER,
                'id'     => $this->config->getPickupLockerId($storeId),
                'reason' => '',
            ];
        }

        return [
            'type'   => self::TYPE_OFFICE,
            'id'     => $this->config->getPickupFallbackOfficeId($storeId),
            'reason' => $this->reasonText($decision['reasons'], $decision['services'], (int) $storeId),
        ];
    }

    /**
     * Why a shipment does not fit a locker; no reasons when it does.
     *
     * @param array<int, array<string, mixed>> $packages
     * @param array<string, mixed> $serviceCodes
     * @return array{reasons: string[], services: string[]}
     */
    public function decide(array $packages, array $serviceCodes, int $storeId = 0): array
    {
        $reasons = [];
        $packages = array_values(array_filter($packages, 'is_array'));

        if (count($packages) > self::LOCKER_MAX_PACKAGES) {
            $reasons[] = self::REASON_PACKAGES;
        }

        $maxWeight = $this->limits->maxWeight(ConfigInterface::DELIVERY_TYPE_APS, $storeId);
        $box = $this->box($storeId);

        foreach ($packages as $package) {
            if ((float) ($package['weight'] ?? 0) > $maxWeight) {
                $reasons[] = self::REASON_WEIGHT;
            }

            $sides = [
                (float) ($package['length'] ?? 0),
                (float) ($package['width'] ?? 0),
                (float) ($package['height'] ?? 0),
            ];
            if (min($sides) <= 0.0 || ($box !== null && !$this->fits($sides, $box))) {
                $reasons[] = self::REASON_SIZE;
            }
        }

        $refused = [];
        foreach ($serviceCodes as $code => $value) {
            if ($value === false || $value === null || $value === '' || $value === 0 || $value === 0.0) {
                continue;
            }
            if (!in_array((string) $code, self::LOCKER_SERVICES, true)) {
                $refused[] = (string) $code;
            }
        }
        if ($refused !== []) {
            $reasons[] = self::REASON_SERVICES;
        }

        return ['reasons' => array_values(array_unique($reasons)), 'services' => $refused];
    }

    /**
     * Why a shipment does not go to the locker, in a few plain words.
     *
     * @param string[] $reasons
     * @param string[] $services
     */
    public function reasonText(array $reasons, array $services, int $storeId = 0): string
    {
        $parts = [];
        foreach ($reasons as $reason) {
            switch ($reason) {
                case self::REASON_WEIGHT:
                    $parts[] = (string) __(
                        'the shipment is over %1 kg',
                        $this->limits->maxWeight(ConfigInterface::DELIVERY_TYPE_APS, $storeId)
                    );
                    break;
                case self::REASON_SIZE:
                    $box = $this->box($storeId);
                    $parts[] = $box !== null
                        ? (string) __('the shipment is larger than %1 cm', implode(' x ', array_map('round', $box)))
                        : (string) __('the shipment does not meet the parcel locker limits');
                    break;
                case self::REASON_PACKAGES:
                    $parts[] = (string) __('the shipment is more than one package');
                    break;
                case self::REASON_SERVICES:
                    $parts[] = (string) __(
                        'a parcel locker does not take %1',
                        implode(', ', array_map([$this, 'serviceName'], $services))
                    );
                    break;
                default:
                    $parts[] = (string) __('the shipment does not meet the parcel locker limits');
            }
        }

        return implode('; ', array_unique($parts));
    }

    /**
     * The locker compartment, longest side first, or null when unchecked.
     *
     * @return float[]|null
     */
    private function box(int $storeId): ?array
    {
        $compartment = $this->config->getLockerCompartment($storeId);
        $box = [(float) $compartment['width'], (float) $compartment['height'], (float) $compartment['length']];
        if (min($box) <= 0.0) {
            return null;
        }

        return $box;
    }

    /**
     * Whether a parcel goes into the box turned any way round.
     *
     * @param float[] $sides
     * @param float[] $box
     */
    private function fits(array $sides, array $box): bool
    {
        rsort($sides);
        rsort($box);
        foreach ($sides as $i => $side) {
            if ($side > $box[$i]) {
                return false;
            }
        }

        return true;
    }

    private function serviceName(string $code): string
    {
        try {
            $service = $this->services->getByCode($code);
        } catch (\Throwable $e) {
            $service = null;
        }
        $name = $service !== null ? trim((string) $service->getName()) : '';

        return $name !== '' ? $name : $code;
    }
}
