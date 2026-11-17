<?php
/**
 * The computed cells of the courier requests listing: the shipments with
 * links to their orders, the pickup time window, the status, and the
 * Refresh / Cancel actions. Which one a column is comes from its "kind" in
 * the listing XML.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Ui\Component\Listing\Column;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Escaper;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Ui\Component\Listing\Columns\Column;
use PigeonExpress\Shipping\Model\Courier\CourierRequestRepository;
use PigeonExpress\Shipping\Model\Courier\CourierRequestService;

class CourierRequestColumns extends Column
{
    /** @var CourierRequestRepository */
    private $repository;

    /** @var UrlInterface */
    private $urlBuilder;

    /** @var Escaper */
    private $escaper;

    /** @var AuthorizationInterface */
    private $authorization;

    /** @var OrderRepositoryInterface */
    private $orderRepository;

    /**
     * @param array<string, mixed> $components
     * @param array<string, mixed> $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        CourierRequestRepository $repository,
        UrlInterface $urlBuilder,
        Escaper $escaper,
        AuthorizationInterface $authorization,
        OrderRepositoryInterface $orderRepository,
        array $components = [],
        array $data = []
    ) {
        $this->repository = $repository;
        $this->urlBuilder = $urlBuilder;
        $this->escaper = $escaper;
        $this->authorization = $authorization;
        $this->orderRepository = $orderRepository;
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * @param array<string, mixed> $dataSource
     * @return array<string, mixed>
     */
    public function prepareDataSource(array $dataSource)
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }
        $name = (string) $this->getData('name');
        $kind = (string) ($this->getData('config/kind') ?? '');

        foreach ($dataSource['data']['items'] as &$item) {
            switch ($kind) {
                case 'shipments':
                    $item[$name] = $this->shipmentsHtml((int) $item['entity_id'], (string) ($item['request_type'] ?? ''));
                    break;
                case 'pickup':
                    $item[$name] = $this->escaper->escapeHtml(CourierRequestService::when($item));
                    break;
                case 'status':
                    $item[$name] = '<span class="pe-shipment-status pe-shipment-status--'
                        . $this->escaper->escapeHtmlAttr(CourierRequestService::statusTone($item)) . '">'
                        . $this->escaper->escapeHtml(CourierRequestService::statusLabel($item)) . '</span>';
                    break;
                case 'actions':
                    $item[$name] = $this->actions($item);
                    break;
            }
        }

        return $dataSource;
    }

    private function shipmentsHtml(int $requestId, string $type): string
    {
        $links = [];
        foreach ($this->repository->shipmentsOf($requestId) as $reference => $orderId) {
            $label = (string) $reference;
            try {
                $label .= ' (' . (string) __('Order no. %1', $this->orderRepository->get($orderId)->getIncrementId()) . ')';
            } catch (\Throwable $e) {
                // The order is gone; the waybill number is still worth showing.
            }
            $links[] = '<a href="' . $this->escaper->escapeUrl($this->urlBuilder->getUrl('sales/order/view', ['order_id' => $orderId]))
                . '">' . $this->escaper->escapeHtml($label) . '</a>';
        }
        if ($links === []) {
            return $this->escaper->escapeHtml(__('No shipments'));
        }
        $kind = $type === 'groupage' ? __('Groupage request') : __('Single request');

        return implode('<br/>', $links) . '<br/><small>' . $this->escaper->escapeHtml($kind) . '</small>';
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, array<string, mixed>>
     */
    private function actions(array $item): array
    {
        $number = (string) $item['request_number'];
        $actions = [
            'refresh' => [
                'href'  => $this->urlBuilder->getUrl('pigeonexpress/courier/refresh', ['number' => $number]),
                'label' => __('Refresh status'),
            ],
        ];
        if (!CourierRequestRepository::isFinal((string) ($item['status_code'] ?? ''))
            && $this->authorization->isAllowed('PigeonExpress_Shipping::courier_request_cancel')
        ) {
            $actions['cancel'] = [
                'href'    => $this->urlBuilder->getUrl('pigeonexpress/courier/cancel', ['number' => $number]),
                'label'   => __('Cancel'),
                'post'    => true,
                'confirm' => [
                    'title'   => __('Cancel courier request %1?', $number),
                    'message' => __('Pigeon Express will not send the courier for this request. The shipments stay as they are and can be put in a new request.'),
                ],
            ];
        }

        return $actions;
    }
}
