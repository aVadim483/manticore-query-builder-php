<?php

if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

spl_autoload_register(static function ($class) {
    if (is_file(__DIR__ . '/../composer.json') && $s = file_get_contents(__DIR__ . '/../composer.json')) {
        $data = json_decode($s, true);
        if (!empty($data['autoload']['psr-4'])) {
            foreach ($data['autoload']['psr-4'] as $namespace => $path) {
                if (0 === strpos($class, $namespace)) {
                    include_once __DIR__ . '/.' . $path . '/' . str_replace($namespace, '', $class) . '.php';
                }
            }
        }
    }
});

// EOF