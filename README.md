# Manticore Search Query Builder for PHP (unofficial PHP client)

Query Builder for Manticore Search in PHP with Laravel-like syntax

Features
* MySQL multiple connections via PDO (because Manticore is SQL-first)
* Placeholders as prefix in table names
* Named parameters in expressions
* Clear Laravel-like syntax
* Support MATCH() and multi-level WHERE for SELECT
* Support faceted search

More detail documentation is available in [docs](/docs/README.md) folder.

## Quick start guide

```php
use avadim\Manticore\QueryBuilder\QueryBuilder as ManticoreDb;

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
ManticoreDb::create('products', function (SchemaTable $table) {
    $table->timestamp('created_at');
    $table->string('manufacturer'); 
    $table->text('title'); 
    $table->json('info'); 
    $table->float('price'); 
    $table->multi('categories'); 
    $table->bool('on_sale'); 
});

// Insert row
$insertRow = [
    'created_at' => time(),
    'manufacturer' => 'Samsung',
    'title' => 'Galaxy S23 Ultra',
    'info' => ['color' => 'Red', 'storage' => 512],
    'price' => 1199.00,
    'categories' => [5, 7, 11],
    'on_sale' => true,
];
$res = ManticoreDb::table('?products')->insert($insertSet);
// $res->result() => <id> of the new record

$rows = ManticoreDb::table('articles')->match('galaxy')->where('price', '>', 1100)->get();
```

More detail documentation is available in [docs](/docs/README.md) folder.


