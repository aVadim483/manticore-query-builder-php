# Manticore Search Query Builder for PHP (unofficial PHP client)

Jump To:
* [Create table](#create-table)
* [Drop tables](#drop-tables)
* [SHOW TABLES](#show-tables)
* [SHOW CREATE TABLE](#show-create-table)
* [DESCRIBE TABLE](#describe-table)
* [SHOW TABLE STATUS](#show-table--tablename--status)
* [SHOW TABLE SETTINGS](#show-table--tablename--settings)

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
$res = ManticoreDb::create('demo_test', $fields, $options);
$res = ManticoreDb::table('demo_test')->options($options)->create($fields);
```

## Drop tables

```php
$res = ManticoreDb::table('test')->drop();
$res = ManticoreDb::table('test')->dropIfExists();

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
$res = ManticoreDb::tableStatus('test');
// Result is array with variables describing the status of the table 
```

### SHOW TABLE <table_name> SETTINGS

```php
$res = ManticoreDb::sql('SHOW TABLE test SETTINGS')->get();
$res = ManticoreDb::table('test')->settings()->result();
$res = ManticoreDb::tableSettings('test');
// Result is array with settings of the table 
```
