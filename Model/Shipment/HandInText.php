<?php
/**
 * Where the shop hands this shipment in, in words, for the order panel.
 *
 * Only said when the shop hands its shipments in at a parcel locker: then a
 * shipment goes to the locker when it fits one and to the fallback office when
 * it does not, and the merchant needs to know which before they walk out of the
 * door with it. The decision is HandInResolver's; this only names it.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Shipment;

use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\ResourceModel\Aps\CollectionFactory as ApsCollectionFactory;
use PigeonExpress\Shipping\Model\ResourceModel\Office\CollectionFactory as OfficeCollectionFactory;
use PigeonExpress\Shipping\Model\Service\ServiceCodesBuilder;
use Psr\Log\LoggerInterface;

class HandInText
{
    /** @var ConfigInterface */
    private $config;

    /** @var HandInResolver */
    private $resolver;

    /** @var ServiceCodesBuilder */
    private $serviceCodesBuilder;

    /** @var OfficeCollectionFactory */
    private $officeCollectionFactory;

    /** @var ApsCollectionFactory */
    private $apsCollectionFactory;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        ConfigInterface $config,
        HandInResolver $resolver,
        ServiceCodesBuilder $serviceCodesBuilder,
        OfficeCollectionFactory $officeCollectionFactory,
        ApsCollectionFactory $apsCollectionFactory,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->resolver = $resolver;
        $this->serviceCodesBuilder = $serviceCodesBuilder;
        $this->officeCollectionFactory = $officeCollectionFactory;
        $this->apsCollectionFactory = $apsCollectionFactory;
        $this->logger = $logger;
    }

    /**
     * Whether the panel has a hand-in point to show at all.
     */
    public function isApplicable(?int $storeId = null): bool
    {
        return $this->config->getPickupType($storeId) === ConfigInterface::PICKUP_TYPE_LOCKER;
    }

    /**
     * "Parcel locker X", or "Office Y (why not the locker)", or '' when the
     * shop does not hand in at a locker or the decision cannot be made.
     *
     * The services count as well as the packages: declared value or a return
     * receipt sends a shipment to the office however small it is.
     *
     * @param array<string, mixed> $fields Shipment form values, package rows and services included
     */
    public function describe(array $fields, ?int $storeId = null): string
    {
        if (!$this->isApplicable($storeId)) {
            return '';
        }

        $packages = isset($fields['packages']) && is_array($fields['packages'])
            ? array_values(array_filter($fields['packages'], 'is_array'))
            : [];

        try {
            $decision = $this->resolver->resolve($packages, $storeId, $this->serviceCodes($fields, (int) $storeId));
        } catch (\Throwable $e) {
            $this->logger->warning('[PigeonExpress] Hand-in point could not be decided: ' . $e->getMessage());

            return '';
        }

        if (!is_array($decision)) {
            return '';
        }

        $id = isset($decision['id']) ? (int) $decision['id'] : 0;
        $reason = trim((string) ($decision['reason'] ?? ''));

        if (($decision['type'] ?? '') === HandInResolver::TYPE_LOCKER) {
            return (string) __('Parcel locker %1', $this->name(ConfigInterface::DELIVERY_TYPE_APS, $id));
        }

        $text = (string) __('Office %1', $this->name(ConfigInterface::DELIVERY_TYPE_OFFICE, $id));

        return $reason === '' ? $text : (string) __('%1 (%2)', $text, $reason);
    }

    /**
     * The services the shipment would carry, as the payload names them, with
     * the form's own choices in place of the settings. COD is left out: a
     * locker takes it, so it can never decide the question.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function serviceCodes(array $fields, int $storeId): array
    {
        $overrides = [];
        if (array_key_exists('declared_value', $fields) && is_scalar($fields['declared_value'])) {
            $overrides[ServiceCodesBuilder::OVERRIDE_DECLARED_VALUE] = filter_var(
                $fields['declared_value'],
                FILTER_VALIDATE_BOOLEAN
            );
        }
        foreach ([
            'review'         => ServiceCodesBuilder::OVERRIDE_REVIEW,
            'return_receipt' => ServiceCodesBuilder::OVERRIDE_RETURN_RECEIPT,
        ] as $field => $override) {
            if (isset($fields[$field]) && is_string($fields[$field]) && $fields[$field] !== '') {
                $overrides[$override] = $fields[$field];
            }
        }

        $type = (string) ($fields['delivery_type'] ?? ConfigInterface::DELIVERY_TYPE_ADDRESS);

        return $this->serviceCodesBuilder->build(
            $type,
            $type === ConfigInterface::DELIVERY_TYPE_APS ? 'locker' : $type,
            $storeId,
            0.0,
            false,
            1.0,
            $overrides
        );
    }

    /**
     * The name of an office or locker by its Pigeon Express number, or the
     * number itself when it is not in the synced list.
     */
    private function name(string $type, int $apiId): string
    {
        if ($apiId <= 0) {
            return '';
        }

        $collection = $type === ConfigInterface::DELIVERY_TYPE_APS
            ? $this->apsCollectionFactory->create()
            : $this->officeCollectionFactory->create();
        $collection->addFieldToFilter('api_id', ['eq' => $apiId]);
        $collection->setPageSize(1);
        $item = $collection->getFirstItem();

        $name = $item && $item->getId() ? trim((string) $item->getData('name')) : '';

        return $name !== '' ? $name : (string) $apiId;
    }
}
