<?php
/**
 * The "Order Comment" email identity, always switched on.
 *
 * Used only for the one email that tells a customer their parcel has a new
 * Pigeon Express number after a redirect: the number they were given no longer
 * follows the parcel, so they are told whatever the store's "Order Comment"
 * email setting says (merchant decision). Sender, template and copies are the
 * store's own "Order Comment" ones; only the on/off switch is ignored. Nothing
 * global changes: Magento's own order comment emails still obey the setting.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Magento;

use Magento\Sales\Model\Order\Email\Container\OrderCommentIdentity;

class RedirectNoticeIdentity extends OrderCommentIdentity
{
    /**
     * @return bool
     */
    public function isEnabled()
    {
        return true;
    }
}
