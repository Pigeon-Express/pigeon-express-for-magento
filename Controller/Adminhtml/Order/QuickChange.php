<?php
/**
 * One quick change: its price (qa_dry_run = 1, nothing changes) or the change
 * itself (qa_dry_run = 0), under the order's lock. A price or a refusal is
 * answered in the change's own form, which keeps what was typed; a change, or
 * a refusal after which the order is not as the page shows it, reloads the
 * order page with the message.
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
use Magento\Sales\Api\OrderRepositoryInterface;
use PigeonExpress\Shipping\Model\QuickActions;
use PigeonExpress\Shipping\Model\QuickActions\QuickActionException;
use PigeonExpress\Shipping\Model\Shipment\RedirectFollower;
use PigeonExpress\Shipping\Model\ShipmentCanceller;
use PigeonExpress\Shipping\Model\ShipmentEditor;
use Psr\Log\LoggerInterface;

class QuickChange extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'PigeonExpress_Shipping::quick_change';

    /** @var JsonFactory */
    private $resultJsonFactory;

    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /** @var ShipmentCanceller */
    private $finder;

    /** @var ShipmentEditor */
    private $editor;

    /** @var QuickActions */
    private $quickActions;

    /** @var Escaper */
    private $escaper;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        OrderRepositoryInterface $orderRepository,
        ShipmentCanceller $finder,
        ShipmentEditor $editor,
        QuickActions $quickActions,
        Escaper $escaper,
        LoggerInterface $logger
    ) {
        $this->resultJsonFactory = $resultJsonFactory;
        $this->orderRepository = $orderRepository;
        $this->finder = $finder;
        $this->editor = $editor;
        $this->quickActions = $quickActions;
        $this->escaper = $escaper;
        $this->logger = $logger;
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $result = $this->resultJsonFactory->create();
        $request = $this->getRequest();

        try {
            $order = $this->orderRepository->get((int) $request->getParam('order_id'));
        } catch (\Throwable $e) {
            return $result->setData(['error' => (string) __('Order not found.')]);
        }

        $orderId = (int) $order->getEntityId();
        $action = (string) preg_replace('/[^a-z_]/', '', strtolower((string) $request->getParam('qa_action', '')));
        $reference = RedirectFollower::cleanReference((string) $request->getParam('qa_reference', ''));
        $dryRun = (string) $request->getParam('qa_dry_run', '1') !== '0';
        $currency = QuickActions::currency(
            (string) $request->getParam('qa_currency', ''),
            (string) $order->getOrderCurrencyCode()
        );
        $reload = $this->getUrl('sales/order/view', ['order_id' => $orderId]);

        // Asked again: the page may be older than the shipment.
        $row = $this->finder->findShipment($orderId);
        if (!QuickActions::isOffered($row, $this->editor->editBlockReason($row))) {
            return $result->setData([
                'error' => (string) __('Quick changes are not offered for this shipment now. Reload the order.'),
            ]);
        }

        $fields = self::fields($request->getParam('qa'));

        try {
            $outcome = $this->quickActions->run($order, $action, $fields, $dryRun, $reference);
        } catch (QuickActionException $e) {
            if (!$dryRun && $e->redraw()) {
                $this->messageManager->addErrorMessage($e->getMessage());

                return $result->setData(['reload' => $reload]);
            }

            return $result->setData(['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->logger->error('[PigeonExpress] Quick change failed: ' . $e->getMessage(), ['order_id' => $orderId]);

            if (!$dryRun) {
                $this->messageManager->addErrorMessage(__(
                    'Pigeon Express did not confirm the change, so it may or may not have been made. Use "Refresh status" to check before trying again.'
                ));

                return $result->setData(['reload' => $reload]);
            }

            return $result->setData(['error' => (string) __('Could not reach Pigeon Express. Try again.')]);
        }

        if ($dryRun) {
            $html = '';
            foreach ($this->quickActions->priceRows($outcome['price'], $currency, $outcome['removed']) as $line) {
                $html .= '<div class="pe-summary-row"><dt>' . $this->escaper->escapeHtml($line['label']) . '</dt><dd>'
                    . $this->escaper->escapeHtml($line['value']) . '</dd></div>';
            }

            return $result->setData(['html' => $html]);
        }

        $this->messageManager->addSuccessMessage($outcome['message']);

        return $result->setData(['reload' => $reload]);
    }

    /**
     * The form's fields, narrowed; which of them count is the action's
     * business (Model\QuickActions::bodyFor()).
     *
     * @param mixed $posted
     * @return array<string, mixed>
     */
    public static function fields($posted): array
    {
        $posted = is_array($posted) ? $posted : [];
        $text = static function (string $key) use ($posted): string {
            $value = $posted[$key] ?? '';

            return is_scalar($value) ? trim(strip_tags((string) $value)) : '';
        };
        $list = static function (string $key) use ($posted): array {
            return QuickActions::codes(is_array($posted[$key] ?? null) ? $posted[$key] : []);
        };
        $type = $text('delivery_type');

        return [
            'delivery_type'          => in_array($type, QuickActions::DELIVERY_TYPES, true) ? $type : '',
            'location_id'            => (string) preg_replace('/\D/', '', $text('location_id')),
            'location_label'         => $text('location_label'),
            'city_id'                => (int) preg_replace('/\D/', '', $text('city_id')),
            'city_label'             => $text('city_label'),
            'street_id'              => (int) preg_replace('/\D/', '', $text('street_id')),
            'street_label'           => $text('street_label'),
            'additional_info'        => $text('additional_info'),
            'add'                    => $list('add'),
            'remove'                 => $list('remove'),
            // Amounts stay text: empty means "remove it".
            'cod_amount'             => (string) preg_replace('/[^0-9.,]/', '', $text('cod_amount')),
            'allow_cod_card_payment' => !empty($posted['cod_card']),
            'declared_value'         => (string) preg_replace('/[^0-9.,]/', '', $text('declared_value')),
        ];
    }
}
