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
an array does not make it valid for an arbitrary column. Keep SQL expressions explicit via
the existing `raw()` API rather than interpolating user input into SQL.

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

Write execution errors are reported through `false`, `success()` and `error()`. The
`*ResultSet()` methods return that complete answer directly. Reads throw
`QueryErrorException` on failure. `lastResultSet()` is shared by queries made by a connection
and refers to its latest operation, including service commands; save a needed result before
running another command. See [ResultSet](result_set.md).

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
