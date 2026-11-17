<?php
/**
 * The "Pigeon Express charges" box on the order panel: what Pigeon Express
 * charged for the shipment and to whom, a fee breakdown, the cash on delivery
 * and the linked shipments, from the copy kept with the shipment. As the
 * WooCommerce "Courier service" box.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Block\Adminhtml\Order;

use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Sales\Api\Data\OrderInterface;
use PigeonExpress\Shipping\Model\Shipment;
use PigeonExpress\Shipping\Model\Status\ShipmentDetailsStore;

class Charges extends Template
{
    /** @var string */
    protected $_template = 'PigeonExpress_Shipping::delivery/charges.phtml';

    /** @var ShipmentDetailsStore */
    private $store;

    /** @var PriceCurrencyInterface */
    private $priceCurrency;

    /** @var Shipment|null */
    private $shipment;

    /** @var OrderInterface|null */
    private $order;

    public function __construct(
        Context $context,
        ShipmentDetailsStore $store,
        PriceCurrencyInterface $priceCurrency,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->store = $store;
        $this->priceCurrency = $priceCurrency;
    }

    public function setShipment(Shipment $shipment): self
    {
        $this->shipment = $shipment;

        return $this;
    }

    public function setOrder(OrderInterface $order): self
    {
        $this->order = $order;

        return $this;
    }

    /**
     * Everything the box prints, already worded and formatted, or null when
     * the details were never read.
     *
     * @return array{updated:string, total:string, payer:string, fees:array<int, array{label:string, amount:string}>, cod_amount:string, cod_status:string, related:array<int, array{label:string, reference:string}>}|null
     */
    public function getDisplay(): ?array
    {
        $stored = $this->shipment !== null ? $this->shipment->getDetails() : null;
        if ($stored === null) {
            return null;
        }

        $currency = (string) ($stored['cod']['currency'] ?? '');
        if ($currency === '' && $this->order !== null) {
            $currency = (string) $this->order->getOrderCurrencyCode();
        }

        $fees = [];
        if (isset($stored['courier_service']) && $stored['courier_service'] !== null) {
            $fees[] = ['label' => (string) __('Delivery'), 'amount' => $this->money((float) $stored['courier_service'], $currency)];
        }
        foreach ((array) ($stored['fees'] ?? []) as $fee) {
            $label = $this->store->feeLabel((string) ($fee['code'] ?? ''), (string) ($fee['name'] ?? ''));
            if ((int) ($fee['quantity'] ?? 1) > 1) {
                $label = (string) __('%1 x %2', $label, (int) $fee['quantity']);
            }
            $fees[] = ['label' => $label, 'amount' => $this->money((float) ($fee['amount'] ?? 0), $currency)];
        }

        $related = [];
        foreach ((array) ($stored['related'] ?? []) as $link) {
            $related[] = [
                'label'     => $this->store->relationLabel((string) ($link['relation'] ?? '')),
                'reference' => (string) ($link['reference'] ?? ''),
            ];
        }

        $cod = isset($stored['cod']['amount']) && $stored['cod']['amount'] !== null ? (float) $stored['cod']['amount'] : 0.0;

        return [
            'updated'    => $this->formatTimestamp((int) $stored['fetched_at']),
            'total'      => isset($stored['total']) && $stored['total'] !== null
                ? $this->money((float) $stored['total'], $currency) : '',
            'payer'      => $this->store->payerLabel((string) ($stored['payer'] ?? '')),
            'fees'       => $fees,
            'cod_amount' => $cod > 0 ? $this->money($cod, $currency) : '',
            'cod_status' => $cod > 0 ? $this->store->codStatusLabel((string) ($stored['cod']['payment_status'] ?? '')) : '',
            'related'    => $related,
        ];
    }

    private function money(float $amount, string $currency): string
    {
        return (string) $this->priceCurrency->format(
            $amount,
            false,
            PriceCurrencyInterface::DEFAULT_PRECISION,
            null,
            $currency !== '' ? $currency : null
        );
    }

    private function formatTimestamp(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return '';
        }

        return (string) $this->_localeDate->formatDateTime(
            (new \DateTime('@' . $timestamp)),
            \IntlDateFormatter::MEDIUM,
            \IntlDateFormatter::SHORT
        );
    }
}
