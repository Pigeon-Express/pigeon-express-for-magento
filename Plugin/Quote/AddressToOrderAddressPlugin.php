<?php
/**
 * Copy Pigeon Express delivery/location from quote address to order address on place order.
 * Connection: quote (extension_attributes) → order address (extension_attributes).
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Plugin\Quote;

use Magento\Quote\Model\Quote\Address\ToOrderAddress;
use Magento\Quote\Api\Data\AddressInterface as QuoteAddressInterface;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Api\Data\OrderAddressExtensionInterfaceFactory;
use PigeonExpress\Shipping\Api\ConfigInterface;

class AddressToOrderAddressPlugin
{
    /**
     * @var OrderAddressExtensionInterfaceFactory
     */
    private $orderAddressExtensionFactory;

    public function __construct(OrderAddressExtensionInterfaceFactory $orderAddressExtensionFactory)
    {
        $this->orderAddressExtensionFactory = $orderAddressExtensionFactory;
    }

    /**
     * After converting quote address to order address, copy Pigeon Express extension attributes.
     *
     * @param ToOrderAddress $subject
     * @param OrderAddressInterface $result
     * @param QuoteAddressInterface $quoteAddress
     * @param array $data
     * @return OrderAddressInterface
     */
    public function afterConvert(
        ToOrderAddress $subject,
        OrderAddressInterface $result,
        QuoteAddressInterface $quoteAddress,
        array $data = []
    ): OrderAddressInterface {
        $quoteExt = $quoteAddress->getExtensionAttributes();
        if (!$quoteExt) {
            return $result;
        }

        $orderExt = $result->getExtensionAttributes();
        if ($orderExt === null) {
            $orderExt = $this->orderAddressExtensionFactory->create();
            $result->setExtensionAttributes($orderExt);
        }

        $orderExt->setPigeonexpressDeliveryType($quoteExt->getPigeonexpressDeliveryType());
        $orderExt->setPigeonexpressLocationId($quoteExt->getPigeonexpressLocationId());
        $orderExt->setPigeonexpressLocationName($quoteExt->getPigeonexpressLocationName());
        $orderExt->setPigeonexpressLocationAddress($quoteExt->getPigeonexpressLocationAddress());
        $orderExt->setPigeonexpressInstructions($quoteExt->getPigeonexpressInstructions());

        $deliveryPrice = $quoteExt->getPigeonexpressDeliveryPrice();
        if ($deliveryPrice === null) {
            $method = $quoteAddress->getShippingMethod();
            if ($method && strpos($method, ConfigInterface::CARRIER_CODE . '_') === 0) {
                $deliveryPrice = $quoteAddress->getShippingAmount();
                if ($deliveryPrice !== null) {
                    $deliveryPrice = (float) $deliveryPrice;
                }
            }
        }
        $orderExt->setPigeonexpressDeliveryPrice($deliveryPrice);

        return $result;
    }
}
