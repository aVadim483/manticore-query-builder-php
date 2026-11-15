**English** | [Русский](ru/writing.md)

# Inserting and replacing documents

Examples use `avadim\Manticore\QueryBuilder\Builder as ManticoreDb` and a real-time table
`products` with `title` (text), `price` (float), `info` (JSON), `categories` (MVA), and
`embedding` (float vector, dimension 3). Use the fields that exist in your table.

## Choosing a method

| Operation | Success flag | First written ID | Complete result |
|---|---|---|---|
| Insert | `insert($data, $id = 0): bool` | `insertGetId($data, $id = 0): ?int` | `insertResultSet($data, $id = 0): ResultSet` |
| Replace | `replace($data, $id = 0): bool` | `replaceGetId($data, $id = 0): ?int` | `replaceResultSet($data, $id = 0): ResultSet` |

`$data` is one associative row or an array of associative rows. The optional `$id` sets
the ID of a single row; a nonzero argument overrides `id` in that row. It is ignored for
multiple rows: put each ID in its own row. Numeric IDs remain the supported ID contract.

These existing methods use PHP integers for IDs, including formatting and ID retrieval.
Use IDs within `0..PHP_INT_MAX` for this API; it does not preserve the full uint64 range.
The batch methods introduced in 2.7 have a separate exact-uint64 contract described below.

## Insert one document

```php
use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;

// Let the server generate an ID.
$id = ManticoreDb::table('products')->insertGetId([
    'title' => "Alice's phone",
    'price' => 99.50,
]);

// Supply an ID explicitly.
$ok = ManticoreDb::table('products')->insert([
    'id' => 100,
    'title' => 'Phone',
    'price' => 99.50,
]);

// Equivalent way to supply the ID for one row.
$ok = ManticoreDb::table('products')->insert(['title' => 'Tablet'], 101);
```

Do not pre-escape ordinary strings. The builder formats values according to the table schema.
For duplicate IDs, use `replace()` when replacement is intended; the ordinary SQL INSERT
path does not implement an upsert or promise a uniqueness error.

## Replace a document

```php
$ok = ManticoreDb::table('products')->replace([
    'id' => 100,
    'title' => 'New phone',
    'price' => 120.00,
]);
```

The builder emits `REPLACE INTO ... VALUES ...`: an existing document with that ID is
replaced, and an absent ID creates a document. Fields omitted from this SQL replacement
receive their defaults. For example, replacing only `id` and `title` resets the previous
`price` to its default. Include all values you need to preserve.

