<?php
/**
 * A quick change that was refused, priced wrongly or overtaken, in the
 * merchant's words. With redraw(), what is on the order changed meanwhile (the
 * shipment, or its details read again), so the panel is drawn anew with the
 * message instead of keeping the form.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\QuickActions;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

class QuickActionException extends LocalizedException
{
    /** @var bool */
    private $redraw;

    public function __construct(Phrase $phrase, bool $redraw = false, ?\Exception $cause = null)
    {
        parent::__construct($phrase, $cause);
        $this->redraw = $redraw;
    }

    /**
     * Whether the order changed, so the page is reloaded with the message.
     */
    public function redraw(): bool
    {
        return $this->redraw;
    }
}
