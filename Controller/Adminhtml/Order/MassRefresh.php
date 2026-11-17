<?php
/**
 * Sales > Orders mass action "Refresh Pigeon Express shipment status": reads
 * the status of the selected orders' shipments now, in bulk requests.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Ui\Component\MassAction\Filter;
use PigeonExpress\Shipping\Model\Api\ShipmentClient;
use PigeonExpress\Shipping\Model\Status\StatusRefresher;
use Psr\Log\LoggerInterface;

class MassRefresh extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'PigeonExpress_Shipping::refresh_status';

    /** Shipments read per click: one bulk request, as in WooCommerce. */
    public const MAX_SHIPMENTS = ShipmentClient::TRACK_BULK_MAX;

    /** @var Filter */
    private $filter;

    /** @var CollectionFactory */
    private $collectionFactory;

    /** @var StatusRefresher */
    private $refresher;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        Context $context,
        Filter $filter,
        CollectionFactory $collectionFactory,
        StatusRefresher $refresher,
        LoggerInterface $logger
    ) {
        $this->filter = $filter;
        $this->collectionFactory = $collectionFactory;
        $this->refresher = $refresher;
        $this->logger = $logger;
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        try {
            $collection = $this->filter->getCollection($this->collectionFactory->create());
            $ids = array_map('intval', $collection->getAllIds());
            $result = $this->refresher->refreshOrders($ids, self::MAX_SHIPMENTS);
            $leftOut = (int) ($result['left_out'] ?? 0);
            $total = $result['updated'] + $result['unchanged'] + $result['failed'];

            if ($total === 0) {
                $this->messageManager->addErrorMessage(
                    __('None of the selected orders has a Pigeon Express shipment.')
                );
            } else {
                $text = __(
                    'Pigeon Express shipment status: %1 updated, %2 unchanged, %3 failed, %4 without a shipment.',
                    $result['updated'],
                    $result['unchanged'],
                    $result['failed'],
                    max(0, count($ids) - $total - $leftOut)
                );
                if ($leftOut > 0) {
                    $text = __(
                        '%1 Only %2 shipments are refreshed at a time, so %3 were left out. '
                        . 'Select them again to refresh them.',
                        (string) $text,
                        self::MAX_SHIPMENTS,
                        $leftOut
                    );
                }
                $result['failed'] > 0 || $leftOut > 0
                    ? $this->messageManager->addWarningMessage($text)
                    : $this->messageManager->addSuccessMessage($text);
            }
        } catch (\Throwable $e) {
            $this->logger->error('[PigeonExpress] Mass refresh: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(__(
                'Pigeon Express shipment status could not be refreshed: %1',
                $e->getMessage()
            ));
        }

        return $this->resultRedirectFactory->create()->setPath('sales/order/index');
    }
}
