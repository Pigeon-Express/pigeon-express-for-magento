<?php
/**
 * Stream the Pigeon Express shipping label for an order.
 *
 * The label is fetched from the API on demand rather than stored. It is a
 * derived document that Pigeon Express can regenerate at any time, and keeping a
 * copy would mean serving a stale one after a shipment changes.
 *
 * Opening without `download` renders the PDF inline, which is the preview. With
 * `download` the browser saves it. ZPL is always an attachment, since it is a
 * printer program rather than something to look at.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PigeonExpress\Shipping\Api\ShipmentClientInterface;
use PigeonExpress\Shipping\Model\ShipmentCanceller;
use Psr\Log\LoggerInterface;

class Label extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'PigeonExpress_Shipping::print_label';

    /** Raw ZPL for a thermal printer, never a document to look at. */
    private const FORMAT_ZPL = 'zpl300';

    /** Formats the API accepts. Anything else is ignored in favour of default. */
    private const ALLOWED_FORMATS = ['default', 'pdf150', self::FORMAT_ZPL, 'a4'];

    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var ShipmentClientInterface */
    private $shipmentClient;

    /** @var ShipmentCanceller */
    private $shipmentFinder;

    /** @var RawFactory */
    private $rawFactory;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        Context $context,
        OrderRepositoryInterface $orderRepository,
        ShipmentClientInterface $shipmentClient,
        ShipmentCanceller $shipmentFinder,
        RawFactory $rawFactory,
        LoggerInterface $logger
    ) {
        $this->orderRepository = $orderRepository;
        $this->shipmentClient = $shipmentClient;
        $this->shipmentFinder = $shipmentFinder;
        $this->rawFactory = $rawFactory;
        $this->logger = $logger;
        parent::__construct($context);
    }

    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed(self::ADMIN_RESOURCE);
    }

    public function execute(): ResultInterface
    {
        $orderId = (int) $this->getRequest()->getParam('order_id');

        try {
            $order = $this->orderRepository->get($orderId);
            $shipment = $this->shipmentFinder->findShipment($orderId);

            if ($shipment === null || (string) $shipment->getReferenceNumber() === '') {
                throw new \RuntimeException('no shipment');
            }

            $format = $this->resolveFormat();
            $download = (bool) $this->getRequest()->getParam('download');

            $label = $this->shipmentClient->label(
                (string) $shipment->getReferenceNumber(),
                $format,
                $download,
                (int) $order->getStoreId()
            );

            // Everything is streamed straight back rather than going through
            // FileFactory. That helper returns a ResponseInterface, which is not
            // a ResultInterface, so returning it from here was a type error on
            // every download; and it writes the label into var/ on the way past,
            // leaving a copy of each one on disk for no reason.
            //
            // ZPL is a printer program rather than a document, so it is always an
            // attachment however it was asked for.
            $attachment = $download || $format === self::FORMAT_ZPL;

            /** @var Raw $raw */
            $raw = $this->rawFactory->create();
            $raw->setHeader('Content-Type', $label['content_type'], true);
            $raw->setHeader(
                'Content-Disposition',
                ($attachment ? 'attachment' : 'inline') . '; filename="' . $label['filename'] . '"',
                true
            );
            $raw->setHeader('Content-Length', (string) strlen($label['body']), true);
            $raw->setContents($label['body']);

            return $raw;
        } catch (\Throwable $e) {
            $this->logger->error('[PigeonExpress] Label controller error: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(
                __('Could not load the Pigeon Express label. Check the log for details.')
            );

            /** @var Redirect $redirect */
            $redirect = $this->resultRedirectFactory->create();

            return $orderId > 0
                ? $redirect->setPath('sales/order/view', ['order_id' => $orderId])
                : $redirect->setPath('sales/order/index');
        }
    }

    /**
     * Requested label format, restricted to what the API documents.
     */
    private function resolveFormat(): string
    {
        $format = (string) $this->getRequest()->getParam('format', 'default');

        return in_array($format, self::ALLOWED_FORMATS, true) ? $format : 'default';
    }
}
