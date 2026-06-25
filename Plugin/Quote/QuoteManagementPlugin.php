<?php
/**
 * Validate Pigeon Express delivery location and phone before placing the order.
 * When shipping method is office or APS, quote shipping address must have
 * location (pigeonexpress_location_id) and telephone.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Plugin\Quote;

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteManagement;
use PigeonExpress\Shipping\Api\ConfigInterface;
use Psr\Log\LoggerInterface;

class QuoteManagementPlugin
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Before submitting the quote to order, require Pigeon Express location and phone when needed.
     *
     * @param QuoteManagement $subject
     * @param Quote $quote
     * @param array $orderData
     * @return array
     * @throws LocalizedException
     */
    public function beforeSubmit(QuoteManagement $subject, Quote $quote, $orderData = []): array
    {
        // Force totals recollection so COD fee is always applied with the correct payment method.
        try {
            $quote->setTotalsCollectedFlag(false)->collectTotals();
        } catch (\Throwable $e) {
            $this->logger->warning('[PE PlaceOrder] collectTotals failed: ' . $e->getMessage());
        }

        $address = $quote->getShippingAddress();
        if (!$address) {
            $this->logger->info('[PE PlaceOrder] No shipping address', ['quote_id' => $quote->getId()]);
            return [$quote, $orderData];
        }

        $method = $address->getShippingMethod();
        if ($method === null || $method === '') {
            $this->logger->info('[PE PlaceOrder] No shipping method', ['quote_id' => $quote->getId()]);
            return [$quote, $orderData];
        }

        $prefix = ConfigInterface::CARRIER_CODE . '_';
        if (strpos($method, $prefix) !== 0) {
            return [$quote, $orderData];
        }

        $methodCode = substr($method, strlen($prefix));
        $isOfficeOrAps = ($methodCode === ConfigInterface::DELIVERY_TYPE_OFFICE || $methodCode === ConfigInterface::DELIVERY_TYPE_APS);
        
        $ext = $address->getExtensionAttributes();

        if ($isOfficeOrAps) {
            $locationId = $ext && $ext->getPigeonexpressLocationId() !== null
                ? trim((string) $ext->getPigeonexpressLocationId())
                : '';

            $this->logger->info('[PE PlaceOrder] beforeSubmit', [
                'quote_id' => $quote->getId(),
                'address_id' => $address->getId(),
                'shipping_method' => $method,
                'ext_is_null' => $ext === null,
                'location_id' => $locationId,
                'location_id_raw' => $ext ? $ext->getPigeonexpressLocationId() : null,
            ]);

            if ($locationId === '') {
                $this->logger->info('[PE PlaceOrder] Missing location_id, throwing', ['quote_id' => $quote->getId()]);
                throw new LocalizedException(__('Please select a delivery location.'));
            }
        }

        $telephone = $address->getTelephone();
        if ($telephone === null || trim((string) $telephone) === '') {
            throw new LocalizedException(__('Phone number is required for Pigeon Express delivery.'));
        }
        if (!preg_match('/^[\d\s\-\+\(\)]{5,25}$/', trim((string) $telephone))) {
            throw new LocalizedException(__('Please enter a valid phone number.'));
        }

        return [$quote, $orderData];
    }
}
