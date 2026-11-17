<?php
/**
 * Sales > Orders mass action "Download Pigeon Express labels (ZIP)".
 * See Model\Magento\Grid\LabelZip.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Filesystem;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Ui\Component\MassAction\Filter;
use PigeonExpress\Shipping\Model\Magento\Grid\LabelZip;
use Psr\Log\LoggerInterface;

class MassLabels extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'PigeonExpress_Shipping::print_label';

    /** @var Filter */
    private $filter;

    /** @var CollectionFactory */
    private $collectionFactory;

    /** @var LabelZip */
    private $labelZip;

    /** @var RawFactory */
    private $rawFactory;

    /** @var Filesystem */
    private $filesystem;

    /** @var DateTime */
    private $dateTime;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        Context $context,
        Filter $filter,
        CollectionFactory $collectionFactory,
        LabelZip $labelZip,
        RawFactory $rawFactory,
        Filesystem $filesystem,
        DateTime $dateTime,
        LoggerInterface $logger
    ) {
        $this->filter = $filter;
        $this->collectionFactory = $collectionFactory;
        $this->labelZip = $labelZip;
        $this->rawFactory = $rawFactory;
        $this->filesystem = $filesystem;
        $this->dateTime = $dateTime;
        $this->logger = $logger;
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->messageManager->addErrorMessage(__('PHP ZipArchive is not available on this server.'));

            return $this->back();
        }

        $tmp = $this->filesystem->getDirectoryWrite(DirectoryList::TMP);
        $relative = 'pigeonexpress-labels-' . bin2hex(random_bytes(8)) . '.zip';

        try {
            $tmp->create();
            $collection = $this->filter->getCollection($this->collectionFactory->create());
            $result = $this->labelZip->build($collection->getItems(), $tmp->getAbsolutePath($relative));

            $this->reportLeftOut($result['missing'], $result['over']);

            if ($result['count'] === 0 || !$tmp->isExist($relative)) {
                if ($result['missing'] === [] && $result['over'] === []) {
                    $this->messageManager->addErrorMessage(
                        __('None of the selected orders has a Pigeon Express shipment.')
                    );
                }

                return $this->back();
            }

            $body = $tmp->readFile($relative);

            /** @var Raw $raw */
            $raw = $this->rawFactory->create();
            $raw->setHttpResponseCode(200);
            $raw->setHeader('Content-Type', 'application/zip', true);
            $raw->setHeader(
                'Content-Disposition',
                'attachment; filename="pigeon-labels-' . $this->dateTime->gmtDate('Y-m-d') . '.zip"',
                true
            );
            $raw->setHeader('Content-Length', (string) strlen($body), true);
            $raw->setHeader('Cache-Control', 'no-store', true);
            $raw->setContents($body);

            return $raw;
        } catch (\Throwable $e) {
            $this->logger->error('[PigeonExpress] Mass labels: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(
                __('Could not load the Pigeon Express label. Check the log for details.')
            );

            return $this->back();
        } finally {
            if ($tmp->isExist($relative)) {
                $tmp->delete($relative);
            }
        }
    }

    /**
     * Shown on the orders grid once back there (after the download, the next
     * page the admin opens).
     *
     * @param string[] $missing
     * @param string[] $over
     */
    private function reportLeftOut(array $missing, array $over): void
    {
        if ($missing !== []) {
            $this->messageManager->addErrorMessage(__(
                'Pigeon Express did not send the label of these orders now, so they are not in the ZIP: %1. '
                . 'Try again in a minute.',
                '#' . implode(', #', $missing)
            ));
        }

        if ($over !== []) {
            $this->messageManager->addWarningMessage(__(
                'Only %1 labels are downloaded at a time, so these orders are not in the ZIP: %2. '
                . 'Select them again to download theirs.',
                LabelZip::MAX_FETCHES,
                '#' . implode(', #', $over)
            ));
        }
    }

    private function back(): ResultInterface
    {
        return $this->resultRedirectFactory->create()->setPath('sales/order/index');
    }
}
