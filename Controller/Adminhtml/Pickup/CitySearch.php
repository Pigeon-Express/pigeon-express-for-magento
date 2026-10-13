<?php
/**
 * Cities for the pickup address picker in the carrier configuration.
 *
 * Same local table as the shipment form's picker, but guarded by the
 * configuration permission: whoever may set the pickup address may search for
 * it, whether or not they may also send shipments.
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

class CitySearch extends Action implements HttpGetActionInterface
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

        $query = trim((string) $this->getRequest()->getParam('q', ''));
        if ($query === '') {
            return $result->setData(['items' => []]);
        }

        return $result->setData(['items' => $this->addressResolver->searchCities($query, self::LIMIT)]);
    }
}
