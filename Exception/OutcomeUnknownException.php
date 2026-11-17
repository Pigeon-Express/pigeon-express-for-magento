<?php
/**
 * A write whose result Pigeon Express did not confirm.
 *
 * Thrown when the request may have reached the carrier and been carried out:
 * no answer (a timeout), a server error, or a 409 that persisted after the
 * repeats (an identical request still running). The caller must not repeat
 * the request blindly, because the shipment may exist already.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Exception;

class OutcomeUnknownException extends ApiException
{
}
