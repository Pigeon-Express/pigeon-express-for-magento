<?php
/**
 * Persist selected Pigeon Express office/APS on quote shipping address as early as possible.
 *
 * Called from checkout location-autocomplete when customer selects a location.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Controller\Checkout;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\QuoteAddressLocationPersistor;
use PigeonExpress\Shipping\Model\Rate\LocationToApiIdResolver;
use Psr\Log\LoggerInterface;

class SetLocation extends Action implements HttpPostActionInterface
{
    /** Courier note field is not unbounded free text. */
    private const MAX_INSTRUCTIONS_LENGTH = 255;

    /** @var JsonFactory */
    private $resultJsonFactory;

    /** @var CheckoutSession */
    private $checkoutSession;

    /** @var QuoteAddressLocationPersistor */
    private $locationPersistor;

    /** @var LocationToApiIdResolver */
    private $locationToApiIdResolver;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        CheckoutSession $checkoutSession,
        QuoteAddressLocationPersistor $locationPersistor,
        LocationToApiIdResolver $locationToApiIdResolver,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->checkoutSession = $checkoutSession;
        $this->locationPersistor = $locationPersistor;
        $this->locationToApiIdResolver = $locationToApiIdResolver;
        $this->logger = $logger;
    }

    /**
     * Save selected location (office/APS) on quote shipping address and PE quote table.
     *
     * Expected POST params: delivery_type, location_id, location_name, location_address, instructions.
     */
    public function execute(): ResultInterface
    {
        $result = $this->resultJsonFactory->create();

        try {
            $quote = $this->checkoutSession->getQuote();
            if (!$quote || !$quote->getId()) {
                return $result->setData(['success' => false, 'message' => 'Quote not found']);
            }

            $request = $this->getRequest();

            $deliveryType = (string) $request->getParam('delivery_type', '');
            $locationId = (string) $request->getParam('location_id', '');

            // Only office and locker deliveries carry a location.
            if (!in_array($deliveryType, [ConfigInterface::DELIVERY_TYPE_OFFICE, ConfigInterface::DELIVERY_TYPE_APS], true)) {
                return $result->setData(['success' => false, 'message' => 'Unsupported delivery type']);
            }

            // The location must be one we actually synced and that is still
            // active. Name, address, city and postcode are then taken from our
            // own record, so request input cannot steer the rate calculation or
            // land arbitrary text on the order.
            $location = $this->locationToApiIdResolver->resolveLocation($deliveryType, $locationId);
            if ($location === null) {
                $this->logger->warning('[PE SetLocation] Rejected unknown or inactive location', [
                    'delivery_type' => $deliveryType,
                    'location_id' => $locationId,
                ]);
                return $result->setData(['success' => false, 'message' => 'Unknown location']);
            }

            $locationId = (string) $location['entity_id'];
            $locationName = $location['name'];
            $locationAddress = $location['address'];
            $locationCity = $location['city'];
            $locationPostcode = $location['postcode'];

            // Free text from the customer. Keep it plain and bounded.
            $instructions = trim(strip_tags((string) $request->getParam('instructions', '')));
            if (mb_strlen($instructions) > self::MAX_INSTRUCTIONS_LENGTH) {
                $instructions = mb_substr($instructions, 0, self::MAX_INSTRUCTIONS_LENGTH);
            }

            $address = $quote->getShippingAddress();
            if (!$address) {
                return $result->setData(['success' => false, 'message' => 'No shipping address']);
            }

            $ext = $address->getExtensionAttributes();
            if ($ext === null) {
                $ext = $address->getExtensionAttributes();
            }
            if ($ext === null) {
                // If extension attributes still null, nothing more we can do safely.
                return $result->setData(['success' => false, 'message' => 'No extension attributes']);
            }

            $ext->setPigeonexpressDeliveryType($deliveryType);
            $ext->setPigeonexpressLocationId($locationId);
            $ext->setPigeonexpressLocationName($locationName);
            $ext->setPigeonexpressLocationAddress($locationAddress);
            $ext->setPigeonexpressInstructions($instructions);
            $address->setExtensionAttributes($ext);

            // Apply real city/postcode from the selected location when available.
            if ($locationCity !== '') {
                $address->setCity($locationCity);
            }
            if ($locationPostcode !== '') {
                $address->setPostcode($locationPostcode);
            }

            // Persist in our PE quote table when we already have an address id.
            if ($address->getId()) {
                $this->locationPersistor->save(
                    (int) $address->getId(),
                    $deliveryType,
                    $locationId,
                    $locationName,
                    $locationAddress,
                    $instructions
                );
            }

            // Save quote so that subsequent RateRequest / collectRates can see the location.
            $quote->save();

            $this->logger->info('[PE SetLocation] Updated location on quote', [
                'quote_id' => $quote->getId(),
                'address_id' => $address->getId(),
                'delivery_type' => $deliveryType,
                'location_id' => $locationId,
            ]);

            return $result->setData(['success' => true]);
        } catch (\Throwable $e) {
            $this->logger->error('[PE SetLocation] Error: ' . $e->getMessage());
            return $result->setData(['success' => false, 'message' => 'Error']);
        }
    }
}

