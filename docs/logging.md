**English** | [Русский](ru/logging.md)

# Manticore Search Query Builder for PHP (unofficial PHP client)

## Logging

The builder accepts a PSR-3 `Psr\Log\LoggerInterface` implementation. Ordinary queries log
SQL at INFO level and results at DEBUG level; execution errors are logged at ERROR level.
Service queries do not necessarily emit the same INFO/DEBUG entries.

### Enable logging

Set a common logger for pooled connections and new queries:
```php
use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use Psr\Log\NullLogger;

// NullLogger discards messages; replace it with your application's PSR-3 logger for output
$logger = new NullLogger();

ManticoreDb::init($config);
ManticoreDb::setLogger($logger);
```

`psr/log` is already a runtime dependency. No separate logging implementation is required
unless you want to write messages to a file or another destination.

Set a logger for one configured connection, using the application's existing `$logger`:
```php
use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;

ManticoreDb::connection('default')->setLogger($logger);
```

Set a logger for an individual query:
```php
use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;

ManticoreDb::table('products')->match('galaxy')->setLogger($logger)->get();
ManticoreDb::sql('SHOW TABLES')->setLogger($logger)->exec();
```

A `Query` copies the connection's logger when it is created. Changing the common or connection
logger affects subsequent queries, but an existing `Query` keeps its logger until you call its
own `setLogger()`. `init()` resets the common logger and connection pool.

### Disable logging
```php
use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;

// Disable logging for this query
ManticoreDb::sql('SHOW TABLES')->setLogger(false)->exec();

// Disable logging for subsequent queries from this connection
ManticoreDb::connection('default')->setLogger(false);

// Disable the common logger and loggers of pooled connections for subsequent queries
ManticoreDb::setLogger(false);
```


