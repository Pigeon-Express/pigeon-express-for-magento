<?php
/**
 * A mass action of a listing that is left out for an admin user without its
 * ACL resource, given in the action's data as "aclResource". The controller
 * checks the same resource; this only keeps the menu honest.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Ui\Component\MassAction;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Ui\Component\Action;

class AclAction extends Action
{
    /** @var AuthorizationInterface */
    private $authorization;

    /**
     * @param array<string, mixed> $components
     * @param array<string, mixed> $data
     * @param mixed $actions
     */
    public function __construct(
        ContextInterface $context,
        AuthorizationInterface $authorization,
        array $components = [],
        array $data = [],
        $actions = null
    ) {
        $this->authorization = $authorization;
        parent::__construct($context, $components, $data, $actions);
    }

    public function prepare()
    {
        parent::prepare();

        $resource = (string) $this->getData('aclResource');
        if ($resource !== '' && !$this->authorization->isAllowed($resource)) {
            $config = (array) $this->getData('config');
            $config['actionDisable'] = true;
            $this->setData('config', $config);
        }
    }
}
