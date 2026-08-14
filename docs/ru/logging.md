# Manticore Search Query Builder for PHP (неофициальный PHP-клиент)

## Логирование

Query Builder работает с любым PSR-совместимым логгером. Записи пишутся с уровнями DEBUG,
INFO и ERROR.

### Включение логирования

Общий логгер для всех соединений и запросов:
```php
use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;

// создаём экземпляр логгера
$logger = new Logger();

ManticoreDb::init($config);
ManticoreDb::setLogger($logger);
```

Логгер для конкретного соединения:
```php
use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;

$logger = new Logger();

ManticoreDb::init($config);
ManticoreDb::connection('test')->setLogger($logger);
```

Логгер для одного запроса:
```php
use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;

$logger = new Logger();

ManticoreDb::init($config);
ManticoreDb::table('test')->match($match)->where($where)->setLogger($logger)->get();
ManticoreDb::sql($sql)->setLogger($logger)->exec();
```

### Отключение логирования
```php
use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;

$logger = new Logger();

ManticoreDb::init($config);
// включаем логирование
ManticoreDb::setLogger($logger);

// отключить для следующего запроса
ManticoreDb::sql($sql)->setLogger(false)->exec();

// отключить для конкретного соединения
ManticoreDb::connection('test2')->setLogger(false);

// отключить везде
ManticoreDb::setLogger(false);
```
