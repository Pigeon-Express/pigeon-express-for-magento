<?php
/**
 * Streets for the pickup address picker in the carrier configuration.
 *
 * Fetched from the API for the chosen city, using the credentials of the store
 * whose configuration is open, so a store view on test mode searches the test
 * environment. A failure is reported so the admin knows to type the street.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Controller\Adminhtml\Pickup;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use PigeonExpress\Shipping\Model\Api\AddressResolver;

class StreetSearch extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'PigeonExpress_Shipping::config_pigeonexpress';

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

    public function execute(): Json
    {
        /** @var Json $result */
        $result = $this->resultJsonFactory->create();

        $cityId = (int) $this->getRequest()->getParam('city_id');
        $query = trim((string) $this->getRequest()->getParam('q', ''));
        $storeId = max(0, (int) $this->getRequest()->getParam('store_id'));

        if ($cityId <= 0 || mb_strlen($query) < AddressResolver::MIN_SEARCH_LENGTH) {
            return $result->setData(['items' => []]);
        }

        try {
            $items = $this->addressResolver->searchStreets($cityId, $query, $storeId, self::LIMIT);
        } catch (\Throwable $e) {
            return $result->setData([
                'items' => [],
                'error' => (string) __('The Pigeon Express street list is unavailable. Type the street by hand.'),
            ]);
        }

        return $result->setData(['items' => $items]);
    }
}
