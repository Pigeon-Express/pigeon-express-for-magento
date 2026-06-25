<?php
/**
 * Checkout location autocomplete: returns Office or APS from local DB only (no external API).
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Controller\Checkout;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Store\Model\StoreManagerInterface;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Api\LocationSearchInterface;

class LocationAutocomplete implements HttpGetActionInterface
{
    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var LocationSearchInterface
     */
    private $locationSearch;

    /**
     * @var JsonFactory
     */
    private $resultJsonFactory;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    public function __construct(
        RequestInterface $request,
        LocationSearchInterface $locationSearch,
        JsonFactory $resultJsonFactory,
        StoreManagerInterface $storeManager
    ) {
        $this->request = $request;
        $this->locationSearch = $locationSearch;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->storeManager = $storeManager;
    }

    /**
     * Return JSON array of locations for autocomplete (local DB only).
     * Query params: type = office|aps, q = search query.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $type = (string) $this->request->getParam('type', '');
        $query = (string) $this->request->getParam('q', '');
        $storeId = (int) $this->storeManager->getStore()->getId();

        if ($type !== ConfigInterface::DELIVERY_TYPE_OFFICE && $type !== ConfigInterface::DELIVERY_TYPE_APS) {
            return $this->resultJsonFactory->create()->setData([]);
        }

        $results = $this->locationSearch->search($type, $query, $storeId, 0);
        return $this->resultJsonFactory->create()->setData($results);
    }
}
