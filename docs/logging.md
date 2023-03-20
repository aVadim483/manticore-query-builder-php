# Manticore Search Query Builder for PHP (unofficial PHP client)

## Logging

The Manticore Query Builder supports compatible PSR Loggers. There are several where DEBUG,INFO or ERROR messages are logged.

### Enable logging

You can define common logger for all connection and queries.
```php
use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;

// create new logger instance
$logger = new Logger();

ManticoreDb::init($config);
ManticoreDb::setLogger($logger);
```

You can define a logger for the specified connection.
```php
use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;

// create new logger instance
$logger = new Logger();

ManticoreDb::init($config);
ManticoreDb::connection('test')->setLogger($logger);
```

You can define a logger for single queries.
```php
use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;

// create new logger instance
$logger = new Logger();

ManticoreDb::init($config);
ManticoreDb::table('test')->match($match)->where($where)->setLogger($logger)->get();
ManticoreDb::sql($sql)->setLogger($logger)->exec();
```

### Disable logging
```php
use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;

// create new logger instance
$logger = new Logger();

ManticoreDb::init($config);
// Set logging
ManticoreDb::setLogger($logger);

// Disable logging for next request
ManticoreDb::sql($sql)->setLogger(false)->exec();

// Disable logging for the specified connection
ManticoreDb::connection('test2')->setLogger(false);

// Disable logging for all
ManticoreDb::setLogger(false);
```


