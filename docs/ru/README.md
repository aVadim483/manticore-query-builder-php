# Manticore Search Query Builder for PHP (неофициальный PHP-клиент)

Query Builder для Manticore Search на PHP с синтаксисом в стиле Laravel.

## Документация

---

* [Конфигурация, транзакции и сырые выражения](configuration.md)
* [Поиск](searching.md)
* [Создание, список и описание таблиц](tables.md)
* [Класс ResultSet и ошибки](result_set.md)
* [Логирование](logging.md)

The English documentation is in the [parent folder](../README.md).

## Коротко о главном

```php
use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;

ManticoreDb::init($config);

// поиск: get() возвращает строки, ключами которых служат id документов,
// а значения приведены к типам PHP по схеме таблицы
$rows = ManticoreDb::table('?products')->match('galaxy')->where('price', '>', 1100)->get();

// запись отвечает скаляром, а причина неудачи остаётся в ResultSet
if (!ManticoreDb::table('?products')->insert($row)) {
    $error = ManticoreDb::lastResultSet()->error();
}

// чтение, отвергнутое сервером, бросает исключение: null было бы не отличить от пустой таблицы
try {
    $rows = ManticoreDb::table('?products')->where('nosuchcolumn', 1)->get();
}
catch (\avadim\Manticore\QueryBuilder\QueryErrorException $e) {
    $error = $e->getMessage();
}
```

### Чем отличается от Laravel Query Builder

Синтаксис намеренно повторяет ларавелевский, но несколько вещей движок делает иначе, и это
влияет на API:

* `whereLike()` нет — `LIKE` в `WHERE` Manticore не принимает. Вместо него `whereRegex()` для
  строковых атрибутов и `match()` / `whereMatch()` для полнотекстовых полей;
* `whereFullText()` нет по той же причине: его аргумент — обычный текст, а `match()` принимает
  выражение языка запросов. Пользовательский ввод нужно пропускать через `escapeMatch()`;
* `whereColumn()` и `distinct()` невозможны: сервер не сравнивает колонку с колонкой в `WHERE`
  и не поддерживает `SELECT DISTINCT`;
* `JOIN` есть, но без алиасов таблиц, без подзапросов и только по равенству;
* `UNION`, подзапросы и блокировки строк не поддерживаются движком.

Всё остальное — `where`-семейство, агрегаты, `chunk()` / `lazy()`, `when()` / `unless()`,
`upsert()`, `updateOrInsert()`, условия по датам, транзакции — работает так же, как принято в
Laravel.
