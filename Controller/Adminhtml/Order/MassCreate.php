<?php
/**
 * Sales > Orders mass action "Create Pigeon Express shipments".
 * See Model\Magento\Grid\MassCreate.
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
use PigeonExpress\Shipping\Model\Magento\Grid\MassCreate as MassCreateService;
use Psr\Log\LoggerInterface;

class MassCreate extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'PigeonExpress_Shipping::send_shipment';

    /** @var Filter */
    private $filter;

    /** @var CollectionFactory */
    private $collectionFactory;

    /** @var MassCreateService */
    private $massCreate;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        Context $context,
        Filter $filter,
        CollectionFactory $collectionFactory,
        MassCreateService $massCreate,
        LoggerInterface $logger
    ) {
        $this->filter = $filter;
        $this->collectionFactory = $collectionFactory;
        $this->massCreate = $massCreate;
        $this->logger = $logger;
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        try {
            $collection = $this->filter->getCollection($this->collectionFactory->create());
            $summary = $this->massCreate->run($collection->getItems());
            $text = $this->massCreate->summaryText($summary);

            if ($summary['failed'] === []) {
                $this->messageManager->addSuccessMessage($text);
            } elseif ($summary['created'] > 0) {
                $this->messageManager->addWarningMessage($text);
            } else {
                $this->messageManager->addErrorMessage($text);
            }
        } catch (\Throwable $e) {
            $this->logger->error('[PigeonExpress] Mass create: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(
                __('Could not create the Pigeon Express shipments. Check the log for details.')
            );
        }

        return $this->resultRedirectFactory->create()->setPath('sales/order/index');
    }
}
