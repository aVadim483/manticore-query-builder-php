**English** | [Русский](ru/configuration.md)

# Manticore Search Query Builder for PHP (unofficial PHP client)

## Configuration

The optional connection setting `lazy_insert_ids` (since 2.6.0) defaults to false. See
[lazy insert IDs](writing.md#optional-lazy-ids-26) for its lifetime, errors and compatibility
with custom clients. `insertGetId()`, `replaceGetId()`, `insertResultSet()` and
`replaceResultSet()` retrieve IDs immediately. The [batch APIs](writing.md) have
separate ID collection options and do not collect IDs by default.

```php
use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;

$config = [
    'defaultConnection' => 'default',
    'connections' => [
        // Default connection
        'default' => [
            'host' => 'localhost',
            'port' => 9306,
            'username' => null,
            'password' => null,
            'timeout' => 5, // seconds to wait for the connection to open
            'prefix' => 'test_', // prefix that will replace the placeholder "?<table_name>"
            'force_prefix' => false,
            'lazy_insert_ids' => false, // opt in to deferred IDs for boolean insert()/replace()
        ],

        // Second connection with minimal settings
        'second'  => [
            'host' => 'localhost',
            'port' => 9306,
        ],

    ],
];

// Init query builder
ManticoreDb::init($config);

// Use default connection
$res = ManticoreDb::table('?products')->get();

// Use specific connection
$res = ManticoreDb::connection('second')->table('texts')->get();

```

## Substituting the connection and query classes

A wrapper for a framework may need to customize `get()` — for example, to return a collection
instead of an array. Two hooks are provided for that,
and both are needed: subclassing `Connection` alone would not help, because the builder would keep
handing out the plain one.

```php
use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use avadim\Manticore\QueryBuilder\Connection;
use avadim\Manticore\QueryBuilder\Query;

class MyQuery extends Query
{
    public function get($columns = '*', ...$more)
    {
        $rows = func_num_args() === 0 ? parent::get() : parent::get($columns, ...$more);

        return new \ArrayObject($rows);
    }
}

class MyConnection extends Connection
{
    protected string $queryClass = MyQuery::class;
}

// Every connection built from now on is a MyConnection, and every query of it is a MyQuery
ManticoreDb::setConnectionClass(MyConnection::class);
```

The class is validated (it must extend `Connection`) and kept by `init()`, so it can be set once
on boot, before the config arrives. Connections built before the call are dropped, since they are
of the previous class.

Note that the schema cache and the last `ResultSet` of a connection keep working with a subclassed
query: `Connection::query()` hands both over to whatever class it builds.

## Dropping a connection

A connection is built once and kept in the pool of the builder, which is what makes the schema
cache and the last `ResultSet` of it worth anything. In a process that lives for one request that
is the whole story; in a queue worker, an Octane process or a scheduled command it is not - the
handle of a connection idle since yesterday may be closed by the server, and a test may want the
connection of the previous one gone.

```php
// the next call for it opens a new connection
ManticoreDb::forgetConnection('second');

// no name means the default connection
ManticoreDb::forgetConnection();
```

It answers whether there was a connection of that name to forget; forgetting one that was never
built, or is not in the config at all, is not an error. The object itself is not closed - whoever
still holds a reference goes on using it, and the handle behind it is released when the last
reference is gone.

`init()` empties the pool as well, but it is the reconfiguration of the whole builder: it replaces
the config and drops the logger with the connections.

## Statements and transactions

```php
// run a statement, true when the server accepted it
ManticoreDb::statement('FLUSH RAMCHUNK products');

// run a statement and take its rows
$rows = ManticoreDb::select('SELECT id, title FROM products WHERE MATCH(:q)', [':q' => 'galaxy']);

// $firstProduct and $secondProduct contain documents for the same table
ManticoreDb::transaction(function ($connection) use ($firstProduct, $secondProduct) {
    if (!$connection->table('products')->insert($firstProduct)) {
        throw new \RuntimeException('Product insert failed');
    }
    if (!$connection->table('products')->insert($secondProduct)) {
        throw new \RuntimeException('Second product insert failed');
    }
});
```

The callback of `transaction()` receives the connection, and whatever it returns becomes the
result of the call. The helper uses `BEGIN` / `COMMIT` / `ROLLBACK`.
Manticore transactions cover `INSERT`, `REPLACE` and `DELETE` on a single real-time or percolate
table, excluding distributed-table writes. `UPDATE` and schema commands are not transactional.
Pending writes are invisible even to reads in the same session until commit.
See the [server transaction contract](https://manual.manticoresearch.com/Data_creation_and_modification/Transactions).

It rolls back when the callback throws. With the default one attempt, the exception is rethrown.
A `false` return from a write or from the callback is not an exception and still leads to a commit;
check write results and throw explicitly when a failed write should abort the transaction.

The second argument is the maximum number of attempts. A value above one reruns the entire
callback after an exception, including any side effects outside Manticore. Do not blindly retry
operations after a connection failure when the outcome of a write or commit is unknown.

Nesting counts transaction levels: only the outermost commit sends `COMMIT`. There are no
savepoints; any rollback resets all levels and rolls back the whole transaction. The helper
does not check the boolean results of `beginTransaction()` and `commit()`. When those results
must be observed, call the manual methods and check their return values. These methods do not
make a read-modify-write sequence atomic.

## Raw expressions

`raw()` marks a piece of SQL to be used where a value is expected, without quoting or escaping:

```php
$res = ManticoreDb::table('products')->where('qty', 'IN', ManticoreDb::raw('(1,2,3)'))->get();
```

Raw expressions cannot be written as document values by `insert()` or `update()`; the builder
throws `InvalidArgumentException` instead of converting them to scalar values. Direct arithmetic
such as `WHERE price > qty * 2` is not a valid Manticore filter. Select a computed value with an
alias, then filter that alias against a constant:

```php
$res = ManticoreDb::table('products')
    ->select(['*', 'price * 2 AS double_price'])
    ->where('double_price', '>', 100)
    ->get();
```
