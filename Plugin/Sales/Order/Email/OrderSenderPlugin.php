<?php
/**
 * Use Pigeon Express order email template when order uses PE shipping.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Plugin\Sales\Order\Email;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Email\Container\Template;
use PigeonExpress\Shipping\Api\ConfigInterface;

class OrderSenderPlugin
{
    public const PE_ORDER_EMAIL_TEMPLATE = 'pigeonexpress_order_confirm';
    public const PE_ORDER_EMAIL_GUEST_TEMPLATE = 'pigeonexpress_order_confirm_guest';

    /**
     * After prepareTemplate: if order uses Pigeon Express, switch to our email template.
     */
    public function afterPrepareTemplate(OrderSender $subject, $result, Order $order): void
    {
        $method = $order->getShippingMethod();
        if (!$method || strpos((string) $method, ConfigInterface::CARRIER_CODE . '_') !== 0) {
            return;
        }

        $ref = new \ReflectionProperty(Sender::class, 'templateContainer');
        $ref->setAccessible(true);
        /** @var Template $container */
        $container = $ref->getValue($subject);
        if (!$container) {
            return;
        }

        $templateId = $order->getCustomerIsGuest()
            ? self::PE_ORDER_EMAIL_GUEST_TEMPLATE
            : self::PE_ORDER_EMAIL_TEMPLATE;
        $container->setTemplateId($templateId);
    }
}
