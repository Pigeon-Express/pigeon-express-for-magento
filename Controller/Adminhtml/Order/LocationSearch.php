<?php
/**
 * Offices and parcel lockers for the shipment form's pickup point picker.
 *
 * Served from the local tables the nightly sync fills, which is the same source
 * checkout searches, so the admin and the customer are choosing from one list.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Api\LocationSearchInterface;

class LocationSearch extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'PigeonExpress_Shipping::send_shipment';

    private const LIMIT = 15;

    /** @var JsonFactory */
    private $resultJsonFactory;

    /** @var LocationSearchInterface */
    private $locationSearch;

    /** @var OrderRepositoryInterface */
    private $orderRepository;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        LocationSearchInterface $locationSearch,
        OrderRepositoryInterface $orderRepository
    ) {
        $this->resultJsonFactory = $resultJsonFactory;
        $this->locationSearch = $locationSearch;
        $this->orderRepository = $orderRepository;
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

        $type = (string) $this->getRequest()->getParam('type', '');
        $query = trim((string) $this->getRequest()->getParam('q', ''));

        if ($query === ''
            || ($type !== ConfigInterface::DELIVERY_TYPE_OFFICE && $type !== ConfigInterface::DELIVERY_TYPE_APS)
        ) {
            return $result->setData(['items' => []]);
        }

        $items = [];
        foreach ($this->locationSearch->search($type, $query, $this->resolveStoreId(), self::LIMIT) as $row) {
            $name = trim((string) $row['name'] . ', ' . (string) $row['city'], ' ,');
            $items[] = [
                'id'    => (int) $row['id'],
                'name'  => $name,
                // The address is what tells two counters in one town apart.
                'label' => trim($name . ' — ' . (string) $row['address'], ' —'),
            ];
        }

        return $result->setData(['items' => $items]);
    }

    private function resolveStoreId(): int
    {
        $orderId = (int) $this->getRequest()->getParam('order_id');
        if ($orderId <= 0) {
            return 0;
        }

        try {
            return (int) $this->orderRepository->get($orderId)->getStoreId();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
