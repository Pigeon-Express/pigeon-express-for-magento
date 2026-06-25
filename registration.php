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

// Autoload Pigeon Express SDK from packages/ (works even if vendor/ is broken)
$sdkBase = dirname(__DIR__, 4) . '/packages/pigeonexpress-php-sdk/src';
if (is_dir($sdkBase) && !class_exists(\PigeonExpress\PigeonExpress::class, false)) {
    spl_autoload_register(function ($class) use ($sdkBase) {
        if (strpos($class, 'PigeonExpress\\') !== 0) {
            return;
        }
        $file = $sdkBase . '/' . str_replace('\\', '/', substr($class, 14)) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }, true, true);
}
