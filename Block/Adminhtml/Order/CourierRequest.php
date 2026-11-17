<?php
/**
 * "Request a courier" in the order panel, or the courier request the
 * shipment is in, with Refresh and Cancel. A child of the panel block
 * (Block\Delivery\Info), included from its template.
 *
 * Shown only for a sent waybill. A shop no longer collected from sees only
 * a request that is still open.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Block\Adminhtml\Order;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use PigeonExpress\Shipping\Block\Delivery\Info;
use PigeonExpress\Shipping\Model\Courier\CourierRequestRepository;
use PigeonExpress\Shipping\Model\Courier\CourierRequestService;
use PigeonExpress\Shipping\Model\Courier\CourierSettings;

class CourierRequest extends Template
{
    /** @var string */
    protected $_template = 'PigeonExpress_Shipping::order/courier-request.phtml';

    /** @var CourierRequestRepository */
    private $repository;

    /** @var CourierRequestService */
    private $service;

    /** @var CourierSettings */
    private $settings;

    /** @var AuthorizationInterface */
    private $authorization;

    /** @var array{request: array<string, mixed>|null, can_request: bool}|null */
    private $state;

    /** @var FormKey */
    private $formKey;

    public function __construct(
        Context $context,
        CourierRequestRepository $repository,
        CourierRequestService $service,
        CourierSettings $settings,
        AuthorizationInterface $authorization,
        FormKey $formKey,
        array $data = []
    ) {
        $this->formKey = $formKey;
        $this->repository = $repository;
        $this->service = $service;
        $this->settings = $settings;
        $this->authorization = $authorization;
        parent::__construct($context, $data);
    }

    /**
     * @return array{request: array<string, mixed>|null, can_request: bool}
     */
    public function getState(): array
    {
        if ($this->state !== null) {
            return $this->state;
        }
        $this->state = ['request' => null, 'can_request' => false];

        $parent = $this->getParentBlock();
        $shipment = $parent instanceof Info && $parent->hasActiveShipment() ? $parent->getExistingShipment() : null;
        if ($shipment === null || !$this->authorization->isAllowed('PigeonExpress_Shipping::courier_requests')) {
            return $this->state;
        }

        $available = $this->settings->isAvailable();
        $request = $this->repository->forReference((string) $shipment->getReferenceNumber());
        if ($request !== null && CourierRequestRepository::isFinal((string) $request['status_code']) && !$available) {
            $request = null;
        }

        $this->state = [
            'request'     => $request,
            'can_request' => $available
                && $this->authorization->isAllowed('PigeonExpress_Shipping::courier_request_create')
                && $this->service->skipReason((string) $shipment->getReferenceNumber(), $shipment->getStatusCode()) === '',
        ];

        return $this->state;
    }

    public function isShown(): bool
    {
        $state = $this->getState();

        return $state['request'] !== null || $state['can_request'];
    }

    private function orderId(): int
    {
        $parent = $this->getParentBlock();
        $shipment = $parent instanceof Info ? $parent->getExistingShipment() : null;

        return $shipment !== null ? (int) $shipment->getOrderId() : 0;
    }

    public function getNewUrl(): string
    {
        $id = $this->orderId();

        return $this->getUrl('pigeonexpress/courier/new', ['order_ids' => $id, 'back_order_id' => $id]);
    }

    public function getActionUrl(string $action, string $number): string
    {
        return $this->getUrl('pigeonexpress/courier/' . $action, ['number' => $number, 'order_id' => $this->orderId()]);
    }

    public function canCancel(array $request): bool
    {
        return !CourierRequestRepository::isFinal((string) $request['status_code'])
            && $this->authorization->isAllowed('PigeonExpress_Shipping::courier_request_cancel');
    }

    public function statusLabel(array $request): string
    {
        return CourierRequestService::statusLabel($request);
    }

    public function statusTone(array $request): string
    {
        return CourierRequestService::statusTone($request);
    }

    public function when(array $request): string
    {
        return CourierRequestService::when($request);
    }

    public function getFormKey(): string
    {
        return $this->formKey->getFormKey();
    }
}
