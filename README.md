**English** | [Русский](README.ru.md)

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
* Support faceted search and JOIN of two tables
* Column types are applied in both directions: PHP values on write, PHP types on read
* The helpers of the Laravel query builder: aggregates, chunked walks, conditional building,
  conditions on dates, upsert and the rest
* A rejected read throws, a rejected write answers with false or zero and keeps its reason
* PSR-3 logging of queries and EXPLAIN of full-text expressions

Manticore Search server documentation: https://manual.manticoresearch.com/

## Requirements

* PHP 7.4 or above with the PDO extension
* Manticore Search reachable over its SQL interface (port 9306 by default).
  The HTTP/JSON API is not used.

## Installation

```bash
composer require avadim/manticore-query-builder-php
```

## Quick start guide

```php
use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use avadim\Manticore\QueryBuilder\Schema\SchemaTable;

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
$id = ManticoreDb::table('?products')->insertGetId($singleRow);
// insert() returns true/false, insertGetId() - the <id> of the new record

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
$res = ManticoreDb::table('?products')->insertResultSet($multipleRows);
// $res->result() => array of <id> of new records

// Update and delete answer with the number of affected rows
$updated = ManticoreDb::table('?products')->where('price', '<', 100)->update(['on_sale' => true]);
$deleted = ManticoreDb::table('?products')->where('price', '<', 1)->delete();

// A failed write is not thrown - insert() gives false, update()/delete() give 0,
// and the reason is in the ResultSet left behind
if (!ManticoreDb::table('?products')->insert($singleRow)) {
    $error = ManticoreDb::lastResultSet()->error();
}

// A failed read is thrown instead: null in place of the rows would look like an empty table
try {
    $rows = ManticoreDb::table('?products')->where('nosuchcolumn', 1)->get();
}
catch (vadim\Manticore\QueryBuilder\QueryErrorException $e) {
    $error = $e->getMessage();
}

// Search: get() returns rows keyed by document id, with values cast back to PHP types
// ('info' as an array, 'categories' as int[], 'on_sale' as bool)
$rows = ManticoreDb::table('?products')->match('galaxy')->where('price', '>', 1100)->get();
```

## Documentation

More detailed documentation is available in the [/docs](/docs/README.md) folder:
[configuration](/docs/configuration.md),
[searching](/docs/searching.md),
[tables](/docs/tables.md),
[result set](/docs/result_set.md),
[logging](/docs/logging.md).

Документация на русском языке: [README.ru.md](README.ru.md) и папка [/docs/ru](/docs/ru).

## Tests

```bash
vendor/bin/phpunit --testsuite unit   # SQL building and parsing, no server needed
vendor/bin/phpunit                    # adds the integration tests
```

The integration tests create and drop their own tables on a live server. They are skipped,
not failed, when nothing listens on `MANTICORE_HOST:MANTICORE_PORT` (`127.0.0.1:9306`
by default).

## Want to support?

If you find this package useful, just give me a star on [GitHub](https://github.com/aVadim483/manticore-query-builder-php) :)