<?php
/**
 * Validate Pigeon Express requirements before saving shipping information.
 * We do NOT require the location here: it is filled after the user selects
 * the delivery method, and is checked at place order (QuoteManagementPlugin).
 *
 * The phone is checked here, server side, for Pigeon Express methods: the
 * carrier needs a Bulgarian number to deliver, and the customer is told on
 * the shipping step, before any order exists, as in WooCommerce. The client
 * side check stays; this one cannot be skipped by a script or another
 * checkout.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Plugin\Checkout;

use Magento\Checkout\Api\Data\ShippingInformationInterface;
use Magento\Checkout\Model\ShippingInformationManagement;
use Magento\Framework\Exception\InputException;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\Phone\BulgarianPhone;
use Psr\Log\LoggerInterface;

class ShippingInformationManagementValidationPlugin
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
     * Before saving shipping information. No strict validation here so the user
     * can select the delivery method first; location and phone are validated at place order.
     *
     * @param ShippingInformationManagement $subject
     * @param int|string $cartId
     * @param ShippingInformationInterface $addressInformation
     * @return array|null
     */
    public function beforeSaveAddressInformation(
        ShippingInformationManagement $subject,
        $cartId,
        ShippingInformationInterface $addressInformation
    ): ?array {
        $ext = $addressInformation->getExtensionAttributes();
        $this->logger->info('[PE SaveAddressInfo] beforeSaveAddressInformation (incoming request)', [
            'cart_id' => $cartId,
            'ext_is_null' => $ext === null,
            'location_id' => $ext ? $ext->getPigeonexpressLocationId() : null,
        ]);

        if ((string) $addressInformation->getShippingCarrierCode() === ConfigInterface::CARRIER_CODE) {
            $address = $addressInformation->getShippingAddress();
            self::assertPhone($address !== null ? (string) $address->getTelephone() : '');
        }

        return null;
    }

    /**
     * Refuse a phone Pigeon Express cannot deliver with.
     *
     * @throws InputException
     */
    public static function assertPhone(string $telephone): void
    {
        if (trim($telephone) === '') {
            throw new InputException(__('Please enter a phone number. Pigeon Express needs it to deliver your order.'));
        }

        if (!BulgarianPhone::isValid($telephone)) {
            throw new InputException(__(
                'Please enter a valid Bulgarian phone number, for example 0881234567 or +359881234567. '
                . 'Pigeon Express needs it to deliver your order.'
            ));
        }
    }
}
