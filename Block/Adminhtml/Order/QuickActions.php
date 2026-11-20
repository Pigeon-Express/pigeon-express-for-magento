<?php
/**
 * The "Quick changes" box of the order panel, and the forms it opens.
 *
 * Shown once Edit is over (the courier has the shipment) while the shipment
 * has not ended and was not redirected (Model\QuickActions::isOffered()).
 * The box lists the four changes; "Open quick changes" asks Pigeon Express
 * what it allows now (Controller Order/QuickOptions) and puts one form per
 * allowed change in its place, drawn by this block with the forms template.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Block\Adminhtml\Order;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Sales\Api\Data\OrderInterface;
use PigeonExpress\Shipping\Api\ConfigInterface;
use PigeonExpress\Shipping\Model\QuickActions as QuickActionsModel;
use PigeonExpress\Shipping\Model\Shipment;
use PigeonExpress\Shipping\Model\ShipmentCanceller;
use PigeonExpress\Shipping\Model\ShipmentEditor;

class QuickActions extends Template
{
    public const ADMIN_RESOURCE = 'PigeonExpress_Shipping::quick_change';

    /** @var string */
    protected $_template = 'PigeonExpress_Shipping::order/quick-actions.phtml';

    /** @var Registry */
    private $registry;

    /** @var ShipmentCanceller */
    private $finder;

    /** @var ShipmentEditor */
    private $editor;

    /** @var AuthorizationInterface */
    private $authorization;

    /** @var OrderInterface|null */
    private $order;

    /** @var array<string, mixed> */
    private $view = [];

    /** @var Shipment|null|false False until looked up */
    private $shipment = false;

    public function __construct(
        Context $context,
        Registry $registry,
        ShipmentCanceller $finder,
        ShipmentEditor $editor,
        AuthorizationInterface $authorization,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->registry = $registry;
        $this->finder = $finder;
        $this->editor = $editor;
        $this->authorization = $authorization;
    }

    public function setOrder(OrderInterface $order): self
    {
        $this->order = $order;
        $this->shipment = false;

        return $this;
    }

    public function getOrder(): ?OrderInterface
    {
        if ($this->order === null) {
            $order = $this->registry->registry('current_order');
            $this->order = $order instanceof OrderInterface ? $order : null;
        }

        return $this->order;
    }

    /**
     * The order's current shipment row, or null.
     */
    public function getShipment(): ?Shipment
    {
        if ($this->shipment === false) {
            $order = $this->getOrder();
            $this->shipment = $order !== null && $order->getEntityId()
                ? $this->finder->findShipment((int) $order->getEntityId())
                : null;
        }

        return $this->shipment;
    }

    /**
     * Whether the box is drawn: allowed to this admin, and offered for the
     * shipment as it was last read.
     */
    public function isOffered(): bool
    {
        if (!$this->isAllowed()) {
            return false;
        }

        $row = $this->getShipment();

        return QuickActionsModel::isOffered($row, $this->editor->editBlockReason($row));
    }

    public function isAllowed(): bool
    {
        try {
            return $this->authorization->isAllowed(self::ADMIN_RESOURCE);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @return array<string, string>
     */
    public function getLabels(): array
    {
        return QuickActionsModel::labels();
    }

    public function getOptionsUrl(): string
    {
        return $this->orderUrl('pigeonexpress/order/quickoptions');
    }

    public function getChangeUrl(): string
    {
        return $this->orderUrl('pigeonexpress/order/quickchange');
    }

    /**
     * Model\QuickActions::view() for the forms template.
     *
     * @param array<string, mixed> $view
     */
    public function setView(array $view): self
    {
        $this->view = $view;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getView(): array
    {
        return $this->view;
    }

    /** The office or locker search of the change-of-place form. */
    public function getLocationSearchUrl(string $type): string
    {
        $order = $this->getOrder();

        return $this->getUrl('pigeonexpress/order/locationsearch', [
            'type'     => $type === 'locker' ? ConfigInterface::DELIVERY_TYPE_APS : ConfigInterface::DELIVERY_TYPE_OFFICE,
            'order_id' => $order !== null ? (int) $order->getEntityId() : 0,
        ]);
    }

    public function getCitySearchUrl(): string
    {
        return $this->getUrl('pigeonexpress/order/citysearch');
    }

    public function getStreetSearchUrl(): string
    {
        return $this->orderUrl('pigeonexpress/order/streetsearch');
    }

    /** A delivery type as the change-of-place form names it. */
    public function getTypeLabel(string $type): string
    {
        return QuickActionsModel::typeLabel($type);
    }

    private function orderUrl(string $route): string
    {
        $order = $this->getOrder();

        return $order !== null && $order->getEntityId()
            ? $this->getUrl($route, ['order_id' => (int) $order->getEntityId()])
            : '';
    }
}
