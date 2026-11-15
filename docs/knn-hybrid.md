# Extended KNN and hybrid search

[Русский](ru/knn-hybrid.md)

## Extended numeric KNN

The additive `knn()` API accepts strict numeric vectors and per-search options:

```php
$rows = ManticoreDb::table('products')
    ->where('stock', '>', 0)
    ->knn('embedding', [0.2, 0.7, 0.1], 20, [
        'ef' => 100,
        'oversampling' => 2,
        'rescore' => true,
        'early_termination' => false,
        'prefilter' => true,
    ])
    ->withKnnDistance('distance')
    ->orderByKnnDistance()
    ->select('id', 'title')
    ->get();
```

The column needs a KNN-indexed `float_vector`. The extended options are checked
against Manticore 28.6.6 with its KNN library; availability on older servers varies.
`knn()` sets `LIMIT` to its positive `k` argument and replaces the previous KNN
condition. A later `limit()` can change the returned row limit. Legacy
`whereKnn(string $column, int $k, array $vector)` is unchanged.

Vectors must be non-empty lists of finite integers/floats. Numeric strings, sparse
arrays, `NaN` and infinities are rejected. Dimension is checked when available in
the cached schema; cosine zero vectors are rejected when the metric is declared
there. Missing dimension/metric evidence is delegated to the server. `ef` must be a
positive integer; `oversampling` must be finite and at least 1. Boolean options
accept booleans or integer 0/1. Unknown options fail before execution.

Distance expressions survive later `select()` calls. `orderByKnnDistance()` accepts
ascending order only: Manticore returns KNN matches nearest first, and attribute
sorting only resolves distance ties. Sorting candidates by another field requires
an explicit outer query. KNN `total_found` describes the candidate set, rather than
an exact global count of relevant documents.

## Ranker expressions

```php
$rows = ManticoreDb::table('products')->match('phone')
    ->rankerExpression('sum(lcs)*1000+bm25')->get();
```

`rankerExpression($formula, 'export')` uses the `export` ranker. Formulas must be
non-empty and are escaped as SQL string literals; their expression syntax is
validated by Manticore. Legacy `ranker(string $value)` retains its signature and
behavior.

See the [Manticore KNN manual](https://manual.manticoresearch.com/Searching/KNN?static=true).
