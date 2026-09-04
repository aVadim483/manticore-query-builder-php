**English** | [Русский](ru/configuration.md)

# Manticore Search Query Builder for PHP (unofficial PHP client)

## Configuration

```php
use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;

$config = [
    'defaultConnection' => 'default',
    'connections' => [
        // Default connection which will be used with environment variables
        'default' => [
            'host' => 'localhost',
            'port' => 9306,
            'username' => null,
            'password' => null,
            'timeout' => 5, // seconds to wait for the connection to open
            'prefix' => 'test_', // prefix that will replace the placeholder "?<table_name>"
            'force_prefix' => false,
        ],

        // Second connection with minimal settings
        'second'  => [
            'hosts' => [
                'host' => 'localhost',
                'port' => 9306,
            ],
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

A wrapper for a framework usually needs the reads answered in the way that framework expects —
a collection instead of an array, its own row objects, and so on. Two hooks are provided for that,
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
        return collect(parent::get($columns, ...$more));
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

// a transaction - Manticore serves BEGIN / COMMIT / ROLLBACK on real-time tables
ManticoreDb::transaction(function ($connection) {
    $connection->table('products')->insert($row);
    $connection->table('log')->insert($record);
});

// ... or by hand
ManticoreDb::beginTransaction();
ManticoreDb::table('products')->insert($row);
ManticoreDb::commit();   // or rollBack()
```

The callback of `transaction()` receives the connection, and whatever it returns becomes the
result of the call. An exception rolls the transaction back and is rethrown; a second argument
sets how many times to try. There are no savepoints in Manticore, so a nested `transaction()`
only counts a level deeper - the outermost commit is the one that writes.

## Raw expressions

`raw()` marks a piece of SQL to be used where a value is expected, without quoting or escaping:

```php
$res = ManticoreDb::table('products')->where('qty', 'IN', ManticoreDb::raw('(1,2,3)'))->get();
```

Note that Manticore takes no expressions in `INSERT` and `UPDATE`, and none in `WHERE` either -
`WHERE price > qty * 2` is a syntax error, so a raw expression cannot make one column compare to
another. Writing one as a value throws an `InvalidArgumentException` rather than casting it to a
number and writing something else.
