<?php
/**
 * Cities, for the checkout pickers.
 *
 * Serves two callers with one endpoint, told apart by `type`:
 *
 *   office, aps   only cities that actually have somewhere to collect from.
 *                 The country has 5284 settlements and 91 of them have an
 *                 office, so offering the rest sends people to an empty list.
 *
 *   anything else every city, for the address form, where the parcel is going
 *                 to a door rather than to a counter.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Controller\Checkout;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Api\LocationSearchInterface;
use PigeonExpress\Shipping\Model\Api\AddressResolver;

class CitySearch implements HttpGetActionInterface
{
    private const LIMIT = 15;

    /** @var RequestInterface */
    private $request;

    /** @var JsonFactory */
    private $resultJsonFactory;

    /** @var LocationSearchInterface */
    private $locationSearch;

    /** @var AddressResolver */
    private $addressResolver;

    public function __construct(
        RequestInterface $request,
        JsonFactory $resultJsonFactory,
        LocationSearchInterface $locationSearch,
        AddressResolver $addressResolver
    ) {
        $this->request = $request;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->locationSearch = $locationSearch;
        $this->addressResolver = $addressResolver;
    }

    public function execute(): Json
    {
        /** @var Json $result */
        $result = $this->resultJsonFactory->create();

        $query = trim((string) $this->request->getParam('q', ''));
        if ($query === '') {
            return $result->setData(['items' => []]);
        }

        $type = (string) $this->request->getParam('type', '');

        $items = $type === ConfigInterface::DELIVERY_TYPE_OFFICE || $type === ConfigInterface::DELIVERY_TYPE_APS
            ? $this->locationSearch->searchCitiesWithLocations($type, $query, self::LIMIT)
            : $this->addressResolver->searchCities($query, self::LIMIT);

        return $result->setData(['items' => $items]);
    }
}
