[![GitHub Release](https://img.shields.io/github/v/release/aVadim483/manticore-query-builder-php)](https://packagist.org/packages/avadim/manticore-query-builder-php)
[![Packagist Downloads](https://img.shields.io/packagist/dt/avadim/manticore-query-builder-php?color=%23aa00aa)](https://packagist.org/packages/avadim/manticore-query-builder-php)
[![GitHub License](https://img.shields.io/github/license/aVadim483/manticore-query-builder-php)](https://packagist.org/packages/avadim/manticore-query-builder-php)
[![Static Badge](https://img.shields.io/badge/php-%3E%3D7.4-005fc7)](https://packagist.org/packages/avadim/manticore-query-builder-php)

# Manticore Search Query Builder for PHP (unofficial PHP client)

Query Builder for Manticore Search in PHP with Laravel-like syntax

Features
* MySQL multiple connections via PDO (because Manticore is SQL-first)
* Placeholders as prefix in table names
* Named parameters in expressions
* Clear Laravel-like syntax
* Multiple INSERT and REPLACE
* Support MATCH() and multi-level WHERE for SELECT
* Support faceted search

More detail documentation is available in [/docs](/docs/README.md) folder.
Manticore Search server documentation: https://manual.manticoresearch.com/

## Quick start guide

```php
use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;

// Define config
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
    ],
];

// Init query builder
ManticoreDb::init($config);

// Create table
ManticoreDb::create('?products', function (SchemaTable $table) {
    $table->timestamp('created_at');
    $table->string('manufacturer'); 
    $table->text('title'); 
    $table->json('info'); 
    $table->float('price'); 
    $table->multi('categories'); 
    $table->bool('on_sale'); 
});

// Insert single row
$singleRow = [
    'created_at' => time(),
    'manufacturer' => 'Samsung',
    'title' => 'Galaxy S23 Ultra',
    'info' => ['color' => 'Red', 'storage' => 512],
    'price' => 1199.00,
    'categories' => [5, 7, 11],
    'on_sale' => true,
];
$res = ManticoreDb::table('?products')->insert($singleRow);
// $res->result() => <id> of the new record

// Insert multiple rows
$multipleRows = [
    [
        'created_at' => time(),
        'manufacturer' => '...',
        'title' => '...',
        'info' => [],
        // ...
    ],
    [
        'created_at' => time(),
        'manufacturer' => '...',
        'title' => '...',
        'info' => [],
        // ...
    ],
];
$res = ManticoreDb::table('?products')->insert($multipleRows);
// $res->result() => array of <id> of new records

$rows = ManticoreDb::table('?products')->match('galaxy')->where('price', '>', 1100)->get();
```

## Documentation

More detail documentation is available in [/docs](/docs/README.md) folder.

## Want to support?

if you find this package useful  just give me a star on [GitHub](https://github.com/aVadim483/manticore-query-builder-php) :)