`update()` changes supported attributes of matching documents; it does not create a missing
document. The builder's `replace()` is a full replacement, not the server's separate partial
`REPLACE ... SET ... WHERE id=...` syntax. See the
[server REPLACE reference](https://manual.manticoresearch.com/Data_creation_and_modification/Updating_documents/REPLACE).

## Write several documents

```php
$result = ManticoreDb::table('products')->insertResultSet([
    ['id' => 201, 'title' => 'One', 'price' => 10.00],
    ['id' => 202, 'title' => 'Two', 'price' => 20.00],
]);

if ($result->success()) {
    $ids = $result->result(); // [201, 202]
}

$ok = ManticoreDb::table('products')->replace([
    ['id' => 201, 'title' => 'Updated one', 'price' => 11.00],
    ['id' => 202, 'title' => 'Updated two', 'price' => 22.00],
]);
```

A multi-row array becomes one SQL statement, not a streaming import. All its rows and SQL
are built in memory; keep statements within the server's packet limit. Use the same columns
in every row: the builder takes their union and formats a missing value as `null` according
to the column type, which does not preserve the existing field.

`result()` contains an integer for one returned ID or an array for several. The `*GetId()`
methods return only the first ID of a multi-row write, not the entire list. Check success
before reading IDs; the ID value alone is not an error report.

## Structured values

```php
$ok = ManticoreDb::table('products')->insert([
    'title' => "Книга 'Поиск'",
    'info' => ['color' => 'red', 'storage' => 512],
    'categories' => [5, 7, 11],
    'embedding' => [0.12, 0.4, 0.8],
]);
```

Pass JSON objects, MVA attributes and vectors as PHP arrays for columns with those schema
types. Vector dimensions must match the schema. Server validation still applies; supplying
an array does not make it valid for an arbitrary column. Do not pass `raw()` expressions
as INSERT, REPLACE or UPDATE values: the formatter rejects them with
`InvalidArgumentException`. `raw()` is for SQL expressions in supported query clauses;
it does not make server-side arithmetic assignments available in these write methods.

## Upserts and conditional writes

`upsert()` and `updateOrInsert()` are helpers implemented by this library over several
Manticore queries. Their familiar names do not imply Laravel's database-specific SQL
implementation, uniqueness guarantees or error behavior.

```php
$written = ManticoreDb::table('products')->upsert([
    ['id' => 100, 'title' => 'Updated phone', 'price' => 125.00],
    ['id' => 101, 'title' => 'New tablet', 'price' => 150.00],
], ['id'], ['title', 'price']);
```

`upsert(array $rows, $uniqueBy, ?array $update = null): int` looks up each input row
with a SELECT matching the supplied key columns. It INSERTs an absent document or
merges a found row with the selected input fields and writes it with REPLACE. `$update`
restricts fields written to an existing document; null uses all supplied fields.
Pass a non-empty key or key list that identifies one document. The method does not
create or verify a unique index and does not reject multiple matches before selecting
the first one. REPLACE can preserve only fields returned by the lookup: TEXT fields
that are not stored/retrievable cannot be reconstructed automatically.

The returned integer counts successful per-row writes. A failed write can leave a
partially applied input; subsequent rows are still attempted. A rejected lookup throws
`QueryErrorException`, and a missing key column throws `InvalidArgumentException` when
that row is reached, possibly after earlier writes. There is no whole-input preflight,
automatic transaction or concurrency protection for the read/merge/write sequence.
The last result alone cannot establish that every earlier write succeeded.

`updateOrInsert(array $attributes, ?array $values = []): bool` first checks for a
matching document. If one exists, it UPDATEs matching documents; otherwise it INSERTs
the merged attributes and values. `$values` must be an array; a callback is not supported.
The UPDATE branch retains native attribute-update restrictions and cannot change TEXT
fields. In the current implementation that branch tests the returned update count with
`>= 0`, so its boolean can be true even after a failed UPDATE returned zero. Inspect the
last write result immediately rather than treating that boolean as a reliable success flag:

```php
$query = ManticoreDb::table('products');
$query->updateOrInsert(['id' => 100], ['price' => 125.00]);
$result = $query->lastResultSet();
if ($result !== null && !$result->success()) {
    $message = $result->error();
}
```

This helper also has no automatic transaction or protection against changes between
lookup and write. For an explicit full-document replacement with a known ID use
`replace()` and include every value that must be retained.

## Errors and the last result

```php
$query = ManticoreDb::table('products');
$ok = $query->insert(['title' => 'Phone']);
$result = $query->lastResultSet();

if (!$ok) {
    $message = $result->error();
} else {
    $id = $result->result();
}
```

For ordinary writes, execution failures are reported through the scalar return value
and `ResultSet::success()` / `error()`. The `*ResultSet()` methods return that complete
answer directly. Row-returning methods such as `get()` and `first()`, and scalar reads
such as `count()` or `sum()`, throw `QueryErrorException` when execution fails;
`search()` and `exec()` return an unsuccessful
ResultSet instead. Argument/SQL-compilation errors can throw before either path executes.
`lastResultSet()` is shared by queries made by a connection and refers to the latest
operation that publishes a ResultSet, including explicit service commands. Internal
schema lookups and the automatic SHOW META do not replace the public result slot.
Save a needed result before another public operation. See [ResultSet](result_set.md).

An eager ID-query failure can be reported after the INSERT/REPLACE itself reached the
server; an unsuccessful result is not proof that nothing was written. Lazy-ID failures
are reported separately from write success, as described below.

There is no automatic retry of writes. A lost connection may leave the outcome unknown;
repeating a write unconditionally can repeat its effects. Multiple statements are not
automatically one atomic operation; see [transactions](configuration.md).

## Optional lazy IDs (2.6)

By default, INSERT/REPLACE is followed by `SELECT LAST_INSERT_ID()`, even when the public
method returns `bool`. That ID is kept in the last result. The first write against an
uncached table can also issue `DESCRIBE`; its schema is cached by the connection.

Set `lazy_insert_ids` in the connection configuration to defer ID retrieval for the boolean
`insert()` and `replace()` methods:

```php
ManticoreDb::init([
    'host' => '127.0.0.1',
    'port' => 9306,
    'lazy_insert_ids' => true,
]);

$query = ManticoreDb::table('products');
$ok = $query->insert(['title' => 'Phone']); // no ID query
$result = $query->lastResultSet();         // no ID query
$result->success();                       // no ID query
if ($ok) {
    $id = $result->result();               // SELECT LAST_INSERT_ID()
    $sameId = $result->result();           // cached, no SQL
}
```

Retrieve pending IDs **before any other command on the same client/connection**, including
reads, service commands, transaction commands and raw SQL. The client conservatively expires
pending IDs when another statement is prepared. A stale result throws `LogicException`;
it never returns another write's IDs. Other connections do not expire it. Already loaded IDs
remain available after subsequent commands. Keeping the result alive keeps its original
client alive; forgetting the connection does not move the loader to a new connection.

If ID retrieval fails after a successful write, `result()` throws and remembers that failure;
`success()`/`error()` continue to describe the write itself. It does not repeat the write
or silently retry ID retrieval. Pending results contain a callback and should not be serialized.

`insertGetId()`, `replaceGetId()`, `insertResultSet()` and `replaceResultSet()` always load
IDs immediately. Legacy custom clients keep the immediate path. PDOClient subclasses must
explicitly opt in via `supportsDeferredInsert()` and preserve the deferred execution contract.

## Streaming batch INSERT/REPLACE

`insertBatch(iterable $rows, array $options = []): BatchResult` and `replaceBatch()`
consume arrays, iterators or one-shot generators a batch at a time. They leave existing
`insert()`/`replace()` behavior unchanged. Start with a table query without SELECT/WHERE,
JOIN, MATCH, KNN, sorting, limits, facets, options, raw SQL or bindings: these clauses
cannot silently restrict an INSERT. Each row must have the same set of plain column names;
column order may differ. Values use the existing schema-aware formatter. Raw expressions,
objects, resources, non-finite numbers and recursive/deeper-than-64-level arrays are rejected.

An explicit document `id` must be a non-negative PHP integer or a decimal digit string
within `0..18446744073709551615`. Leading zeroes are normalized; large digit strings
are compiled without converting through PHP's signed integer or floating-point types.
Floats, booleans, negative values and out-of-range IDs are rejected before sending their
batch. Omit `id` or pass zero to request server-generated IDs. These stricter rules
apply to the new batch methods; existing write methods retain their previous contracts.

```php
$rows = (static function () {
    for ($i = 1; $i <= 5000; ++$i) {
        yield ['id' => $i, 'title' => 'Document ' . $i];
    }
})();

$batch = ManticoreDb::table('products')->insertBatch($rows, [
    'max_rows' => 500,
    'max_bytes' => 1024 * 1024,
]);

if (!$batch->success()) {
    $cause = $batch->error() ?? $batch->idError();
    $confirmed = $batch->confirmedRows();
    $position = $batch->failedPosition();
    $unknown = $batch->outcomeUnknown();
}
```

| Option | Default | Meaning |
| --- | --- | --- |
| `max_rows` | `1000` | Positive integer, maximum documents per SQL statement |
| `max_bytes` | `1048576` | Positive integer, maximum final SQL bytes including header, escaping and separators |
| `collect_ids` | `false` | Read IDs immediately per batch and retain all of them in `ids()`; memory grows with the whole import |
| `on_ids` | `null` | Optional `function (array $ids, int $batchIndex)`; reads IDs immediately and delivers them one batch at a time, with a zero-based batch index |

Choose `max_bytes` below the server packet limit with room for protocol overhead. It is
a SQL byte budget, not an automatic discovery of the server limit. A document too large
on its own is rejected before sending it. Rows buffered before a source/validation error
are not flushed. The bounded-memory mode is the default; it keeps the current batch and
one input row, not the entire source. `collect_ids=true` deliberately changes that bound.

Without either ID option there is no `LAST_INSERT_ID()` query, regardless of
`lazy_insert_ids`. With either option IDs are read after the write acknowledgement and
before a callback can issue another write on the same connection. Large IDs outside
PHP's integer range remain decimal strings. A signed two's-complement ID from the
server is normalized to the same unsigned decimal value without floating-point arithmetic.
`on_ids` does not populate `ids()` unless
`collect_ids` is also true. It may write a progress checkpoint; a callback failure stops
the import after the already acknowledged batch.

`BatchResult` is a summary independent of `lastResultSet()`:

- `confirmedRows()` counts input documents in acknowledged statements, not distinct IDs,
  PDO affected-row counts or a guarantee that an external transaction was committed.
- `completedBatches()` counts acknowledged write statements, including one whose ID query
  or callback subsequently failed.
- `failedPosition()` is a zero-based ordinal in the source, independent of iterator keys.
  `failedBatch()` gives its inclusive `start`/`end` range for a write, ID or callback failure;
  source/validation errors have no failed SQL range.
- `error()` preserves a write, input, source or callback exception. `idError()` separately
  preserves an ID retrieval exception: that exception does not undo confirmed writes.
- `outcomeUnknown()` marks an unacknowledged write that may have reached the server.
  Structured PDO SQLSTATE data/constraint/syntax rejections are distinguished when available;
  otherwise execution failures are conservatively treated as unknown. The reported count
  excludes that uncertain batch and must not be interpreted as its rollback.
  SQLSTATE-looking fragments in exception messages are not trusted; legacy clients
  exposing only error text can therefore report an unknown outcome for a server rejection.
- `success()` is false for either kind of error; `ids()` contains only IDs collected
  successfully with `collect_ids=true`.

`lastResultSet()` describes the last attempted write (successful or failed), not the whole
import. A successful no-ID batch has boolean `true` as its result; a successful ID read
sets its numeric ID or list of IDs. An ID failure leaves the write successful there;
check `BatchResult::idError()`. A callback's own queries can update the shared last-result
slot in the usual way. Empty input issues no SQL and keeps the slot unchanged.

Invalid options or unsupported query clauses throw before iteration. Errors after iteration
starts are returned in `BatchResult` and stop consumption. There is no implicit transaction,
automatic rollback or retry. Within a caller transaction, acknowledgements are not commits;
the caller owns commit/rollback. Save or reconcile the result before retrying a partial or
unknown outcome. Legacy custom clients need only the existing `query()` response contract;
the batch methods do not require a new client interface.
