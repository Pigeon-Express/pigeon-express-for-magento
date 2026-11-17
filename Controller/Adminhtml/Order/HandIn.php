<?php
/**
 * Where the shipment the admin is describing would be handed in: the parcel
 * locker, or the fallback office and why.
 *
 * Asked again whenever the package rows change, so the merchant sees before
 * creating the shipment whether it fits the locker. Read only, and local: the
 * carrier is not asked.
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
use PigeonExpress\Shipping\Model\Shipment\AddressForm;
use PigeonExpress\Shipping\Model\Shipment\HandInText;

class HandIn extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'PigeonExpress_Shipping::hand_in_point';

    /** @var JsonFactory */
    private $resultJsonFactory;

    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var AddressForm */
    private $addressForm;

    /** @var HandInText */
    private $handInText;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        OrderRepositoryInterface $orderRepository,
        AddressForm $addressForm,
        HandInText $handInText
    ) {
        $this->resultJsonFactory = $resultJsonFactory;
        $this->orderRepository = $orderRepository;
        $this->addressForm = $addressForm;
        $this->handInText = $handInText;
        parent::__construct($context);
    }

    public function execute(): Json
    {
        /** @var Json $result */
        $result = $this->resultJsonFactory->create();

        try {
            $order = $this->orderRepository->get((int) $this->getRequest()->getParam('order_id'));
        } catch (\Throwable $e) {
            return $result->setData(['error' => (string) __('Order not found.')]);
        }

        $storeId = (int) $order->getStoreId();
        $submitted = $this->getRequest()->getParam('pe');
        $fields = $this->addressForm->fromRequest(is_array($submitted) ? $submitted : []);

        // Only the packages matter here, so whatever else is wrong with the
        // form is left for Send to report.
        $checked = $this->addressForm->validatePackages($fields, $storeId);
        if (isset($checked['issues']['packages'])) {
            return $result->setData([
                'hand_in' => '',
                'error'   => (string) $checked['issues']['packages']['message'],
            ]);
        }

        return $result->setData([
            'hand_in' => $this->handInText->describe($checked['fields'], $storeId),
        ]);
    }
}
