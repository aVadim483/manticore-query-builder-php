**English** | [Русский](ru/result_set.md)

# Manticore Search Query Builder for PHP (unofficial PHP client)

## ResultSet

A container for one query's result, metadata and execution status. `search()`, `exec()`,
`create()`, `drop()` and other schema commands return it directly.

The composite APIs added in 2.7 return different objects: `insertBatch()` /
`replaceBatch()` return [BatchResult](writing.md#streaming-batch-insertreplace), and
`hybridSearch()` returns [HybridResult](knn-hybrid.md). Their summary counts and errors
are not interchangeable with the single-query ResultSet methods below.

### Getting a ResultSet out of a write

`insert()` and `replace()` return boolean success flags; `update()` and `delete()`
return integer affected-row counts. Obtain their ResultSet separately when you need
the query, IDs or error details. These are this library's contracts, not a promise of
Laravel-compatible behavior. There are two ways to obtain the result of the same write;
service queries may also run. See [writing documents](writing.md) for examples and lazy IDs:

```php
// 1. the *ResultSet() twin of the method
$result = ManticoreDb::table('?products')->insertResultSet(['title' => 'Laptop']);
$result->success();     // did it work
$result->result();      // the id of the inserted row
$result->error();       // and why not, if it did not

// 2. or the last ResultSet of the connection, after the scalar call
if (!ManticoreDb::table('?products')->insert(['title' => 'Laptop'])) {
    $error = ManticoreDb::lastResultSet()->error();
}
```

Ordinary write execution failures are captured in `success()` and `error()`. A bare
`false` from `insert()`/`replace()` or `0` from `update()`/`delete()` contains no reason;
zero cannot distinguish no matching rows from an execution failure. Argument and SQL
compilation errors can still throw, as can deferred ID retrieval after a successful
write. Row-returning methods use a different execution-error contract; see [Errors](#errors).

Ordinary write methods:

| statement | flag or number | id | ResultSet |
|---|---|---|---|
| INSERT | ```insert(): bool``` | ```insertGetId(): ?int``` | ```insertResultSet()``` |
| REPLACE | ```replace(): bool``` | ```replaceGetId(): ?int``` | ```replaceResultSet()``` |
| UPDATE | ```update(): int``` | - | ```updateResultSet()``` |
| DELETE | ```delete(): int``` | - | ```deleteResultSet()``` |

Queries created by one connection share its `lastResultSet()` slot. Public operations
that return/publish a ResultSet, including an explicit `describe()` or `getMeta()`, can
replace it. Internal schema DESCRIBE queries and the automatic SHOW META do not replace
that slot. Save a needed result before another public operation on the connection.

### result(): mixed
Depends on the query and its status:

* a PHP array of rows for a successful SELECT;
* a boolean for CREATE, DROP, TRUNCATE, OPTIMIZE and ALTER;
* an integer ID, a list of integer IDs or null for ordinary INSERT/REPLACE;
* an affected-row count for UPDATE/DELETE;
* a command-specific value for other operations.

Check `success()` before interpreting the value. Ordinary write IDs use PHP integers;
the exact uint64 contract of batch writes does not extend the older methods. A no-ID
batch's last ResultSet contains `true`; after successful batch ID retrieval it contains
an ID or list, with decimal strings outside PHP's integer range. Read the independent
BatchResult for the overall import status and ID errors.

### command(): ?string
Returns a command ('SELECT', 'INSERT', 'SHOW TABLES', etc)

### sqlQuery(): ?string
Returns SQL query

### execTime(): ?float
Returns client execution time measured by the builder, not the server's `meta()['time']`.

### error(): ?string
Returns text of error or warning of last query

### success(): bool
Result without errors and warnings

### status(): ?string
The last result of query

## Specific methods for SELECT

### result(): array
A PHP array of rows. Ordinary single-table results are keyed by ID when it is selected;
JOIN results remain a list so repeated document IDs do not discard rows.

### columns(): array
Returns array of columns names

### count(): int
Returns the server's `total` metadata field. This differs from `Query::count()`, which runs
a separate COUNT query. Use `count($rows)` to count a returned PHP array directly.

### total(): int
Returns the server's `total_found` metadata field: an estimate of matches, or groups for
grouped queries. It is not necessarily an exact document count. Both count accessors return
0 when their fields are absent; use `hasMeta()` or nullable `Meta` accessors to identify
unavailable metadata.

### first(): ?array
Returns the first SELECT row, or null for an empty result.

### meta(): array
Returns the meta data received after SQL request

### hasMeta(): bool and metaObject(): Meta

Available since 2.6.0.

`hasMeta()` tells whether metadata was loaded, including an empty SHOW META answer.
`meta()` keeps returning an array. `metaObject()` provides read-only object access to the
already loaded values and never runs SQL:

```php
$result = ManticoreDb::table('products')->search();
$meta = $result->metaObject();
$total = $meta['total_found'];
$total = $meta->totalFound();
$returned = $meta->total();
$seconds = $meta->time();
$array = $meta->toArray();
```

`Meta` supports `isset()`, `foreach` and `count()`. `count($meta)` is the number of metadata
fields, not documents. Typed accessors return `null` for missing fields; a missing key also
returns `null`. Writing or removing fields throws `LogicException`. Unknown server fields
remain accessible by key. `total_found` is a server estimate and can count groups for grouped
queries; see [SHOW META](https://manual.manticoresearch.com/Node_info_and_management/SHOW_META?static=true).

After `getRows()`, `hasMeta()` is false and `meta()` is empty. The legacy `count()` and
`total()` accessors still return 0 when their metadata fields are absent; these zeros are
not known totals. Use `count($rows)` for the returned row count and the separate `getMeta()`
for server metadata. `metaObject()->totalFound()` returns `null` when the value is unknown.
See [rows without metadata](searching.md#rows-without-metadata).

### facets(?int $key = null): array
Returns all facet rowsets, or one rowset by its zero-based index. A missing facet returns
an empty array.

## Results built from existing rows

`ResultSet::of()` and `ResultSet::empty()` create result objects without SQL:

```php
use avadim\Manticore\QueryBuilder\ResultSet;

$result = ResultSet::of([['id' => 1, 'title' => 'Phone']], ['total_found' => 20]);
$empty = ResultSet::empty();
```

`of()` normalizes rows to a list and supplies `total` / `total_found` from its size unless
you pass those metadata values explicitly. `empty()` has empty rows and zero totals.
Both have metadata available through `hasMeta()`; these totals describe the supplied data,
not a new server count.

## Specific method for SHOW VARIABLES

### variable($name): mixed
Returns value of variable

### variables(): array
Returns all parsed variable names and values.

## Errors

A statement asked for its **rows** throws when execution fails: `get()`, `getRows()`, `first()`,
`find()`, `value()`, `pluck()`, `sole()`, `count()`, the aggregates and the walks all raise
`avadim\Manticore\QueryBuilder\QueryErrorException`, which carries the message of the server
and the statement itself through `sql()`. Answering with `null` or `0` would be
indistinguishable from an empty table.

```php
try {
    $rows = ManticoreDb::table('?products')->whereRegex('title', 'galaxy')->get();
}
catch (\avadim\Manticore\QueryBuilder\QueryErrorException $e) {
    $e->getMessage();   // what the server said
    $e->sql();          // the statement it rejected
}
```

`exec()` and `search()` capture execution failures in a ResultSet. Argument validation
and SQL-compilation errors can still throw before execution:

```php
$result = ManticoreDb::table('?products')->whereRegex('title', 'galaxy')->exec();
if (!$result->success()) {
    $error = $result->error();
}
```

Writes follow the same split: `insert()`, `update()`, `delete()` and `replace()` answer with a
scalar, and the whole answer of the last statement is available through `lastResultSet()`.
`updateOrInsert()` has an additional boolean-return caveat documented in
[conditional writes](writing.md#upserts-and-conditional-writes). Batch methods return
their own failure summary, while `hybridSearch()` propagates a failed branch rather
than returning an apparently successful combined result.
