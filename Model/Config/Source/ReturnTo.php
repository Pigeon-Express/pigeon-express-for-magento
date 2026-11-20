<?php
/**
 * Where refused or uncollected shipments go back to.
 *
 * A saved address is offered only when the Pigeon Express account has saved
 * addresses, as for the pickup type, or when it is already the stored choice
 * of the scope being edited: a shop that chose one keeps it shown after the
 * addresses left the account, rather than Magento saving another choice over
 * it unseen.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Config\Source;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Data\OptionSourceInterface;
use Magento\Store\Model\ScopeInterface;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\Account\SavedAddresses;

class ReturnTo implements OptionSourceInterface
{
    /** @var SavedAddresses */
    private $savedAddresses;

    /** @var ScopeConfigInterface */
    private $scopeConfig;

    /** @var RequestInterface */
    private $request;

    /**
     * The request is required: Magento passes null to an optional constructor
     * argument nobody configures, and the scope being edited would never be read.
     */
    public function __construct(
        SavedAddresses $savedAddresses,
        ScopeConfigInterface $scopeConfig,
        RequestInterface $request
    ) {
        $this->savedAddresses = $savedAddresses;
        $this->scopeConfig = $scopeConfig;
        $this->request = $request;
    }

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        $options = [
            ['value' => ConfigInterface::RETURN_TO_PROFILE, 'label' => __('As set in your Pigeon Express account')],
            ['value' => ConfigInterface::RETURN_TO_OFFICE, 'label' => __('To a chosen office')],
        ];

        if ($this->offersAddress()) {
            $options[] = ['value' => ConfigInterface::RETURN_TO_ADDRESS, 'label' => __('To a saved address')];
        }

        return $options;
    }

    /**
     * The setting whose stored value keeps the address choice offered.
     */
    protected function path(): string
    {
        return ConfigInterface::XML_PATH_RETURN_TO;
    }

    private function offersAddress(): bool
    {
        if ($this->storedValue() === ConfigInterface::RETURN_TO_ADDRESS) {
            return true;
        }

        return $this->savedAddresses->hasAny();
    }

    /**
     * The value the scope being edited holds, inherited or its own.
     */
    private function storedValue(): string
    {
        $store = (int) $this->request->getParam('store');
        $website = (int) $this->request->getParam('website');

        if ($store > 0) {
            $value = $this->scopeConfig->getValue($this->path(), ScopeInterface::SCOPE_STORE, $store);
        } elseif ($website > 0) {
            $value = $this->scopeConfig->getValue($this->path(), ScopeInterface::SCOPE_WEBSITE, $website);
        } else {
            $value = $this->scopeConfig->getValue($this->path());
        }

        return trim((string) $value);
    }
}
