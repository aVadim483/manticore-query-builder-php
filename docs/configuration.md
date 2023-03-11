# Manticore Search Query Builder for PHP (unofficial PHP client)

## Configuration

```php
$config = [
    'defaultConnection' => 'default',
    'connections' => [
        // Default connection which will be used with environment variables
        'default' => [
            'host' => 'localhost',
            'port' => 9306,
            'username' => null,
            'password' => null,
            'timeout' => 5,
            'prefix' => 'test_', // prefix that will replace the placeholder "?<table_name>"
            'force_prefix' => false,
        ],

        // Second connection with minimal settings
        'second'  => [
            'hosts' => [
                'host' => 'localhost',
                'port' => 9306,
            ],
        ],

    ],
];

// Init query builder
ManticoreDb::init($config);

// Use default connection
$res = ManticoreDb::table('?products')->get();

// Use specific connection
$res = ManticoreDb::connection('second')->table('texts')->get();

```