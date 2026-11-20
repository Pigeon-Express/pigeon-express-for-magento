<?php
/**
 * Every active office or parcel locker of a type that can go on the checkout
 * map ("Map of offices and lockers" setting), from the local tables only.
 *
 * Fetched by the picker once per type and page, when office or locker
 * delivery is chosen: an empty list means the "Choose on the map" button is
 * not shown (in the test environment no locker has a position yet).
 *
 * GET: type (office|aps).
 * Answers {success: true, locations: [picker row + lat, lng]} or
 * {success: false, message} with 400.
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

class MapLocations implements HttpGetActionInterface
{
    /** Decimals of a pin (five is about 1 m). */
    private const PIN_PRECISION = 5;

    /** Row keys the page gets: the picker row, then the pin. */
    private const ROW_KEYS = ['id', 'name', 'address', 'city', 'postcode', 'region_id', 'type', 'hours'];

    /** @var RequestInterface */
    private $request;

    /** @var JsonFactory */
    private $resultJsonFactory;

    /** @var ConfigInterface */
    private $config;

    /** @var StoreManagerInterface */
    private $storeManager;

    /** @var LocationSearchInterface */
    private $locationSearch;

    public function __construct(
        RequestInterface $request,
        JsonFactory $resultJsonFactory,
        ConfigInterface $config,
        StoreManagerInterface $storeManager,
        LocationSearchInterface $locationSearch
    ) {
        $this->request = $request;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->config = $config;
        $this->storeManager = $storeManager;
        $this->locationSearch = $locationSearch;
    }

    public function execute(): ResultInterface
    {
        $result = $this->resultJsonFactory->create();
        $storeId = (int) $this->storeManager->getStore()->getId();

        if (!$this->config->isLocationMapEnabled($storeId)) {
            $result->setHttpResponseCode(400);

            return $result->setData(['success' => false, 'message' => (string) __('Unknown request.')]);
        }

        $type = (string) $this->request->getParam('type', '');
        if ($type !== ConfigInterface::DELIVERY_TYPE_OFFICE && $type !== ConfigInterface::DELIVERY_TYPE_APS) {
            $result->setHttpResponseCode(400);

            return $result->setData(['success' => false, 'message' => (string) __('Unknown location type.')]);
        }

        $locations = [];
        foreach ($this->locationSearch->listWithCoordinates($type, $storeId) as $row) {
            if (!isset($row['latitude'], $row['longitude'])) {
                continue;
            }

            $item = [];
            foreach (self::ROW_KEYS as $key) {
                $item[$key] = $row[$key] ?? null;
            }
            $item['lat'] = round((float) $row['latitude'], self::PIN_PRECISION);
            $item['lng'] = round((float) $row['longitude'], self::PIN_PRECISION);
            $locations[] = $item;
        }

        return $result->setData(['success' => true, 'locations' => $locations]);
    }
}
