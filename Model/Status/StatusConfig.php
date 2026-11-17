<?php
/**
 * The "Shipment status and tracking" settings.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Status;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class StatusConfig
{
    public const XML_STATUS_SYNC = 'carriers/pigeonexpress/status_sync';
    public const XML_ORDER_ON_DELIVERY = 'carriers/pigeonexpress/order_on_delivery';
    public const XML_ORDER_ON_RETURN = 'carriers/pigeonexpress/order_on_return';

    /** What the order settings can ask for. */
    public const ACTION_NONE = 'none';
    public const ACTION_COMPLETE = 'complete';
    public const ACTION_HOLD = 'hold';
    public const ACTION_CANCEL = 'cancel';

    /** @var ScopeConfigInterface */
    private $scopeConfig;

    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Read shipment statuses in the background. On by default, as in
     * WooCommerce: it only reads and shows; orders change only through the
     * two order settings, which default to "Don't change the order".
     */
    public function isStatusSyncEnabled(): bool
    {
        $value = $this->scopeConfig->getValue(self::XML_STATUS_SYNC);

        return $value === null || $value === '' || (bool) (int) $value;
    }

    /** What to do with the order once delivered: ACTION_NONE or ACTION_COMPLETE. */
    public function getOrderOnDelivery(?int $storeId = null): string
    {
        return $this->action(self::XML_ORDER_ON_DELIVERY, $storeId, [self::ACTION_COMPLETE]);
    }

    /** What to do with the order once returned: ACTION_NONE, ACTION_HOLD or ACTION_CANCEL. */
    public function getOrderOnReturn(?int $storeId = null): string
    {
        return $this->action(self::XML_ORDER_ON_RETURN, $storeId, [self::ACTION_HOLD, self::ACTION_CANCEL]);
    }

    /**
     * The saved action, ACTION_NONE for anything else (never saved, or a
     * value this version does not know).
     *
     * @param string[] $allowed
     */
    private function action(string $path, ?int $storeId, array $allowed): string
    {
        $value = $this->string($path, $storeId);

        return in_array($value, $allowed, true) ? $value : self::ACTION_NONE;
    }

    private function string(string $path, ?int $storeId): string
    {
        return trim((string) $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId));
    }
}
