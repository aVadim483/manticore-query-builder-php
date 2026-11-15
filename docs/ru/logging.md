[English](../logging.md) | **Русский**

# Manticore Search Query Builder for PHP (неофициальный PHP-клиент)

## Логирование

Билдер принимает реализацию PSR-3 `Psr\Log\LoggerInterface`. Обычные запросы записывают
SQL с уровнем INFO, результаты — с уровнем DEBUG, ошибки выполнения — с уровнем ERROR.
Служебные запросы не обязательно создают такие же записи INFO/DEBUG.

### Включение логирования

Общий логгер для соединений в пуле и новых запросов:
```php
use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use Psr\Log\NullLogger;

// NullLogger отбрасывает сообщения; для вывода подставьте PSR-3 логгер приложения
$logger = new NullLogger();

ManticoreDb::init($config);
ManticoreDb::setLogger($logger);
```

`psr/log` уже входит в зависимости библиотеки. Отдельная реализация логирования нужна,
если требуется записывать сообщения в файл или другое хранилище.

Логгер для одного настроенного соединения, с использованием существующего `$logger` приложения:
```php
use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;

ManticoreDb::connection('default')->setLogger($logger);
```

Логгер для одного запроса:
```php
use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;

ManticoreDb::table('products')->match('galaxy')->setLogger($logger)->get();
ManticoreDb::sql('SHOW TABLES')->setLogger($logger)->exec();
```

Объект `Query` копирует логгер соединения при создании. Смена общего логгера или логгера
соединения влияет на последующие запросы; существующий `Query` сохраняет свой логгер, пока
не будет вызван его собственный `setLogger()`. `init()` сбрасывает общий логгер и пул соединений.

### Отключение логирования
```php
use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;

// отключить для этого запроса
ManticoreDb::sql('SHOW TABLES')->setLogger(false)->exec();

// отключить для последующих запросов этого соединения
ManticoreDb::connection('default')->setLogger(false);

// отключить общий логгер и логгеры соединений в пуле для последующих запросов
ManticoreDb::setLogger(false);
```
