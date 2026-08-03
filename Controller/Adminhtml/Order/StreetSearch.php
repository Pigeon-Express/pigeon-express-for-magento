<?php
/**
 * Streets for the shipment form's street picker.
 *
 * Streets are not synced locally. They exist only inside a city, and the carrier
 * holds far more of them than a store would ever need, so they are fetched from
 * the API for the chosen city and cached by AddressResolver.
 *
 * A failure here is reported rather than swallowed: the form then tells the
 * admin to type the street by hand, which still ships, instead of showing an
 * empty list that looks like "no such street".
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
use PigeonExpress\Shipping\Model\Api\AddressResolver;

class StreetSearch extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'PigeonExpress_Shipping::send_shipment';

    private const LIMIT = 15;

    /** @var JsonFactory */
    private $resultJsonFactory;

    /** @var AddressResolver */
    private $addressResolver;

    /** @var OrderRepositoryInterface */
    private $orderRepository;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        AddressResolver $addressResolver,
        OrderRepositoryInterface $orderRepository
    ) {
        $this->resultJsonFactory = $resultJsonFactory;
        $this->addressResolver = $addressResolver;
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

        $cityId = (int) $this->getRequest()->getParam('city_id');
        $query = trim((string) $this->getRequest()->getParam('q', ''));

        if ($cityId <= 0 || mb_strlen($query) < AddressResolver::MIN_SEARCH_LENGTH) {
            return $result->setData(['items' => []]);
        }

        try {
            $items = $this->addressResolver->searchStreets($cityId, $query, $this->resolveStoreId(), self::LIMIT);
        } catch (\Throwable $e) {
            return $result->setData([
                'items' => [],
                'error' => (string) __('The Pigeon Express street list is unavailable. Type the street by hand.'),
            ]);
        }

        return $result->setData(['items' => $items]);
    }

    /**
     * Store of the order being shipped, so test mode and credentials match.
     */
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
