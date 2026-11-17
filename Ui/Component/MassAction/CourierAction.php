<?php
/**
 * "Request a Pigeon Express courier" on the orders grid: left out like any
 * AclAction, and also while the courier does not collect from the shop
 * (pickup type office or locker).
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Ui\Component\MassAction;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use PigeonExpress\Shipping\Model\Courier\CourierSettings;

class CourierAction extends AclAction
{
    /** @var CourierSettings */
    private $settings;

    /**
     * @param array<string, mixed> $components
     * @param array<string, mixed> $data
     * @param mixed $actions
     */
    public function __construct(
        ContextInterface $context,
        AuthorizationInterface $authorization,
        CourierSettings $settings,
        array $components = [],
        array $data = [],
        $actions = null
    ) {
        $this->settings = $settings;
        parent::__construct($context, $authorization, $components, $data, $actions);
    }

    public function prepare()
    {
        parent::prepare();

        if (!$this->settings->isAvailable()) {
            $config = (array) $this->getData('config');
            $config['actionDisable'] = true;
            $this->setData('config', $config);
        }
    }
}
