**English** | [Русский](ru/result_set.md)

# Manticore Search Query Builder for PHP (unofficial PHP client)

## ResultSet

A read-only model of the answer to a single query. ```search()```, ```create()```,
```drop()``` and the rest of the schema commands return it directly.

### Getting a ResultSet out of a write

```insert()```, ```update()```, ```delete()``` and ```replace()``` answer the way the Laravel
query builder does - with a flag and a number - so their ResultSet has to be asked for
separately. There are two ways to get the result of the same write; service queries may also
run. See [inserting and replacing documents](writing.md) for examples and optional lazy IDs:

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

This matters more here than it would in Laravel: a failed **write** is not thrown as an
exception, it is reported through ```success()``` and ```error()```. The bare ```false``` of
```insert()```/```replace()``` and the bare ```0``` of ```update()```/```delete()``` carry no
reason with them, and ```0``` does not even tell "nothing matched the condition" from "the
statement failed". Reads are the other way round - see [Errors](#errors) below.

The full set of write methods, three per statement where an id makes sense:

| statement | flag or number | id | ResultSet |
|---|---|---|---|
| INSERT | ```insert(): bool``` | ```insertGetId(): ?int``` | ```insertResultSet()``` |
| REPLACE | ```replace(): bool``` | ```replaceGetId(): ?int``` | ```replaceResultSet()``` |
| UPDATE | ```update(): int``` | - | ```updateResultSet()``` |
| DELETE | ```delete(): int``` | - | ```deleteResultSet()``` |

```lastResultSet()``` belongs to a connection and holds the last statement that went through it,
service ones included - the DESCRIBE that a write asks for before building its SQL lands there
too, though always before the write itself, so a write is never masked by it.

### result(): mixed
Depends on last query:
* Collection of rows for SELECT query
* Boolean for CREATE, DROP, TRUNCATE, OPTIMIZE and ALTER queries
* Bigint for INSERT and REPLACE (a list of them when a set of rows was written)
* Number of affected rows for UPDATE and DELETE
* Array for others   

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
Collection of rows

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

### first(): array
Returns the first row of rows set

### meta(): array
Returns the meta data received after SQL request

### hasMeta(): bool and metaObject(): Meta

Added for the upcoming 2.6; available in `main` (Unreleased).

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

### facets(): array
Returns facets

## Specific method for SHOW VARIABLES

### variable($name): mixed
Returns value of variable

## Errors

A statement asked for its **rows** throws when the server rejects it: `get()`, `first()`,
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

`exec()` and `search()` keep answering with the `ResultSet` instead - the error is read out of
it rather than caught:

```php
$result = ManticoreDb::table('?products')->whereRegex('title', 'galaxy')->exec();
if (!$result->success()) {
    $error = $result->error();
}
```

Writes follow the same split: `insert()`, `update()`, `delete()` and `replace()` answer with a
scalar, and the whole answer of the last statement is available through `lastResultSet()`.
