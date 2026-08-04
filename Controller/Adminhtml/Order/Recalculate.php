<?php
/**
 * Price the shipment the admin is currently describing.
 *
 * Redirecting a parcel to an office costs something different from carrying it
 * to a door, and a merchant agreeing to that on the phone should be able to see
 * the difference before they agree, not on the next invoice.
 *
 * Read only. The order keeps whatever the customer was quoted at checkout.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Api\FreeShippingInterface;
use PigeonExpress\Shipping\Api\ShipmentSenderInterface;
use PigeonExpress\Shipping\Model\Shipment\AddressForm;
use Psr\Log\LoggerInterface;

class Recalculate extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'PigeonExpress_Shipping::send_shipment';

    /** @var JsonFactory */
    private $resultJsonFactory;

    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var ShipmentSenderInterface */
    private $shipmentSender;

    /** @var AddressForm */
    private $addressForm;

    /** @var LoggerInterface */
    private $logger;

    /** @var FreeShippingInterface */
    private $freeShipping;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        OrderRepositoryInterface $orderRepository,
        ShipmentSenderInterface $shipmentSender,
        AddressForm $addressForm,
        LoggerInterface $logger,
        FreeShippingInterface $freeShipping
    ) {
        $this->resultJsonFactory = $resultJsonFactory;
        $this->orderRepository = $orderRepository;
        $this->shipmentSender = $shipmentSender;
        $this->addressForm = $addressForm;
        $this->logger = $logger;
        $this->freeShipping = $freeShipping;
        parent::__construct($context);
    }

    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed(self::ADMIN_RESOURCE);
    }

    public function execute(): Json
    {
        /** @var Json $result */
        $result = $this->resultJsonFactory->create();
        $orderId = (int) $this->getRequest()->getParam('order_id');

        try {
            $order = $this->orderRepository->get($orderId);
        } catch (\Throwable $e) {
            return $result->setData(['error' => (string) __('Order not found.')]);
        }

        $submitted = $this->getRequest()->getParam('pe');
        $fields = $this->addressForm->fromRequest(is_array($submitted) ? $submitted : []);
        $checked = $this->addressForm->validate(
            $fields,
            (int) $order->getStoreId(),
            $this->freeShipping->wasAppliedToOrder($order)
        );

        // A price for an address the carrier cannot resolve would be a guess, so
        // the admin is told what to fix instead of being given a number.
        if ($this->addressForm->hasErrors($checked['issues'])) {
            return $result->setData(['error' => $this->addressForm->firstError($checked['issues'])]);
        }

        try {
            $quote = $this->shipmentSender->priceFor($order, $checked['fields']);
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[PigeonExpress] Recalculate failed for order #' . $orderId . ': ' . $e->getMessage()
            );

            return $result->setData([
                'error' => (string) __('Pigeon Express could not price this shipment. For further assistance, please contact our customer service.'),
            ]);
        }

        // Split by the side the form currently bills. The carrier charges one or
        // the other, never both, so the side that is not billed comes back as
        // zero rather than being left out, matching the stored rows above it.
        $whoPays = (string) ($checked['fields']['who_pays'] ?? '');
        $price = (float) $quote['price'];

        return $result->setData([
            'price'              => $quote['price'],
            'formatted'          => $order->formatPrice($price),
            'who_pays'           => $whoPays,
            'sender_formatted'   => $order->formatPrice(
                $whoPays === ConfigInterface::WHO_PAYS_SENDER ? $price : 0.0
            ),
            'receiver_formatted' => $order->formatPrice(
                $whoPays === ConfigInterface::WHO_PAYS_RECEIVER ? $price : 0.0
            ),
        ]);
    }
}
