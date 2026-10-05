[English](README.md) | **Русский**

[![GitHub Release](https://img.shields.io/github/v/release/aVadim483/manticore-query-builder-php)](https://packagist.org/packages/avadim/manticore-query-builder-php)
[![Packagist Downloads](https://img.shields.io/packagist/dt/avadim/manticore-query-builder-php?color=%23aa00aa)](https://packagist.org/packages/avadim/manticore-query-builder-php)
[![GitHub License](https://img.shields.io/github/license/aVadim483/manticore-query-builder-php)](https://packagist.org/packages/avadim/manticore-query-builder-php)
[![Static Badge](https://img.shields.io/badge/php-%3E%3D7.4-005fc7)](https://packagist.org/packages/avadim/manticore-query-builder-php)

# Manticore Search Query Builder for PHP (неофициальный PHP-клиент)

Query Builder для Manticore Search на PHP с синтаксисом в стиле Laravel

Возможности
* несколько соединений MySQL через PDO (Manticore прежде всего SQL-сервер)
* плейсхолдеры вместо префиксов в именах таблиц
* именованные параметры в выражениях
* понятный синтаксис в стиле Laravel
* множественные INSERT и REPLACE
* поддержка MATCH() и многоуровневых WHERE в SELECT
* фасетный поиск, JOIN двух таблиц и векторный поиск (KNN)
* MVA-фильтры ANY/ALL и геопоиск: радиус, расстояние в результатах и сортировка
* типы колонок применяются в обе стороны: значения PHP при записи, типы PHP при чтении
* помощники из Laravel Query Builder: агрегаты, постраничные обходы, условное построение
  запроса, условия по датам, upsert и остальные
* CALL-помощники: `callAutocomplete()`, `callSuggest()`, `callQsuggest()`, `callKeywords()`,
  `callSnippets()` и `callPq()` (перколятивные запросы)
* отвергнутое чтение бросает исключение, отвергнутая запись отвечает false или нулём и
  сохраняет причину
* PSR-3 логирование запросов и EXPLAIN полнотекстовых выражений

Документация сервера Manticore Search: https://manual.manticoresearch.com/

## Смежные пакеты

Это самостоятельный построитель запросов: ему нужны только PHP и PDO, а два пакета ниже
построены на нём.

* [`avadim/manticore-query-builder-laravel`](https://github.com/aVadim483/manticore-query-builder-laravel) —
  интеграция с Laravel и Lumen: сервис-провайдер, `config/manticore.php`, именованные соединения,
  фасад и чтения, отвечающие коллекцией объектов-строк.
* [`avadim/manticore-laravel-scout`](https://github.com/aVadim483/manticore-laravel-scout) —
  драйвер ManticoreSearch для [Laravel Scout](https://laravel.com/docs/scout): полнотекстовый
  поиск по моделям Eloquent в виде `Post::search('manticore')->get()`.

## Требования

* PHP 7.4 и выше с расширением PDO
* Manticore Search, доступный по SQL-интерфейсу (по умолчанию порт 9306).
  HTTP/JSON API не используется.

### Что требует большего, чем один сервер

Почти всё в билдере общается только с сервером. Нескольким методам нужна библиотека,
загруженная сервером, либо запущенный рядом Manticore Buddy:

| Метод | Что нужно | Что будет без этого |
|---|---|---|
| всё остальное | только сервер | — |
| `floatVector()`, `whereKnn()` | библиотека KNN | таблица не создастся: *knn library not loaded* |
| `columnar()`, `columnEngine('columnar')`, `engine => 'columnar'` | библиотека Columnar | таблица не создастся: *columnar library not loaded* |
| `rename()` | Manticore Buddy | сервер ответит синтаксической ошибкой |
| `callAutocomplete()` | Manticore Buddy и инфиксы таблицы | сервер отвергнет вызов |

Библиотеки KNN и Columnar входят в [Manticore Columnar Library](https://github.com/manticoresoftware/columnar);
там же поставляется библиотека вторичных индексов — она ускоряет фильтрацию и на API никак не
влияет.

Версия библиотек должна соответствовать демону: он проверяет их ABI при загрузке и отвергает
слишком новую, сообщая об этом только при запуске с `--console`. Какие библиотеки загрузились,
видно в строке версии:

```sql
SHOW STATUS LIKE 'version';
-- 28.6.6 … (columnar 13.8.3 …) (secondary 13.8.3 …) (knn 13.8.3 …)
```

Если библиотеки нет в этой строке — она не загружена, сколько бы файлов ни лежало на диске.

## Установка

```bash
composer require avadim/manticore-query-builder-php
```

## Быстрый старт

```php
use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use avadim\Manticore\QueryBuilder\Schema\SchemaTable;

// конфигурация
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
    ],
];

// инициализация билдера
ManticoreDb::init($config);

// создание таблицы
ManticoreDb::create('?products', function (SchemaTable $table) {
    $table->timestamp('created_at');
    $table->string('manufacturer');
    $table->text('title');
    $table->json('info');
    $table->float('price');
    $table->multi('categories');
    $table->bool('on_sale');
});

// вставка одной строки
$singleRow = [
    'created_at' => time(),
    'manufacturer' => 'Samsung',
    'title' => 'Galaxy S23 Ultra',
    'info' => ['color' => 'Red', 'storage' => 512],
    'price' => 1199.00,
    'categories' => [5, 7, 11],
    'on_sale' => true,
];
$id = ManticoreDb::table('?products')->insertGetId($singleRow);
// insert() возвращает true/false, insertGetId() — <id> новой записи

// вставка нескольких строк
$multipleRows = [
    [
        'created_at' => time(),
        'manufacturer' => '...',
        'title' => '...',
        'info' => [],
        // ...
    ],
    [
        'created_at' => time(),
        'manufacturer' => '...',
        'title' => '...',
        'info' => [],
        // ...
    ],
];
$res = ManticoreDb::table('?products')->insertResultSet($multipleRows);
// $res->result() => массив <id> новых записей

// update и delete отвечают числом затронутых строк
$updated = ManticoreDb::table('?products')->where('price', '<', 100)->update(['on_sale' => true]);
$deleted = ManticoreDb::table('?products')->where('price', '<', 1)->delete();

// неудавшаяся запись не бросает исключение: insert() вернёт false, update()/delete() — 0,
// а причина останется в ResultSet
if (!ManticoreDb::table('?products')->insert($singleRow)) {
    $error = ManticoreDb::lastResultSet()->error();
}

// неудавшееся чтение, наоборот, бросает: null вместо строк было бы не отличить от пустой таблицы
try {
    $rows = ManticoreDb::table('?products')->where('nosuchcolumn', 1)->get();
}
catch (\avadim\Manticore\QueryBuilder\QueryErrorException $e) {
    $error = $e->getMessage();
}

// поиск: get() возвращает строки, ключами которых служат id документов, а значения
// приведены к типам PHP ('info' — массив, 'categories' — int[], 'on_sale' — bool)
$rows = ManticoreDb::table('?products')->match('galaxy')->where('price', '>', 1100)->get();
```

## Чем отличается от Laravel Query Builder

Синтаксис намеренно повторяет ларавелевский, но несколько вещей сервер Manticore делает иначе,
и это влияет на API:

* `whereLike()` нет — `LIKE` в `WHERE` Manticore не принимает. Вместо него `whereRegex()` для
  строковых атрибутов и `match()` / `whereMatch()` для полнотекстовых полей;
* `whereFullText()` нет по той же причине: его аргумент — обычный текст, а `match()` принимает
  выражение языка запросов. Пользовательский ввод нужно пропускать через `escapeMatch()`;
* `whereColumn()` и `distinct()` невозможны: сервер не сравнивает колонку с колонкой в `WHERE`
  и не поддерживает `SELECT DISTINCT`;
* `JOIN` есть, но без алиасов таблиц, без подзапросов и только по равенству;
* `UNION`, подзапросы и блокировки строк сервером не поддерживаются.

Всё остальное — `where`-семейство, агрегаты, `chunk()` / `lazy()`, `when()` / `unless()`,
`upsert()`, `updateOrInsert()`, условия по датам, транзакции — работает так же, как принято в
Laravel.

## Документация

Подробная документация на русском — в папке [/docs/ru](/docs/ru):
[конфигурация](/docs/ru/configuration.md),
[поиск](/docs/ru/searching.md),
[таблицы](/docs/ru/tables.md),
[ResultSet и ошибки](/docs/ru/result_set.md),
[логирование](/docs/ru/logging.md).

## Тесты

```bash
vendor/bin/phpunit --testsuite unit   # построение и разбор SQL, сервер не нужен
vendor/bin/phpunit                    # плюс интеграционные тесты
vendor/bin/phpunit --exclude-group buddy # без новых тестов автодополнения на сервере без Buddy
```

Интеграционные тесты сами создают и удаляют свои таблицы на живом сервере. Если на
`MANTICORE_HOST:MANTICORE_PORT` (по умолчанию `127.0.0.1:9306`) никто не слушает, они
пропускаются, а не падают.

GitHub Actions запускает полный набор на Manticore 29.9.0 с PHP 7.4 и 8.4,
а также отдельный прогон на `latest` с PHP 8.4. Перед PHPUnit скрипт
`tests/ci/check-manticore.php` проверяет выполнение SQL, фильтрацию Columnar,
поиск KNN и переименование таблицы через Buddy. Временные таблицы удаляются после
проверки. Запускайте этот скрипт только на тестовом сервере. Время ожидания — 90 секунд
по умолчанию (`MANTICORE_READY_TIMEOUT`, от 1 до 120 секунд); адрес и порт задаются
теми же переменными окружения, что и для интеграционных тестов.

В CI используются `--fail-on-skipped --fail-on-incomplete`: отсутствие компонентов
не позволяет полному прогону завершиться успешно. Локальное поведение пропусков
сохранено. Отчёты JUnit, журнал проверки готовности и диагностика контейнера сохраняются
как артефакты на 14 дней, в том числе при ошибке. После попадания workflow в основную
ветку его можно запускать вручную из вкладки Actions. Успешными должны быть прогоны
и фиксированной версии, и `latest`.

## Хотите поддержать?

Если пакет оказался полезным, поставьте звезду на [GitHub](https://github.com/aVadim483/manticore-query-builder-php) :)
