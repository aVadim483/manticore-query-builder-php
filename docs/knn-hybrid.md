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

## Hybrid search with RRF in PHP

```php
$query = ManticoreDb::table('products')->where('stock', '>', 0);
$result = $query->hybridSearch('phone', 'embedding', [0.2, 0.7, 0.1], [
    'text_candidates' => 200,
    'vector_candidates' => 150,
    'text_weight' => 1.5,
    'vector_weight' => 1,
    'rank_constant' => 60,
    'knn_options' => ['ef' => 200, 'prefilter' => true],
]);
$rows = $result->rows();             // list of document arrays
$first = $result->first();           // array|null
$hits = $result->hits();             // HybridHit[]
$page = $result->paginateWindow(20, 2); // data, page, per_page, window_total, total_found
```

`hybridSearch()`, `hybridGet()` and `hybridFirst()` execute immediately. The latter
two return document arrays / the first document. Common structured attribute
filters (including nested conditions, JSON and MVA) apply to both branches.
The original query is cloned and retains its filters and options.

The first branch retrieves text matches; the second independently retrieves vector
neighbors. A document may occur in either or both. Fusion runs in PHP, using
`sum(weight / (rank_constant + rank))`, with ranks starting at 1. This is distinct
from a single `match()->whereKnn()` query and from server-side fusion. Applications
supply embeddings; no model provider is called by this API.

The common query must select all columns and have no existing MATCH/KNN, raw SQL
predicates, joins, grouping, facets, highlighting, visible computed expressions,
ordering, limit or offset. Text parameters are escaped by default; `escape => false`
explicitly enables native MATCH syntax. The options are limited to the keys shown
above plus `escape`; unknown options fail before execution. Candidate windows are
integers from 1 to 10000; weights are finite numbers from 0 to 1000000 with at least
one positive weight; `rank_constant` is an integer from 0 to 1000000. A zero-weight
branch is skipped, and empty text skips the text branch. The numeric vector and
KNN options must remain valid even when the vector branch is disabled.

Each `HybridHit` exposes `id()`, `document()`, `score()`, `textRank()`, `vectorRank()`,
`textScore()` and `distance()`. An absent branch has null evidence and contributes
zero. Text-branch document fields win for overlapping IDs. Equal fused scores sort
by unsigned numeric ID ascending. IDs outside PHP's integer range are returned as
exact decimal strings, using an independent SQL string transport alias.

## Exporting the result

`HybridResult::toArray()` returns `hits`, `window_total`, `total_found`,
`text_meta` and `vector_meta`. Each hit uses the existing `HybridHit::toArray()`
shape: `id`, `document`, `score`, `textRank`, `vectorRank`, `textScore` and `distance`.
Metadata is an array when captured (possibly empty), or null when unavailable.
An empty result has an empty `hits` list and a window size of zero; `total_found`
remains null. Search options and duplicate document lists are not included.

Both result classes implement `JsonSerializable` with the same array representation:

```php
$array = $result->toArray();
$json = json_encode($result, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
$hitJson = json_encode($result->firstHit(), JSON_THROW_ON_ERROR);
```

Export performs no SQL and does not change the result or `lastResultSet()`.
ID types follow the PHP API: values above `PHP_INT_MAX` remain decimal strings.
Consumers must also preserve precision for numeric IDs. JSON flags and encoding
errors remain controlled by the caller; invalid text is not silently replaced.

## Window pagination and execution

`windowTotal()` and `count($result)` count the materialized union window.
`totalFound()` is null: the global union total is unknown. `textMeta()` and
`vectorMeta()` expose captured server metadata, or null for an absent branch.
Never add branch totals. `paginateWindow()` slices only this window without SQL;
its `total_found` remains null. Deep windows cost more memory, traffic and server
work. An explicit `max_matches` must cover both declared candidate windows;
otherwise the helper sets it on each clone as needed.

Searches run sequentially on the same client without a shared snapshot. Metadata
is captured before casting or logging can run other queries. A branch error is
propagated even when the other branch succeeded. There is no retry or silent
fallback. `lastResultSet()` continues to describe the last executed SQL branch;
keep the returned `HybridResult` for the combined result. Duplicate IDs within a
branch and collisions with reserved transport aliases are rejected.

`HybridResult::fuse($textResultSet, $vectorResultSet, $options)` exposes pure fusion
for relevance tests and captured data without making database calls.
