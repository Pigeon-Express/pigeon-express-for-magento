<?php
/**
 * What the waybill says the parcel contains.
 *
 * Pigeon Express prints this on the label, so it is read by the courier, by the
 * counter staff, and by whoever happens to be standing behind the recipient when
 * they collect. "5 бр. - Push It Messenger Bag 1kg" tells all of them what is in
 * the box and roughly what it is worth. A merchant who would rather not announce
 * that can replace the wording here.
 *
 * `inventory_items` is descriptive only. The sum the carrier insures travels as
 * `service_codes.declared_value`, taken from the order subtotal, and the sum it
 * collects travels as `cod_amount`, so nothing written here moves money.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Shipment;

use Magento\Sales\Api\Data\OrderInterface;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\Config\Source\LabelContents as Mode;

class LabelContents
{
    /** The API rejects a longer description. */
    private const MAX_DESCRIPTION = 200;

    /** @var ConfigInterface */
    private $config;

    public function __construct(ConfigInterface $config)
    {
        $this->config = $config;
    }

    /**
     * The `inventory_items` payload for an order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function build(OrderInterface $order, int $storeId): array
    {
        if ($this->config->getLabelContentsMode($storeId) === Mode::FIXED_TEXT) {
            return $this->fixed($storeId);
        }

        return $this->fromProducts($order, $storeId);
    }

    /**
     * One line, no quantity.
     *
     * A merchant who has replaced the contents with a single wording does not
     * want it repeated once per order line, and "1 бр. - Дрехи" reads as though
     * something has been lost. Quantity is optional in the API, so leaving it out
     * is what gets the count off the label.
     *
     * @return array<int, array<string, string>>
     */
    private function fixed(int $storeId): array
    {
        return [
            ['description' => $this->clamp($this->config->getLabelContentsText($storeId))],
        ];
    }

    /**
     * A line per order line, as the module has always sent.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fromProducts(OrderInterface $order, int $storeId): array
    {
        $items = [];

        foreach ($order->getItems() as $item) {
            // Skip child items (e.g. simple product under configurable).
            if ($item->getParentItemId()) {
                continue;
            }

            $qty = (int) $item->getQtyOrdered();
            if ($qty <= 0) {
                $qty = 1;
            }

            $price = round((float) $item->getPrice(), 2);
            if ($price <= 0) {
                $price = 0.01;
            }

            $name = trim((string) $item->getName());
            if ($name === '') {
                $name = (string) __('Goods');
            }
            $name = $this->clamp($name);

            $items[] = [
                'name'        => $name,
                'description' => $name,
                'quantity'    => $qty,
                'price'       => $price,
            ];
        }

        // The API requires at least one item.
        if (empty($items)) {
            return $this->fixed($storeId);
        }

        return $items;
    }

    /**
     * The API caps a description at 200 characters and rejects the shipment
     * rather than trimming it, so a long product name must not reach it whole.
     */
    private function clamp(string $value): string
    {
        if (mb_strlen($value) <= self::MAX_DESCRIPTION) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, self::MAX_DESCRIPTION - 1)) . '…';
    }
}
