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

## Facets
```
use avadim\Manticore\QueryBuilder\Facet;

// Plain SQL
$response = Manticore::sql('SELECT * FROM products FACET country FACET price')->exec();

// Use builder
$response = ManticoreDb::table('products')->facet('country')->facet('price')->get();
 
// With additional facet options
$response = ManticoreDb::table('products')
    ->facet('country', function (Facet $facet) {
        $facet->limit(2);
    })
    ->facet('price', function (Facet $facet) {
        $facet->alias('cost')->limit(3);
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