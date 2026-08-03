<?php
/**
 * Streets in a city, for the checkout address form.
 *
 * Unlike the city list this one reaches the Pigeon Express API, so the guards
 * matter: below the minimum query length nothing is sent, the result count is
 * bounded, and with the address override switched off the endpoint answers
 * nothing at all rather than remaining a way to reach a metered third party.
 * AddressResolver caches each city and query for an hour.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Controller\Checkout;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\StoreManagerInterface;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\Api\AddressResolver;

class StreetSearch implements HttpGetActionInterface
{
    private const LIMIT = 15;

    /** @var RequestInterface */
    private $request;

    /** @var JsonFactory */
    private $resultJsonFactory;

    /** @var AddressResolver */
    private $addressResolver;

    /** @var ConfigInterface */
    private $config;

    /** @var StoreManagerInterface */
    private $storeManager;

    public function __construct(
        RequestInterface $request,
        JsonFactory $resultJsonFactory,
        AddressResolver $addressResolver,
        ConfigInterface $config,
        StoreManagerInterface $storeManager
    ) {
        $this->request = $request;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->addressResolver = $addressResolver;
        $this->config = $config;
        $this->storeManager = $storeManager;
    }

    public function execute(): Json
    {
        /** @var Json $result */
        $result = $this->resultJsonFactory->create();
        $storeId = (int) $this->storeManager->getStore()->getId();

        if (!$this->config->isOverrideAddressFields($storeId)) {
            return $result->setData(['items' => []]);
        }

        $cityId = (int) $this->request->getParam('city_id');
        $query = trim((string) $this->request->getParam('q', ''));

        if ($cityId <= 0 || mb_strlen($query) < AddressResolver::MIN_SEARCH_LENGTH) {
            return $result->setData(['items' => []]);
        }

        try {
            $items = $this->addressResolver->searchStreets($cityId, $query, $storeId, self::LIMIT);
        } catch (\Throwable $e) {
            // Typing the street by hand still ships, so the customer is told
            // that rather than being shown an empty list that reads as "no such
            // street".
            return $result->setData([
                'items' => [],
                'error' => (string) __('The street list is unavailable. Type the street name instead.'),
            ]);
        }

        return $result->setData(['items' => $items]);
    }
}
