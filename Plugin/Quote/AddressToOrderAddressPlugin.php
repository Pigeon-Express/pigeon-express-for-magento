<?php
/**
 * Copy Pigeon Express delivery/location from quote address to order address on place order.
 * Connection: quote (extension_attributes) → order address (extension_attributes).
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Plugin\Quote;

use Magento\Quote\Model\Quote\Address as QuoteAddressModel;
use Magento\Quote\Model\Quote\Address\ToOrderAddress;
use Magento\Quote\Api\Data\AddressInterface as QuoteAddressInterface;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Api\Data\OrderAddressExtensionInterfaceFactory;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Api\FreeShippingInterface;

class AddressToOrderAddressPlugin
{
    /**
     * @var OrderAddressExtensionInterfaceFactory
     */
    private $orderAddressExtensionFactory;

    /**
     * @var FreeShippingInterface
     */
    private $freeShipping;

    public function __construct(
        OrderAddressExtensionInterfaceFactory $orderAddressExtensionFactory,
        FreeShippingInterface $freeShipping
    ) {
        $this->orderAddressExtensionFactory = $orderAddressExtensionFactory;
        $this->freeShipping = $freeShipping;
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

        // Why the customer was not charged, recorded now while the quote still
        // says so. The shipment needs to know whether a zero was a giveaway, in
        // which case the merchant is billed, or a store on receiver pays quoting
        // zero on purpose and expecting the courier to collect at the door. The
        // amount alone cannot tell those apart, and the config it depends on can
        // change between the order and the waybill.
        $orderExt->setPigeonexpressFreeShipping(
            $quoteAddress instanceof QuoteAddressModel
            && $this->freeShipping->wasAppliedToQuoteAddress($quoteAddress) ? 1 : 0
        );

        return $result;
    }
}
