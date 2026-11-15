[English](../configuration.md) | **Русский**

# Manticore Search Query Builder for PHP (неофициальный PHP-клиент)

## Конфигурация

Необязательная настройка соединения `lazy_insert_ids` (начиная с 2.6.0)
по умолчанию равна false. См.
[ленивое получение ID](writing.md): срок доступности, ошибки и совместимость с
пользовательскими клиентами. `insertGetId()`, `replaceGetId()`, `insertResultSet()` и
`replaceResultSet()` получают ID сразу. У [пакетной записи](writing.md) свои параметры
сбора ID; по умолчанию она их не собирает.

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
            'timeout' => 5, // сколько секунд ждать установки соединения
            'prefix' => 'test_', // префикс, который подставляется вместо плейсхолдера "?<table_name>"
            'force_prefix' => false,
            'lazy_insert_ids' => false, // отложенные ID для булевых insert()/replace()
        ],

        // второе соединение с минимальными настройками
        'second'  => [
            'host' => 'localhost',
            'port' => 9306,
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

Обёртке под фреймворк может понадобиться изменить `get()`: например, возвращать коллекцию
вместо массива. Для этого есть две точки
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
        $rows = func_num_args() === 0 ? parent::get() : parent::get($columns, ...$more);

        return new \ArrayObject($rows);
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

## Забыть соединение

Соединение создаётся один раз и живёт в пуле построителя — именно поэтому чего-то стоят его кеш
схемы и последний `ResultSet`. Для процесса, живущего один запрос, на этом история и кончается, а
для воркера очереди, процесса Octane или команды по расписанию — нет: соединение, простоявшее без
дела со вчера, сервер мог уже закрыть, а тесту бывает нужно, чтобы соединения предыдущего не
осталось.

```php
// следующее обращение к нему откроет новое соединение
ManticoreDb::forgetConnection('second');

// без имени — соединение по умолчанию
ManticoreDb::forgetConnection();
```

Метод отвечает, было ли что забывать; забыть соединение, которое ни разу не создавалось или
которого нет в конфиге, — не ошибка. Сам объект не закрывается: тот, кто держит на него ссылку,
продолжает им пользоваться, а дескриптор освобождается вместе с последней ссылкой.

`init()` тоже опустошает пул, но это переконфигурация всего построителя: она заменяет конфиг и
выбрасывает логгер вместе с соединениями.

## Произвольный SQL и транзакции

```php
// выполнить запрос; true, если сервер его принял
ManticoreDb::statement('FLUSH RAMCHUNK products');

// выполнить запрос и получить строки
$rows = ManticoreDb::select('SELECT id, title FROM products WHERE MATCH(:q)', [':q' => 'galaxy']);

// $firstProduct и $secondProduct содержат документы одной таблицы
ManticoreDb::transaction(function ($connection) use ($firstProduct, $secondProduct) {
    if (!$connection->table('products')->insert($firstProduct)) {
        throw new \RuntimeException('Не удалось записать товар');
    }
    if (!$connection->table('products')->insert($secondProduct)) {
        throw new \RuntimeException('Не удалось записать второй товар');
    }
});
```

В колбэк `transaction()` передаётся соединение, а то, что колбэк вернул, становится
результатом вызова. Метод использует `BEGIN` / `COMMIT` / `ROLLBACK`.
Транзакции Manticore охватывают `INSERT`, `REPLACE` и `DELETE` в одной real-time или percolate
таблице, кроме записи через распределённую таблицу. `UPDATE` и команды изменения схемы
не транзакционны. До коммита изменения не видны даже чтению в той же сессии.
См. [контракт транзакций сервера](https://manual.manticoresearch.com/Data_creation_and_modification/Transactions).

Если колбэк бросает исключение, метод делает откат. При одной попытке по умолчанию исключение
пробрасывается дальше. Возврат `false` из записи или из колбэка не считается исключением и
ведёт к коммиту; проверяйте результат записи и бросайте исключение явно, если ошибка должна
прервать транзакцию.

Второй аргумент задаёт максимальное число попыток. При значении больше единицы после
исключения заново выполняется весь колбэк, включая действия вне Manticore. Не повторяйте
операции вслепую после сбоя соединения, если результат записи или коммита неизвестен.

Вложенность лишь считает уровни: `COMMIT` отправляет только внешний коммит. Savepoint-ов нет;
любой откат сбрасывает все уровни и откатывает всю транзакцию. Метод `transaction()` не проверяет
булевы результаты `beginTransaction()` и `commit()`. Если их нужно контролировать, вызывайте
ручные методы и проверяйте возвращаемые значения. Эти методы не делают последовательность
чтения и последующей записи атомарной.

## Сырые выражения

`raw()` помечает кусок SQL, который нужно подставить туда, где ожидается значение, — без
кавычек и экранирования:

```php
$res = ManticoreDb::table('products')->where('qty', 'IN', ManticoreDb::raw('(1,2,3)'))->get();
```

Сырые выражения нельзя записывать как значения документов через `insert()` или `update()`:
билдер бросит `InvalidArgumentException` вместо преобразования в скаляр. Прямая арифметика
вроде `WHERE price > qty * 2` не является допустимым фильтром Manticore. Вычисляемое значение
можно выбрать с псевдонимом, а затем отфильтровать этот псевдоним по константе:

```php
$res = ManticoreDb::table('products')
    ->select(['*', 'price * 2 AS double_price'])
    ->where('double_price', '>', 100)
    ->get();
```
