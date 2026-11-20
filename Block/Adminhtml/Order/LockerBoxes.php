<?php
/**
 * Free boxes of the parcel locker picked in the admin shipment form: the
 * verdict for this parcel in one line, which boxes take it, and every box
 * size behind "All boxes". Drawn by Controller Order/LockerBoxes from
 * Model\Shipment\LockerBoxes::view().
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Block\Adminhtml\Order;

use Magento\Framework\View\Element\Template;
use PigeonExpress\Shipping\Model\Shipment\LockerBoxes as LockerBoxesModel;

class LockerBoxes extends Template
{
    /** @var string */
    protected $_template = 'PigeonExpress_Shipping::order/locker-boxes.phtml';

    /** @var array{verdict: string, message: string, detail: string, rows: array<int, array<string, mixed>>} */
    private $view = ['verdict' => '', 'message' => '', 'detail' => '', 'rows' => []];

    /**
     * @param array{verdict: string, message: string, detail: string, rows: array<int, array<string, mixed>>} $view
     */
    public function setView(array $view): self
    {
        $this->view = $view + $this->view;

        return $this;
    }

    /**
     * @return array{verdict: string, message: string, detail: string, rows: array<int, array<string, mixed>>}
     */
    public function getView(): array
    {
        return $this->view;
    }

    /** ok (a free box), bad (none, no fit, blocked) or plain (no verdict). */
    public function getTone(): string
    {
        if ($this->view['message'] === '') {
            return 'plain';
        }

        return $this->view['verdict'] === LockerBoxesModel::VERDICT_FREE ? 'ok' : 'bad';
    }

    /** Whether each box can be told apart as one the parcel fits in or not. */
    public function isSized(): bool
    {
        return LockerBoxesModel::isSized($this->view);
    }
}
