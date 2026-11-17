<?php
/**
 * The "Pigeon Express" cell of Sales > Orders: the waybill number and the
 * status last read, or "No shipment" for a Pigeon Express order without one,
 * and "Courier requested" under the waybill while an open courier request
 * holds it and the courier has not collected it, as WooCommerce shows it.
 *
 * The waybill comes from OrderGridPigeonExpressPlugin's join; the order's
 * method and the courier requests are read here for the page's rows only.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Ui\Component\Listing\Column;

use Magento\Framework\Escaper;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;
use PigeonExpress\Shipping\Model\Courier\CourierRequestService;
use PigeonExpress\Shipping\Model\Magento\Grid\GridFacts;
use PigeonExpress\Shipping\Model\Magento\Grid\StateFilter;
use PigeonExpress\Shipping\Model\ShipmentCanceller;
use PigeonExpress\Shipping\Model\Status\StatusCodes;
use PigeonExpress\Shipping\Model\Status\StatusNames;

class PigeonExpress extends Column
{
    /** @var StatusNames */
    private $statusNames;

    /** @var Escaper */
    private $escaper;

    /** @var GridFacts */
    private $gridFacts;

    /** @var StateFilter */
    private $stateFilter;

    /**
     * @param array<string, mixed> $components
     * @param array<string, mixed> $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        StatusNames $statusNames,
        Escaper $escaper,
        GridFacts $gridFacts,
        StateFilter $stateFilter,
        array $components = [],
        array $data = []
    ) {
        $this->statusNames = $statusNames;
        $this->escaper = $escaper;
        $this->gridFacts = $gridFacts;
        $this->stateFilter = $stateFilter;
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * @param array<string, mixed> $dataSource
     * @return array<string, mixed>
     */
    public function prepareDataSource(array $dataSource)
    {
        if (!isset($dataSource['data']['items']) || !is_array($dataSource['data']['items'])) {
            return $dataSource;
        }

        $items = $dataSource['data']['items'];
        $methods = [];
        $requests = [];
        try {
            $methods = $this->gridFacts->shippingMethods(array_column($items, 'entity_id'));
            $requests = $this->gridFacts->openFor(array_column($items, 'pigeonexpress_reference'));
        } catch (\Throwable $e) {
            // The column shows the waybills without the extras rather than
            // breaking the grid.
            unset($e);
        }

        $name = (string) $this->getData('name');
        foreach ($dataSource['data']['items'] as &$item) {
            $reference = (string) ($item['pigeonexpress_reference'] ?? '');
            $item[StateFilter::FIELD . '_value'] = $this->stateFilter->stateOf(
                $methods[(int) ($item['entity_id'] ?? 0)] ?? '',
                isset($item['pigeonexpress_lifecycle']) ? (string) $item['pigeonexpress_lifecycle'] : null,
                isset($item['pigeonexpress_status_code']) ? (string) $item['pigeonexpress_status_code'] : null,
                $reference !== '' && isset($requests[$reference])
            );
            $item['pigeonexpress_courier'] = $reference !== '' ? ($requests[$reference] ?? null) : null;
            $item[$name] = $this->cell($item);
        }
        unset($item);

        return $dataSource;
    }

    /**
     * @param array<string, mixed> $item
     */
    public function cell(array $item): string
    {
        $state = $item[StateFilter::FIELD . '_value'] ?? null;
        if ($state === null || $state === '') {
            return '';
        }

        $reference = (string) ($item['pigeonexpress_reference'] ?? '');
        $lifecycle = (string) ($item['pigeonexpress_lifecycle'] ?? '');

        if ($state === StateFilter::STATE_NONE) {
            $text = $lifecycle === ShipmentCanceller::STATUS_CANCELLED && $reference !== ''
                ? __('No shipment (%1 cancelled)', $reference)
                : __('No shipment');

            return '<span class="pigeonexpress-grid-none">' . $this->escaper->escapeHtml((string) $text) . '</span>';
        }

        $code = (string) ($item['pigeonexpress_status_code'] ?? '');
        $label = $code !== ''
            ? $this->statusNames->label($code, (string) ($item['pigeonexpress_status_name'] ?? ''))
            : (string) __('Shipment created');
        $tone = $code !== '' ? StatusCodes::tone($code) : StatusCodes::TONE_TRANSIT;

        return '<code class="pigeonexpress-grid-ref">' . $this->escaper->escapeHtml($reference) . '</code><br>'
            . '<span class="pigeonexpress-grid-status pigeonexpress-grid-status--'
            . $this->escaper->escapeHtmlAttr($tone) . '">' . $this->escaper->escapeHtml($label) . '</span>'
            . ($state === StateFilter::STATE_WAITING ? $this->courierLine($item['pigeonexpress_courier'] ?? null) : '');
    }

    /**
     * "Courier requested", with the request number and pickup time on hover.
     *
     * @param array<string, mixed>|null $request
     */
    private function courierLine($request): string
    {
        if (!is_array($request)) {
            return '';
        }

        $title = trim((string) ($request['request_number'] ?? '') . ' · ' . CourierRequestService::when($request), ' ·');

        return '<br><span class="pigeonexpress-grid-courier" title="' . $this->escaper->escapeHtmlAttr($title) . '">'
            . $this->escaper->escapeHtml((string) __('Courier requested')) . '</span>';
    }
}
