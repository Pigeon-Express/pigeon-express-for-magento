<?php
/**
 * The "Courier request" settings: what a new courier request proposes.
 * Defaults as in WooCommerce's CourierRequestService.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Courier;

use Magento\Framework\App\Config\ScopeConfigInterface;
use PigeonExpress\Shipping\Api\ConfigInterface;

class CourierSettings
{
    public const XML_PATH_TIME_FROM = 'carriers/pigeonexpress/courier_time_from';
    public const XML_PATH_TIME_TO = 'carriers/pigeonexpress/courier_time_to';
    public const XML_PATH_CUTOFF = 'carriers/pigeonexpress/courier_same_day_until';
    public const XML_PATH_PACKAGE_TYPE = 'carriers/pigeonexpress/courier_package_type';
    public const XML_PATH_INSTRUCTIONS = 'carriers/pigeonexpress/courier_instructions';
    public const XML_PATH_CONTACT_NAME = 'carriers/pigeonexpress/courier_contact_name';
    public const XML_PATH_CONTACT_PHONE = 'carriers/pigeonexpress/courier_contact_phone';
    public const XML_PATH_CONTACT_EMAIL = 'carriers/pigeonexpress/courier_contact_email';

    public const DEFAULT_TIME_FROM = '10:00';
    public const DEFAULT_TIME_TO = '17:00';
    public const DEFAULT_CUTOFF = '15:00';
    public const DEFAULT_PACKAGE_TYPE = 'standard';

    public const PACKAGE_TYPES = ['standard', 'documents', 'large', 'pallet'];

    /** @var ScopeConfigInterface */
    private $scopeConfig;

    /** @var ConfigInterface */
    private $config;

    public function __construct(ScopeConfigInterface $scopeConfig, ConfigInterface $config)
    {
        $this->scopeConfig = $scopeConfig;
        $this->config = $config;
    }

    /**
     * Whether the shop can ask for a courier at all: the courier collects from
     * an address (typed or saved in the account) and a key is set.
     */
    public function isAvailable(): bool
    {
        return $this->config->getApiKey() !== ''
            && in_array(
                $this->config->getPickupType(),
                [ConfigInterface::PICKUP_TYPE_ADDRESS, ConfigInterface::PICKUP_TYPE_SAVED_ADDRESS],
                true
            );
    }

    public static function isClock(string $value): bool
    {
        return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value);
    }

    public static function minutes(string $clock): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $clock) + [0, 0]);

        return $hours * 60 + $minutes;
    }

    public function timeFrom(): string
    {
        return $this->clock(self::XML_PATH_TIME_FROM, self::DEFAULT_TIME_FROM);
    }

    public function timeTo(): string
    {
        return $this->clock(self::XML_PATH_TIME_TO, self::DEFAULT_TIME_TO);
    }

    public function cutoff(): string
    {
        return $this->clock(self::XML_PATH_CUTOFF, self::DEFAULT_CUTOFF);
    }

    public function packageType(): string
    {
        $value = (string) $this->scopeConfig->getValue(self::XML_PATH_PACKAGE_TYPE);

        return in_array($value, self::PACKAGE_TYPES, true) ? $value : self::DEFAULT_PACKAGE_TYPE;
    }

    public function instructions(): string
    {
        return trim((string) $this->scopeConfig->getValue(self::XML_PATH_INSTRUCTIONS));
    }

    /**
     * @return array{name:string, phone:string, email:string}
     */
    public function contact(): array
    {
        return [
            'name'  => trim((string) $this->scopeConfig->getValue(self::XML_PATH_CONTACT_NAME)),
            'phone' => trim((string) $this->scopeConfig->getValue(self::XML_PATH_CONTACT_PHONE)),
            'email' => trim((string) $this->scopeConfig->getValue(self::XML_PATH_CONTACT_EMAIL)),
        ];
    }

    /**
     * @return array<string, \Magento\Framework\Phrase>
     */
    public static function packageTypeLabels(): array
    {
        return [
            'standard'  => __('Standard'),
            'documents' => __('Documents'),
            'large'     => __('Large'),
            'pallet'    => __('Pallet'),
        ];
    }

    private function clock(string $path, string $default): string
    {
        $value = trim((string) $this->scopeConfig->getValue($path));

        return self::isClock($value) ? $value : $default;
    }
}
