<?php
/**
 * Cities for the shipment form's city picker.
 *
 * Served from the local table, which the location sync keeps current, so typing
 * stays instant and does not depend on the carrier being reachable.
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
use PigeonExpress\Shipping\Model\Api\AddressResolver;

class CitySearch extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'PigeonExpress_Shipping::send_shipment';

    /**
     * A read-only lookup, so the change of delivery place in Quick changes
     * may use it too.
     */
    public const QUICK_CHANGE_RESOURCE = 'PigeonExpress_Shipping::quick_change';

    /** Enough to choose from without turning the list into a second problem. */
    private const LIMIT = 15;

    /** @var JsonFactory */
    private $resultJsonFactory;

    /** @var AddressResolver */
    private $addressResolver;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        AddressResolver $addressResolver
    ) {
        $this->resultJsonFactory = $resultJsonFactory;
        $this->addressResolver = $addressResolver;
        parent::__construct($context);
    }

    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed(self::ADMIN_RESOURCE)
            || $this->_authorization->isAllowed(self::QUICK_CHANGE_RESOURCE);
    }

    public function execute(): Json
    {
        /** @var Json $result */
        $result = $this->resultJsonFactory->create();

        $query = trim((string) $this->getRequest()->getParam('q', ''));
        if ($query === '') {
            return $result->setData(['items' => []]);
        }

        return $result->setData(['items' => $this->addressResolver->searchCities($query, self::LIMIT)]);
    }
}
