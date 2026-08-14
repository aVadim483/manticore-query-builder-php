# Manticore Search Query Builder for PHP (неофициальный PHP-клиент)

## Конфигурация

```php
use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;

$config = [
    'defaultConnection' => 'default',
    'connections' => [
        // соединение по умолчанию
        'default' => [
            'host' => 'localhost',
            'port' => 9306,
            'username' => null,
            'password' => null,
            'timeout' => 5,
            'prefix' => 'test_', // префикс, который подставляется вместо плейсхолдера "?<table_name>"
            'force_prefix' => false,
        ],

        // второе соединение с минимальными настройками
        'second'  => [
            'hosts' => [
                'host' => 'localhost',
                'port' => 9306,
            ],
        ],

    ],
];

// инициализация билдера
ManticoreDb::init($config);

// соединение по умолчанию
$res = ManticoreDb::table('?products')->get();

// конкретное соединение
$res = ManticoreDb::connection('second')->table('texts')->get();

```

## Подмена классов соединения и запроса

Обёртке под фреймворк обычно нужно, чтобы чтения отвечали так, как принято в этом фреймворке:
коллекцией вместо массива, своими объектами строк и так далее. Для этого есть две точки
подмены, и нужны обе: одного наследования `Connection` мало — билдер всё равно продолжит
раздавать базовый класс.

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

// каждое созданное дальше соединение будет MyConnection, а каждый его запрос — MyQuery
ManticoreDb::setConnectionClass(MyConnection::class);
```

Класс проверяется (он обязан наследовать `Connection`) и не сбрасывается вызовом `init()`,
поэтому его можно задать один раз при старте приложения, ещё до появления конфига. Соединения,
созданные до вызова, отбрасываются — они уже другого класса.

Кеш схемы и последний `ResultSet` соединения при подмене продолжают работать:
`Connection::query()` передаёт их тому классу, который создаёт.

## Произвольный SQL и транзакции

```php
// выполнить запрос; true, если сервер его принял
ManticoreDb::statement('FLUSH RAMCHUNK products');

// выполнить запрос и получить строки
$rows = ManticoreDb::select('SELECT id, title FROM products WHERE MATCH(:q)', [':q' => 'galaxy']);

// транзакция — BEGIN / COMMIT / ROLLBACK Manticore поддерживает для real-time таблиц
ManticoreDb::transaction(function ($connection) {
    $connection->table('products')->insert($row);
    $connection->table('log')->insert($record);
});

// ... или вручную
ManticoreDb::beginTransaction();
ManticoreDb::table('products')->insert($row);
ManticoreDb::commit();   // либо rollBack()
```

В колбэк `transaction()` передаётся соединение, а то, что колбэк вернул, становится
результатом вызова. Исключение откатывает транзакцию и пробрасывается дальше; второй аргумент
задаёт число попыток. Savepoint-ов в Manticore нет, поэтому вложенный `transaction()` лишь
считает уровень вложенности — пишет только внешний commit.

## Сырые выражения

`raw()` помечает кусок SQL, который нужно подставить туда, где ожидается значение, — без
кавычек и экранирования:

```php
$res = ManticoreDb::table('products')->where('qty', 'IN', ManticoreDb::raw('(1,2,3)'))->get();
```

Учтите, что Manticore не принимает выражения ни в `INSERT`/`UPDATE`, ни в `WHERE`:
`WHERE price > qty * 2` — синтаксическая ошибка, так что сравнить одну колонку с другой через
сырое выражение не получится. Попытка записать выражение как значение бросает
`InvalidArgumentException`, а не приводит его к числу и не пишет что-то другое.
