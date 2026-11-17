<?php
/**
 * Bulgarian phone numbers, as Pigeon Express takes them.
 *
 * The carrier looks the recipient up by phone, so a wrong number can attach
 * a parcel to somebody else's stored address. The same rules as the
 * WooCommerce plugin (Util\Phone):
 *
 *   accepted  +359 88 123 4567, 00359881234567, 0881234567, 881234567,
 *             02 981 2345, 032 123 456
 *   mobile    359 87/88/89/98 + 7 digits
 *   landline  359 2-7 + 6 or 7 digits
 *
 * @copyright Copyright (c). All rights reserved.
 */

declare(strict_types=1);

namespace PigeonExpress\Shipping\Model\Phone;

class BulgarianPhone
{
    /**
     * Digits only, starting with 359 when the number is Bulgarian. Null when
     * there are no digits at all.
     */
    public static function normalize(string $phone): ?string
    {
        $digits = (string) preg_replace('/\D+/', '', $phone);
        if ($digits === '') {
            return null;
        }

        if (strpos($digits, '00359') === 0) {
            $digits = substr($digits, 2);
        }

        // Trunk prefix 0: mobiles dial as 10 digits, landlines as 9.
        if (in_array(strlen($digits), [9, 10], true) && $digits[0] === '0') {
            $digits = '359' . substr($digits, 1);
        }

        // A mobile typed without the 0.
        if (strlen($digits) === 9 && in_array($digits[0], ['8', '9'], true)) {
            $digits = '359' . $digits;
        }

        return $digits;
    }

    public static function isValid(string $phone): bool
    {
        $normalized = self::normalize($phone);
        if ($normalized === null || strpos($normalized, '359') !== 0) {
            return false;
        }

        $rest = substr($normalized, 3);
        $length = strlen($rest);
        if ($length < 8 || $length > 9) {
            return false;
        }

        if ($length === 9 && preg_match('/^(8[7-9]|98)\d{7}$/', $rest)) {
            return true;
        }

        return (bool) preg_match('/^[2-7]\d{6,7}$/', $rest);
    }
}
