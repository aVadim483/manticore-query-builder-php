# Manticore Search Query Builder for PHP (unofficial PHP client)

Query Builder for Manticore Search in PHP with Laravel-like syntax

Features
* MySQL connection is used via PDO (because Manticore is SQL-first)
* Placeholders as prefix in table names
* Named parameters in expressions
* Clear Laravel-like syntax

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
$res = ManticoreDb::table('?products')->create([
    'created_at' => 'timestamp',
    'manufacturer' => 'string',
    'title' => 'text',
    'info' => 'json',
    'price' => ['type' => 'float'],
    'categories' => 'multi',
    'on_sale' => 'bool',
]);
// $res->result() => true

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

ManticoreDb::table('articles')->match('peace')->get();
```

More detail documentation is available in [docs](/docs/README.md) folder.

## Create table

```php
ManticoreDb::sql('create table products(title text, data json)')->exec();
ManticoreDb::create('products', ['title'=> 'text', 'data' => 'json']);
ManticoreDb::create('products', function (SchemaIndex $table) {
    $table->text('title');
    $table->json('data'); 
});

// columns with additional options
ManticoreDb::sql("create table products(title text, price float engine='columnar') engine='rowwise'")->exec();
ManticoreDb::create('products', ['title'=> 'text', 'data' => ['type' => 'json', 'engine' => 'rowwise']]);
ManticoreDb::create('products', function (SchemaIndex $table) {
    $table->text('title');
    $table->json('data')->columnEngine('columnar');
});

```

## Listing tables
```php
// Plain SQL
$res = ManticoreDb::sql('SHOW TABLES LIKE <pattern>')->result();
// or with the method
$res = ManticoreDb::showTables('<pattern>')->result();
/*
Array
(
    [0] => Array
        (
            [Table] => <table_name>
            [Name] => <table_name_with_placeholder>
            [Type] => rt
        )
)
*/
// Get tables by pattern
$res = ManticoreDb::showTables('abc%')->result();

// Get tables with prefix
$res = ManticoreDb::showTables('?%')->result();

// Equals of {DESC | DESCRIBE} table [ LIKE pattern ]
$res = ManticoreDb::table('test')->describe();
// Returns array with columns Field and Type

```

## Facets
```php
use avadim\Manticore\QueryBuilder\Facet;

// Plain SQL
$response = ManticoreDb::sql('SELECT * FROM products FACET country FACET price')->exec();

// Use builder
$response = ManticoreDb::table('products')->facet('country')->facet('price')->get();
 
// With additional facet options
$response = ManticoreDb::table('products')
    ->facet('country', function (Facet $facet) {
        $facet->limit(2);
    })
    ->facet('price', function (Facet $facet) {
        $facet->alias('cost');
        $facet->limit(3);
    })
    ->get()
;

// get facets
$response->facets();
```
Facet methods
* alias(string $alias)
* byExpr(string $expr)
* distinct(string $column)
* orderBy(string $names)
* orderByDesc(string $names)
* limit(int $limit)
* limit(int $offset, int $limit)