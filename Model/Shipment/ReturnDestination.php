<?php
/**
 * Where an undelivered shipment, and the documents it brings back, return to:
 * the fields the create and change bodies carry, and how the order shows it.
 *
 * As in the WooCommerce plugin (BodyBuilder::return_destinations(),
 * OrderMetaBox::return_destination_lines()):
 *
 * - Nothing is sent when the Pigeon Express account decides, which is what
 *   every shipment did before the Returns settings existed.
 * - An office or a saved address, never both: the carrier refuses the two
 *   together, and the mode in the settings (or the record) decides which.
 * - A saved address is sent only while the account still lists it. An id the
 *   list does not have (deleted in the account, or saved under other
 *   credentials) would have the carrier refuse the whole shipment, so it is
 *   left out and the account's own setting applies. On a change, the
 *   recorded address is left out only when a list was actually read and
 *   lacks it: when the list cannot be read at all (the API failed and
 *   nothing was fetched before), it is sent again as recorded.
 * - A destination for return documents only with a service that brings
 *   documents back: without one the carrier refuses it, and with it the
 *   whole shipment. Never on a return (reverse) shipment, whose body is
 *   built elsewhere (ReturnBodyBuilder).
 * - A shipment that exists keeps the destination it was created with: a
 *   change sends the recorded one again (fromRecord(), see ShipmentEditor),
 *   so a Returns setting changed since does not move a parcel already on its
 *   way.
 *
 * The record is the body stored with the shipment (pigeonexpress_shipment.payload).
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Shipment;

use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Api\LocationSearchInterface;
use PigeonExpress\Shipping\Logger\Logger;
use PigeonExpress\Shipping\Model\Account\SavedAddresses;
use Psr\Log\LoggerInterface;

class ReturnDestination
{
    public const OFFICE = 'return_office_id';
    public const ADDRESS = 'return_address_id';
    public const DOCUMENTS_OFFICE = 'return_documents_office_id';
    public const DOCUMENTS_ADDRESS = 'return_documents_address_id';

    /** Every destination field of the body, in the order they are decided. */
    public const KEYS = [self::OFFICE, self::ADDRESS, self::DOCUMENTS_OFFICE, self::DOCUMENTS_ADDRESS];

    /**
     * Services after which the recipient's signed papers travel back to the
     * sender. Only with one of them does the carrier accept a destination for
     * return documents.
     */
    public const DOCUMENT_SERVICE_CODES = [
        'service_return_documents',
        'paper_return_receipt',
        'ID_verification_and_document_signature',
    ];

    /** @var ConfigInterface */
    private $config;

    /** @var SavedAddresses */
    private $savedAddresses;

    /** @var LocationSearchInterface */
    private $locationSearch;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        ConfigInterface $config,
        SavedAddresses $savedAddresses,
        LocationSearchInterface $locationSearch,
        Logger $logger
    ) {
        $this->config = $config;
        $this->savedAddresses = $savedAddresses;
        $this->locationSearch = $locationSearch;
        $this->logger = $logger;
    }

    /**
     * The destination fields of a new shipment, from the Returns settings.
     *
     * @param array<string, mixed> $serviceCodes The services the body carries, keyed by API code
     * @return array<string, int>
     */
    public function fromSettings(int $storeId, array $serviceCodes): array
    {
        return $this->build([
            self::OFFICE            => (int) $this->config->getReturnOfficeId($storeId),
            self::ADDRESS           => (int) $this->config->getReturnAddressId($storeId),
            self::DOCUMENTS_OFFICE  => (int) $this->config->getReturnDocumentsOfficeId($storeId),
            self::DOCUMENTS_ADDRESS => (int) $this->config->getReturnDocumentsAddressId($storeId),
        ], $serviceCodes, $storeId, false);
    }

    /**
     * The destination fields as a shipment was sent with them, for a change
     * of it: the carrier clears whatever a change leaves out.
     *
     * @param array<string, mixed> $recorded The body the shipment was created or last changed with
     * @param array<string, mixed> $serviceCodes The services the change carries
     * @return array<string, int>
     */
    public function fromRecord(array $recorded, array $serviceCodes, int $storeId): array
    {
        return $this->build(self::recorded($recorded), $serviceCodes, $storeId, true);
    }

    /**
     * The four destination ids of a stored body, 0 for each one not sent.
     *
     * @param array<string, mixed> $payload
     * @return array<string, int>
     */
    public static function recorded(array $payload): array
    {
        $ids = [];
        foreach (self::KEYS as $key) {
            $value = $payload[$key] ?? null;
            $ids[$key] = is_scalar($value) && ctype_digit(trim((string) $value)) ? (int) $value : 0;
        }

        return $ids;
    }

    /**
     * Whether a change left out the saved address the shipment was recorded
     * with (it is no longer in the account). Undelivered shipments then
     * return as set in the account, and the order comment says so.
     *
     * @param array<string, mixed> $recorded The stored body
     * @param array<string, mixed> $sent The body of the change
     */
    public static function addressDropped(array $recorded, array $sent): bool
    {
        $ids = self::recorded($recorded);

        return $ids[self::OFFICE] === 0
            && $ids[self::ADDRESS] > 0
            && !isset($sent[self::OFFICE])
            && !isset($sent[self::ADDRESS]);
    }

    /**
     * Whether the services include one that brings documents back.
     *
     * @param array<string, mixed> $serviceCodes
     */
    public static function carriesDocuments(array $serviceCodes): bool
    {
        return array_intersect(self::DOCUMENT_SERVICE_CODES, array_keys(array_filter($serviceCodes))) !== [];
    }

    /**
     * Where the shipment returns to, as it was sent: the office, the saved
     * address, or the account's own setting.
     *
     * @param array<string, mixed> $payload The stored body
     */
    public function describe(array $payload, int $storeId): string
    {
        $ids = self::recorded($payload);

        if ($ids[self::OFFICE] > 0) {
            return $this->officeName($ids[self::OFFICE], $storeId);
        }
        if ($ids[self::ADDRESS] > 0) {
            return $this->addressName($ids[self::ADDRESS]);
        }

        return (string) __('As set in your Pigeon Express account');
    }

    /**
     * The lines the order shows, label => text: where the shipment returns
     * to and, only when sent and while the shop can choose it
     * (ConfigInterface::RETURN_DOCUMENTS_CHOICE), where its documents go.
     *
     * @param array<string, mixed> $payload The stored body
     * @return array<string, string>
     */
    public function lines(array $payload, int $storeId): array
    {
        $lines = [(string) __('Return to') => $this->describe($payload, $storeId)];

        if (ConfigInterface::RETURN_DOCUMENTS_CHOICE) {
            $ids = self::recorded($payload);
            if ($ids[self::DOCUMENTS_OFFICE] > 0) {
                $lines[(string) __('Return documents to')] = $this->officeName($ids[self::DOCUMENTS_OFFICE], $storeId);
            } elseif ($ids[self::DOCUMENTS_ADDRESS] > 0) {
                $lines[(string) __('Return documents to')] = $this->addressName($ids[self::DOCUMENTS_ADDRESS]);
            }
        }

        return $lines;
    }

    /**
     * @param array<string, int> $ids
     * @param array<string, mixed> $serviceCodes
     * @param bool $recorded Whether the ids are the ones a shipment was sent with
     * @return array<string, int>
     */
    private function build(array $ids, array $serviceCodes, int $storeId, bool $recorded): array
    {
        $out = [];

        if ($ids[self::OFFICE] > 0) {
            $out[self::OFFICE] = $ids[self::OFFICE];
        } elseif ($this->isKnownAddress($ids[self::ADDRESS], self::ADDRESS, $storeId, $recorded)) {
            $out[self::ADDRESS] = $ids[self::ADDRESS];
        }

        if (!self::carriesDocuments($serviceCodes)) {
            return $out;
        }

        if ($ids[self::DOCUMENTS_OFFICE] > 0) {
            $out[self::DOCUMENTS_OFFICE] = $ids[self::DOCUMENTS_OFFICE];
        } elseif ($this->isKnownAddress($ids[self::DOCUMENTS_ADDRESS], self::DOCUMENTS_ADDRESS, $storeId, $recorded)) {
            $out[self::DOCUMENTS_ADDRESS] = $ids[self::DOCUMENTS_ADDRESS];
        }

        return $out;
    }

    /**
     * Whether this saved address may be sent: a new shipment only with one
     * the account lists; a recorded one unless a list was read without it
     * (a list that cannot be read is no proof it left the account).
     *
     * @param string $field The body field it was meant for, for the log
     * @param bool $recorded Whether the shipment was already sent with it
     */
    private function isKnownAddress(int $addressId, string $field, int $storeId, bool $recorded): bool
    {
        if ($addressId <= 0) {
            return false;
        }

        if ($recorded) {
            $known = $this->savedAddresses->isKnown($addressId);
            if ($known === null) {
                $this->log($storeId, sprintf(
                    '[PigeonExpress] Return %s=%d sent again as recorded: the saved addresses could not be read',
                    $field,
                    $addressId
                ));
            }
            if ($known !== false) {
                return true;
            }
        } elseif ($this->savedAddresses->find($addressId) !== null) {
            return true;
        }

        $this->log($storeId, sprintf(
            '[PigeonExpress] Return %s=%d not sent: not among the saved addresses of this account',
            $field,
            $addressId
        ));

        return false;
    }

    private function log(int $storeId, string $message): void
    {
        if ($this->config->isLoggingEnabled($storeId)) {
            $this->logger->info($message);
        }
    }

    private function officeName(int $officeId, int $storeId): string
    {
        try {
            $rows = $this->locationSearch->rowsByApiIds(ConfigInterface::DELIVERY_TYPE_OFFICE, [$officeId], $storeId);
        } catch (\Throwable $e) {
            $rows = [];
        }

        if ($rows === []) {
            return (string) __('Office %1', $officeId);
        }

        $name = trim((string) ($rows[0]['name'] ?? ''));
        $address = trim((string) ($rows[0]['address'] ?? ''));
        $city = trim((string) ($rows[0]['city'] ?? ''));
        // The synced address usually names the town already.
        if ($city !== '' && $address !== '' && mb_stripos($address, $city) !== false) {
            $city = '';
        }

        return implode(' - ', array_filter([$name, $address, $city], 'strlen'));
    }

    /**
     * A saved address as the settings list it; one that has left the account
     * since reads by its number.
     */
    private function addressName(int $addressId): string
    {
        try {
            $entry = $this->savedAddresses->find($addressId);
        } catch (\Throwable $e) {
            $entry = null;
        }

        $label = $entry !== null ? trim((string) ($entry['label'] ?? '')) : '';

        return $label !== '' ? $label : (string) __('Address %1', $addressId);
    }
}
