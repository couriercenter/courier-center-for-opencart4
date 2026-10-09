<?php
/**
 * Loads the Courier Center library (shared with OpenCart 3) + the OpenCart 4 helpers on demand.
 * Safe to include many times.
 */
if (!defined('CC_OC4_LOADER')) {
    define('CC_OC4_LOADER', 1);
    spl_autoload_register(function ($class) {
        $map = [
            'Opencart\\Extension\\Couriercenter\\Library\\Oc4\\' => __DIR__ . '/',
            'Opencart\\Extension\\Couriercenter\\Library\\'      => dirname(__DIR__) . '/',
        ];
        foreach ($map as $ns => $dir) {
            if (strpos($class, $ns) === 0) {
                $file = $dir . str_replace('\\', '/', substr($class, strlen($ns))) . '.php';
                if (is_file($file)) {
                    require_once $file;
                }
                return;
            }
        }
    });
}
