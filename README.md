# manticore-query-builder-php

Query Builder for Manticore Search in PHP

```
use avadim\Manticore\QueryBuilder\QueryBuilder as Manticore;

Manticore::init($config);
Manticore::table('articles')->match('peace')->get();
```

## Create index

```
Manticore::create('products', ['title'=> 'text', 'data' => 'json']);
Manticore::create('products', function (SchemaIndex $index) {
    $index->text('title');
    $index->json('data');
});

// columns width additional options
Manticore::create('products', ['title'=> 'text', 'data' => ['type' => 'json', 'engine'=>'rowwise']]);
Manticore::create('products', function (SchemaIndex $index) {
    $index->text('title');
    $index->json('data')->engine('rowwise');
});

```
