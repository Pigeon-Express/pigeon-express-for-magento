<?php
/**
 * Pigeon Express Shipping module registration.
 *
 * @copyright Copyright (c). All rights reserved.
 */

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'PigeonExpress_Shipping',
    __DIR__
);

/*
 * Find the Pigeon Express SDK.
 *
 * Composer first, when the package is installed the usual way. Then the copy
 * bundled inside this module, which is how a client release ships, the SDK not
 * being on Packagist. Then the sibling checkout used while developing against
 * both repositories at once.
 *
 * Registered ahead of Composer's autoloader rather than after it, so the module
 * still reaches the carrier on an install whose vendor/ is half built.
 */
if (!class_exists(\PigeonExpress\PigeonExpress::class, false)) {
    $sdkCandidates = [
        __DIR__ . '/lib/pigeonexpress-php-sdk/src',
        dirname(__DIR__, 4) . '/packages/pigeonexpress-php-sdk/src',
    ];

    foreach ($sdkCandidates as $sdkBase) {
        if (!is_dir($sdkBase)) {
            continue;
        }

        spl_autoload_register(function ($class) use ($sdkBase) {
            // The module's own classes are PigeonExpress\Shipping\*, and Composer
            // owns those. Only the SDK's namespace is served from here.
            if (strpos($class, 'PigeonExpress\\') !== 0
                || strpos($class, 'PigeonExpress\\Shipping\\') === 0
            ) {
                return;
            }

            $file = $sdkBase . '/' . str_replace('\\', '/', substr($class, 14)) . '.php';
            if (file_exists($file)) {
                require_once $file;
            }
        }, true, true);

        break;
    }
}
