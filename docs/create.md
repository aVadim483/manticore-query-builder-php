# Manticore Search Query Builder for PHP (unofficial PHP client)

## Create table

```php
// Raw SQL query
$res = ManticoreDb::sql("create table products(title text, price float engine='columnar') engine='rowwise'")->exec();

// Use a closure for table schema
$res = ManticoreDb::create('demo_test', function (SchemaTable $table) {
    $table->text('title')->stored();
    $table->text('article')->indexed();
    $table->string('country')->attribute();
    $table->timestamp('time')->columnar();
    $table->json('data');
    $table->float('price');
    $table->multi('list');
    $table->bool('boo');
    
    $table->tableEngine('rowwise');
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
$res = ManticoreDb::create('demo_test', $fields);
$res = ManticoreDb::table('demo_test')->create($fields);
```
