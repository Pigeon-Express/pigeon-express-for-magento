<?php
/**
 * Why the nearest offices or lockers were not looked up. The message is
 * already the one the shopper may read; the reason decides the HTTP status
 * of the checkout endpoint.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Location;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

class NearestRefusedException extends LocalizedException
{
    /** Bad type or point: the request itself is wrong. */
    public const INVALID = 'invalid';

    /** A throttle is full, or Pigeon Express asked to be left alone for a while. */
    public const BUSY = 'busy';

    /** No cart session to count the lookup against. */
    public const NO_SESSION = 'no_session';

    /** Pigeon Express did not answer, or answered with an error. */
    public const UNAVAILABLE = 'unavailable';

    /** @var string */
    private $reason;

    public function __construct(string $reason, Phrase $phrase, ?\Exception $cause = null)
    {
        parent::__construct($phrase, $cause);
        $this->reason = $reason;
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
