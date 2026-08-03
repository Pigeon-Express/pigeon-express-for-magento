<?php
/**
 * Product dimensions are not known, so the parcel cannot be described.
 *
 * Its own type because rating has to tell this apart from a carrier that is
 * merely unreachable, and it used to do so by matching on the text of the
 * message. That check could only ever work in English: this store runs its admin
 * in Bulgarian, where the translated Phrase never matched and the parcel was
 * quoted instead of hidden.
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Exception;

class MissingDimensionsException extends ApiException
{
}
