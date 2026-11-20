<?php
/**
 * The free boxes of the parcel locker picked in the shipment form, and
 * whether a box this parcel fits in is expected to be free tomorrow. The
 * carrier's answer is kept a few minutes per locker (Model\Shipment\LockerBoxes),
 * so a change of the parcel's size asks nothing new. Writes nothing.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Escaper;
use Magento\Framework\View\LayoutFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use PigeonExpress\Shipping\Block\Adminhtml\Order\LockerBoxes as LockerBoxesBlock;
use PigeonExpress\Shipping\Exception\ApiException;
use PigeonExpress\Shipping\Model\Shipment\LockerBoxes as LockerBoxesModel;
use Psr\Log\LoggerInterface;

class LockerBoxes extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'PigeonExpress_Shipping::send_shipment';

    /** @var JsonFactory */
    private $resultJsonFactory;

    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var LockerBoxesModel */
    private $lockerBoxes;

    /** @var LayoutFactory */
    private $layoutFactory;

    /** @var Escaper */
    private $escaper;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        OrderRepositoryInterface $orderRepository,
        LockerBoxesModel $lockerBoxes,
        LayoutFactory $layoutFactory,
        Escaper $escaper,
        LoggerInterface $logger
    ) {
        $this->resultJsonFactory = $resultJsonFactory;
        $this->orderRepository = $orderRepository;
        $this->lockerBoxes = $lockerBoxes;
        $this->layoutFactory = $layoutFactory;
        $this->escaper = $escaper;
        $this->logger = $logger;
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $result = $this->resultJsonFactory->create();
        $request = $this->getRequest();

        $lockerId = (int) preg_replace('/\D/', '', (string) $request->getParam('location_id', ''));
        if ($lockerId <= 0) {
            return $result->setData(['html' => '']);
        }

        // Decimals count: a parcel of 30.5 cm does not go into a 30 cm box.
        $number = static function ($value): float {
            $value = (float) str_replace(',', '.', is_scalar($value) ? (string) $value : '');

            return is_finite($value) ? max(0.0, $value) : 0.0;
        };
        $packages = max(0, (int) $request->getParam('packages', 0));
        $weight = $number($request->getParam('weight'));
        $sides = [
            $number($request->getParam('length')),
            $number($request->getParam('width')),
            $number($request->getParam('height')),
        ];

        try {
            $locker = $this->lockerBoxes->availability($lockerId, $this->storeId());
        } catch (ApiException $e) {
            return $result->setData(['html' => $this->note($this->failure($e))]);
        } catch (\Throwable $e) {
            $this->logger->warning('[PigeonExpress] Locker boxes not read: ' . $e->getMessage());

            return $result->setData(['html' => $this->note((string) __('Could not reach Pigeon Express. Try again.'))]);
        }

        // A verdict only for one parcel of known size: a locker takes one.
        $view = $this->lockerBoxes->view($locker, LockerBoxesModel::parcel($packages, $sides), $weight);

        /** @var LockerBoxesBlock $block */
        $block = $this->layoutFactory->create()->createBlock(LockerBoxesBlock::class);
        $block->setView($view);

        return $result->setData(['html' => $block->toHtml()]);
    }

    private function failure(ApiException $e): string
    {
        $status = $e->getHttpStatus();

        if ($status === 404) {
            return (string) __('Pigeon Express does not list this parcel locker now, so its free boxes are not known.');
        }
        if ($status === 429) {
            return (string) __('Too many requests to Pigeon Express. Wait a moment and try again.');
        }
        if ($status === 401 || $status === 403) {
            return (string) __('Pigeon Express rejected the credentials. Check them in the Pigeon Express settings.');
        }
        if ($status >= 500) {
            return (string) __('Could not reach Pigeon Express. Try again.');
        }

        // Raised here (no locker code), or the carrier's own words.
        return $e->getMessage();
    }

    private function note(string $message): string
    {
        return '<p class="pe-locker-boxes__note">' . $this->escaper->escapeHtml($message) . '</p>';
    }

    /** Store of the order being shipped, so the environment and credentials match. */
    private function storeId(): int
    {
        $orderId = (int) $this->getRequest()->getParam('order_id');
        if ($orderId <= 0) {
            return 0;
        }

        try {
            return (int) $this->orderRepository->get($orderId)->getStoreId();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
