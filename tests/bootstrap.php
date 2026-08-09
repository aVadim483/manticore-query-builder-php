<?php

/**
 * PHPUnit bootstrap.
 *
 * Looks for the composer autoloader both when the package is installed as a dependency
 * (vendor/avadim/manticore-query-builder-php/tests) and when tests run from the repo root.
 */

$vendorDir = __DIR__ . '/../../..';

if (file_exists($file = $vendorDir . '/autoload.php')) {
    require_once $file;
}
elseif (file_exists($file = __DIR__ . '/../vendor/autoload.php')) {
    require_once $file;
}
else {
    throw new \RuntimeException('Not found composer autoload, run "composer install" first');
}

/**
 * Test classes are not registered in composer.json (the package ships without autoload-dev),
 * so map the test namespace here.
 */
spl_autoload_register(static function ($class) {
    $prefix = 'avadim\\Manticore\\Tests\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = __DIR__ . '/' . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
