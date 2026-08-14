[English](../result_set.md) | **Русский**

# Manticore Search Query Builder for PHP (неофициальный PHP-клиент)

## ResultSet

Модель ответа на один запрос, доступная только для чтения. `search()`, `create()`, `drop()` и
остальные схемные команды возвращают её напрямую.

### Как получить ResultSet записи

`insert()`, `update()`, `delete()` и `replace()` отвечают так же, как в Laravel — флагом и
числом, — поэтому их `ResultSet` нужно запрашивать отдельно. Способа два, и оба выполняют
ровно один и тот же запрос:

```php
// 1. метод-близнец *ResultSet()
$result = ManticoreDb::table('?products')->insertResultSet(['title' => 'Laptop']);
$result->success();     // получилось ли
$result->result();      // id вставленной строки
$result->error();       // и почему не получилось, если не получилось

// 2. либо последний ResultSet соединения — уже после скалярного вызова
if (!ManticoreDb::table('?products')->insert(['title' => 'Laptop'])) {
    $error = ManticoreDb::lastResultSet()->error();
}
```

Здесь это важнее, чем было бы в Laravel: неудавшаяся **запись** не бросает исключение, о ней
сообщают `success()` и `error()`. Голый `false` от `insert()`/`replace()` и голый `0` от
`update()`/`delete()` причины не несут, а `0` даже не отличает «ничего не подошло под условие»
от «запрос не выполнился». С чтениями всё наоборот — см. [Ошибки](#ошибки) ниже.

Полный набор методов записи — по три на команду там, где id имеет смысл:

| команда | флаг или число | id | ResultSet |
|---|---|---|---|
| INSERT | `insert(): bool` | `insertGetId(): ?int` | `insertResultSet()` |
| REPLACE | `replace(): bool` | `replaceGetId(): ?int` | `replaceResultSet()` |
| UPDATE | `update(): int` | — | `updateResultSet()` |
| DELETE | `delete(): int` | — | `deleteResultSet()` |

`lastResultSet()` принадлежит соединению и хранит последний прошедший через него запрос,
включая служебные: `DESCRIBE`, который запись запрашивает перед сборкой SQL, тоже попадает
туда — но всегда до самой записи, так что запись им не перекрывается.

### result(): mixed
Зависит от последнего запроса:
* набор строк для SELECT;
* boolean для CREATE, DROP, TRUNCATE, OPTIMIZE и ALTER;
* bigint для INSERT и REPLACE (список id, если писали несколько строк);
* число затронутых строк для UPDATE и DELETE;
* массив для остальных.

### command(): ?string
Команда запроса («SELECT», «INSERT», «SHOW TABLES» и т. п.)

### sqlQuery(): ?string
Текст SQL-запроса

### execTime(): ?float
Время выполнения

### error(): ?string
Текст ошибки или предупреждения последнего запроса

### success(): bool
Результат без ошибок и предупреждений

### status(): ?string
Статус последнего запроса

## Методы, специфичные для SELECT

### result(): array
Набор строк

### columns(): array
Имена колонок

### count(): int
Число возвращённых строк

### total(): int
Общее число строк таблицы, подходящих под условие

### first(): array
Первая строка набора

### meta(): array
Метаданные, полученные после SQL-запроса

### facets(): array
Фасеты

## Метод, специфичный для SHOW VARIABLES

### variable($name): mixed
Значение переменной

## Ошибки

Запрос, у которого спросили **строки**, бросает исключение, если сервер его отверг: `get()`,
`first()`, `find()`, `value()`, `pluck()`, `sole()`, `count()`, агрегаты и обходы поднимают
`avadim\Manticore\QueryBuilder\QueryErrorException`. В нём есть и сообщение сервера, и сам
отвергнутый запрос — через `sql()`. Ответ `null` или `0` было бы не отличить от пустой таблицы.

```php
try {
    $rows = ManticoreDb::table('?products')->whereRegex('title', 'galaxy')->get();
}
catch (\avadim\Manticore\QueryBuilder\QueryErrorException $e) {
    $e->getMessage();   // что сказал сервер
    $e->sql();          // какой запрос он отверг
}
```

`exec()` и `search()` по-прежнему отвечают `ResultSet` — ошибку из него читают, а не ловят:

```php
$result = ManticoreDb::table('?products')->whereRegex('title', 'galaxy')->exec();
if (!$result->success()) {
    $error = $result->error();
}
```

Записи устроены по тому же принципу: `insert()`, `update()`, `delete()` и `replace()` отвечают
скаляром, а полный ответ последнего запроса доступен через `lastResultSet()`.
