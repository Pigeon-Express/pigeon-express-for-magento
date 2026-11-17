<?php
/**
 * The label of the return shipment, inline or as a download.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Controller\Adminhtml\Reverse;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PigeonExpress\Shipping\Api\ShipmentClientInterface;
use PigeonExpress\Shipping\Model\ReturnShipment\ReturnShipmentRepository;
use PigeonExpress\Shipping\Model\ReturnShipment\ReturnShipmentService;
use Psr\Log\LoggerInterface;

class Label extends AbstractReverse implements HttpGetActionInterface
{
    /** @var ShipmentClientInterface */
    private $client;

    /** @var ReturnShipmentRepository */
    private $repository;

    /** @var RawFactory */
    private $rawFactory;

    public function __construct(
        Context $context,
        OrderRepositoryInterface $orderRepository,
        ReturnShipmentService $service,
        LoggerInterface $logger,
        ShipmentClientInterface $client,
        ReturnShipmentRepository $repository,
        RawFactory $rawFactory
    ) {
        $this->client = $client;
        $this->repository = $repository;
        $this->rawFactory = $rawFactory;
        parent::__construct($context, $orderRepository, $service, $logger);
    }

    public function execute(): ResultInterface
    {
        $order = $this->loadOrder();
        $row = $order ? $this->repository->findActive((int) $order->getEntityId()) : null;

        if ($row === null) {
            $this->messageManager->addErrorMessage(__('This order has no return shipment.'));

            return $this->back($order);
        }

        $reference = (string) $row['reference_number'];
        $download = (bool) $this->getRequest()->getParam('download');

        try {
            $label = $this->client->label($reference, 'default', $download, (int) $order->getStoreId());
        } catch (\Throwable $e) {
            $this->logger->error('[PigeonExpress] Return label failed: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(__('No Pigeon Express label is available for this order.'));

            return $this->back($order);
        }

        $raw = $this->rawFactory->create();
        $raw->setHeader('Content-Type', 'application/pdf', true);
        $raw->setHeader(
            'Content-Disposition',
            ($download ? 'attachment' : 'inline') . '; filename="pigeon-express-return-'
                . preg_replace('/[^A-Za-z0-9_\-]/', '', $reference) . '.pdf"',
            true
        );
        $raw->setHeader('Content-Length', (string) strlen($label['body']), true);
        $raw->setContents($label['body']);

        return $raw;
    }
}
