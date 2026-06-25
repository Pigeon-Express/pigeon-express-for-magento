<?php
/**
 * Validate Pigeon Express requirements before saving shipping information.
 * We do NOT require location or phone here: they are filled after the user selects
 * the delivery method. Validation for location and phone is done at place order
 * (QuoteManagementPlugin) so the checkout step can complete without them.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Plugin\Checkout;

use Magento\Checkout\Api\Data\ShippingInformationInterface;
use Magento\Checkout\Model\ShippingInformationManagement;
use PigeonExpress\Shipping\Api\ConfigInterface;
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
        return null;
    }
}
