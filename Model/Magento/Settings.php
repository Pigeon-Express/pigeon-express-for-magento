<?php
/**
 * The settings that decide what the module does to Magento's own order
 * documents: the native shipment with its tracking number, and completing the
 * order once it is delivered.
 *
 * A path never saved reads as its default from config.xml; a null here means
 * neither exists (an install upgraded without the config cache flushed), and
 * then the WooCommerce default applies.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Magento;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use PigeonExpress\Shipping\Model\Status\StatusConfig;

class Settings
{
    public const XML_PATH_CREATE_MAGENTO_SHIPMENT = 'carriers/pigeonexpress/create_magento_shipment';

    /** Magento's own "send shipment email" and "send invoice email". */
    public const XML_PATH_SHIPMENT_EMAIL = 'sales_email/shipment/enabled';
    public const XML_PATH_INVOICE_EMAIL = 'sales_email/invoice/enabled';

    /** @var ScopeConfigInterface */
    private $scopeConfig;

    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
    }

    /** Create the native Magento shipment with the waybill. Default Yes. */
    public function isCreateMagentoShipment(?int $storeId = null): bool
    {
        return $this->flag(self::XML_PATH_CREATE_MAGENTO_SHIPMENT, $storeId, true);
    }

    /** "When the shipment is delivered" is "Complete the order". Default no. */
    public function isCompleteOnDelivery(?int $storeId = null): bool
    {
        $value = $this->scopeConfig->getValue(
            StatusConfig::XML_ORDER_ON_DELIVERY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return trim((string) $value) === StatusConfig::ACTION_COMPLETE;
    }

    public function isShipmentEmailEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_PATH_SHIPMENT_EMAIL, $storeId, true);
    }

    public function isInvoiceEmailEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_PATH_INVOICE_EMAIL, $storeId, true);
    }

    private function flag(string $path, ?int $storeId, bool $default): bool
    {
        $value = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
        if ($value === null || $value === '') {
            return $default;
        }

        return (bool) (int) $value;
    }
}
