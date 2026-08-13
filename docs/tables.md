# Manticore Search Query Builder for PHP (unofficial PHP client)

Jump To:
* [Create table](#create-table)
* [Alter table](#alter-table)
* [Drop tables](#drop-tables)
* [SHOW TABLES](#show-tables)
* [SHOW CREATE TABLE](#show-create-table)
* [DESCRIBE TABLE](#describe-table)
* [SHOW TABLE STATUS](#show-table-table_name-status)
* [SHOW TABLE SETTINGS](#show-table-table_name-settings)

## Create table

```php
use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;

// Raw SQL query
$res = ManticoreDb::sql("create table products(title text, price float engine='columnar') engine='rowwise'")->exec();

// Use a closure for table schema
$res = ManticoreDb::create('demo_test', function (SchemaTable $table) {
    // set columns
    $table->text('title')->stored();
    $table->text('article')->indexed();
    $table->string('country')->attribute();
    $table->timestamp('time')->columnar();
    $table->json('data');
    $table->float('price');
    $table->multi('list');
    $table->bool('boo');
    
    // set table options
    $table->tableEngine('rowwise');
    $table->tableMorphology(['lemmatize_uk_all', 'lemmatize_de_all']);
    $table->tableOptions(['html_strip' => 1, 'html_index_attrs' => 'img=alt,title; a=title;']);
});

// Use an array for fields description
$fields = [
    // 'field_name' => 'field_type',
    // or 'field_name' => [field_options],
    'title' => 'text stored',
    'article' => ['text', 'indexed'],
    'country' => ['type' => 'string', 'attribute', 'fast_fetch' => 0],
    'time' => ['type' => 'timestamp', 'engine' => 'columnar'],
    'data' => 'json',
    'price' => 'float',
    'list' => 'multi',
    'boo' => 'bool',
];
// Create table without options
$res = ManticoreDb::create('demo_test', $fields);
$res = ManticoreDb::table('demo_test')->create($fields);

// Create table with options
$options = [
    'morphology' => ['lemmatize_uk_all', 'lemmatize_de_all'],
    'html_strip' => 1, 
    'html_index_attrs' => 'img=alt,title; a=title;'
];
$res = ManticoreDb::table('demo_test')->options($options)->create($fields);
$res = ManticoreDb::create('demo_test', $fields, $options);

$res = ManticoreDb::createIfNotExists('demo_test', $fields, $options);
if (!ManticoreDb::hasTable('demo_test') {
$res = ManticoreDb::create('demo_test', $fields, $options);
}
```

## Alter table

Columns are added, dropped and widened one at a time -- the server takes a single operation per
`ALTER` statement.

```php
// Add a column; the type is written the same way as in create()
$res = ManticoreDb::table('products')->addColumn('group_id', 'integer');
$res = ManticoreDb::table('products')->addColumn('title', 'text indexed stored');
$res = ManticoreDb::table('products')->addColumn('article', 'text', 'indexed');
$res = ManticoreDb::table('products')->addColumn('time', ['type' => 'timestamp', 'engine' => 'columnar']);

// Drop a column, or several of them
$res = ManticoreDb::table('products')->dropColumn('price');
$res = ManticoreDb::table('products')->dropColumn(['price', 'qty']);

// Widen an integer column; int -> bigint is the only change of type the server makes
$res = ManticoreDb::table('products')->modifyColumn('group_id', 'bigint');

// Change the full-text settings of the table (the columns are not touched)
$res = ManticoreDb::table('products')->alterSettings(['html_strip' => 1, 'morphology' => ['lemmatize_en_all']]);

// Rename the table (needs a server with Manticore Buddy running)
$res = ManticoreDb::table('?products')->rename('?goods');

// The same operations from the facade, with the table as the first argument
$res = ManticoreDb::addColumn('products', 'group_id', 'integer');
$res = ManticoreDb::dropColumn('products', 'price');
$res = ManticoreDb::modifyColumn('products', 'group_id', 'bigint');
$res = ManticoreDb::alterSettings('products', ['html_strip' => 1]);
$res = ManticoreDb::rename('?products', '?goods');
```

Things to keep in mind:

* A new scalar attribute is filled with an empty value of its type in the rows that are already
  there, and the table cannot be queried while the column is being added.
* The `id` column can neither be dropped nor modified.
* A field that is a full-text field and a string attribute at the same time takes two drops of
  the same name: `dropColumn(['title', 'title'])`.
* `ALTER` is not transactional. When a chain of statements is sent (`dropColumn()` with several
  names), it stops at the first error, the statements before it stay applied, and
  `$res->sqlQuery()` reports the queries that really reached the server.
* Errors are not thrown: `$res->success()` is `false` and `$res->error()` holds the message,
  just like with the other statements.

```php
$res = ManticoreDb::table('products')->dropColumn('nonexistent');
if (!$res->success()) {
    echo $res->error();
}
```

## Drop tables

```php
$res = ManticoreDb::table('test')->drop();
$res = ManticoreDb::table('test')->dropIfExists();

$res = ManticoreDb::drop('test');
$res = ManticoreDb::dropIfExists('test');
```

## Listing tables

### SHOW TABLES

```php
// Plain SQL
$res = ManticoreDb::sql('SHOW TABLES')->get();
// or with the method
$res = ManticoreDb::showTables();
```
| Index         | Table         | Name        | Type |
|---------------|---------------|-------------|------|
| test_products | test_products |  ?_products | rt   |


```php
// Get tables by pattern
$res = ManticoreDb::sql('SHOW TABLES LIKE abc%')->get();
$res = ManticoreDb::showTables('abc%');

// Get tables with prefix
$res = ManticoreDb::showTables();
// ... equal to
$res = ManticoreDb::showTables('?%');

// Get all tables (ignore prefix)
$res = ManticoreDb::showTables('%');
```

### SHOW CREATE TABLE

```php
$res = ManticoreDb::showCreate('test');
// Result is string like
// "CREATE TABLE test (
//      price float,
//      title text,
//      tags text
//  ) morphology='lemmatize_ru_all,lemmatize_en_all'"
```

### DESCRIBE TABLE

```php
$res = ManticoreDb::sql('DESC test')->get();
$res = ManticoreDb::table('test')->describe()->result();
$res = ManticoreDb::tableDescribe('test');
```
| Field   | Type     | Properties     |
|---------|----------|----------------|
| id      | bigint   |                |
| title   | text     | indexed stored |
| price   | float    |                |

### SHOW TABLE <table_name> STATUS

```php
$res = ManticoreDb::sql('SHOW TABLE test STATUS')->get();
$res = ManticoreDb::table('test')->status()->result();
$res = ManticoreDb::table('other')->status('test')->result(); // the argument wins over table()
$res = ManticoreDb::tableStatus('test');
// Result is array with variables describing the status of the table 
```

### SHOW TABLE <table_name> SETTINGS

```php
$res = ManticoreDb::sql('SHOW TABLE test SETTINGS')->get();
$res = ManticoreDb::table('test')->settings()->result();
$res = ManticoreDb::table('other')->settings('test')->result(); // the argument wins over table()
$res = ManticoreDb::tableSettings('test');
// Result is array with settings of the table 
```
