[English](README.md) | **Русский**

[![GitHub Release](https://img.shields.io/github/v/release/aVadim483/manticore-query-builder-php)](https://packagist.org/packages/avadim/manticore-query-builder-php)
[![Packagist Downloads](https://img.shields.io/packagist/dt/avadim/manticore-query-builder-php?color=%23aa00aa)](https://packagist.org/packages/avadim/manticore-query-builder-php)
[![GitHub License](https://img.shields.io/github/license/aVadim483/manticore-query-builder-php)](https://packagist.org/packages/avadim/manticore-query-builder-php)
[![PHP](https://img.shields.io/badge/php-7.4%20%7C%20%3E%3D8.1-005fc7)](https://packagist.org/packages/avadim/manticore-query-builder-php)

# Manticore Search Query Builder for PHP (неофициальный PHP-клиент)

Самостоятельный построитель запросов к Manticore Search на PHP с цепочками методов,
вдохновлёнными API Laravel.

Возможности
* именованные соединения с Manticore по SQL/MySQL-протоколу через PDO
* плейсхолдеры вместо префиксов в именах таблиц
* именованные параметры в выражениях
* построение запросов цепочками методов
* множественные INSERT и REPLACE
* поддержка MATCH() и многоуровневых WHERE в SELECT
* фасетный поиск, JOIN двух таблиц и векторный поиск (KNN)
* MVA-фильтры ANY/ALL и геопоиск: радиус, расстояние в результатах и сортировка
* типы колонок применяются в обе стороны: значения PHP при записи, типы PHP при чтении
* собственные реализации части помощников с именами, знакомыми по Laravel Query Builder:
  агрегаты, постраничный обход, условное построение запросов, фильтры по датам и upsert
* CALL-помощники: `callAutocomplete()`, `callSuggest()`, `callQsuggest()`, `callKeywords()`,
  `callSnippets()` и `callPq()` (перколятивные запросы)
* методы получения строк бросают исключение при ошибке выполнения; обычная запись
  возвращает статус или число, а подробности доступны через `lastResultSet()`
* PSR-3 логирование запросов и EXPLAIN полнотекстовых выражений
* строки без метаданных через `getRows()`, объект `Meta`
  и необязательное ленивое получение ID при записи

Документация сервера Manticore Search: https://manual.manticoresearch.com/

## 2.7: пакетный импорт и гибридный поиск

В версии [2.7.0](CHANGELOG.md) добавлены [пакетная запись из iterable](docs/ru/writing.md),
[JSON-фильтры и составные полнотекстовые условия](docs/ru/json-fulltext.md),
[расширенный числовой KNN и гибридный поиск](docs/ru/knn-hybrid.md).
Версия [2.7.1](CHANGELOG.md) исправляет русскую и английскую документацию;
поведение библиотеки и зависимости не изменены.

Пример ниже предполагает числовой атрибут `stock`, JSON-массив `info.tags`
и колонку `embedding` с KNN-индексом в таблице `products`. Передайте числовой
`$vector` объявленной размерности; запускаемый пример ниже содержит свою схему и данные.

```php
$result = ManticoreDb::table('products')->where('stock', '>', 0)
    ->whereJsonContains('info.tags', 'sale')
    ->hybridSearch('phone', 'embedding', $vector, [
        'text_candidates' => 200,
        'vector_candidates' => 200,
        'knn_options' => ['ef' => 200],
    ]);
$rows = $result->rows();
$windowSize = $result->windowTotal();
$globalTotal = $result->totalFound(); // null: известен только размер окна кандидатов
```

Приложение передаёт числовой вектор; выдачи объединяются в PHP. Сохраняются PHP 7.4,
старые сигнатуры и поведение чтения/записи по умолчанию. Новому KNN нужна серверная
библиотека KNN; Laravel и внешний провайдер моделей не требуются.

[Запускаемый пример импорта и поиска](examples/README.ru.md) создаёт тестовую таблицу,
загружает генератор пакетами, применяет JSON и полнотекстовые фильтры, объединяет
выдачи, экспортирует JSON и получает страницу окна результатов.

## Смежные пакеты

Это самостоятельный построитель запросов без обязательного фреймворка. Смежные
Laravel-интеграции развиваются отдельно; их версии 4.x используют нативный драйвер Laravel.

* [`avadim/manticore-query-builder-laravel`](https://github.com/aVadim483/manticore-query-builder-laravel) —
  в версии 4.x это нативный SQL-драйвер Laravel с `DB::connection('manticore')`,
  операциями схемы и построителями запросов. Он имеет собственную реализацию
  и не зависит от этого standalone-пакета. Прежние версии обёртки использовали
  этот построитель.
* [`avadim/manticore-laravel-scout`](https://github.com/aVadim483/manticore-laravel-scout) —
  драйвер ManticoreSearch для [Laravel Scout](https://laravel.com/docs/scout): полнотекстовый
  поиск по моделям Eloquent в виде `Post::search('manticore')->get()`. Линейка 4.x
  использует нативный Laravel-драйвер выше.

## Требования

* PHP 7.4 или PHP 8.1 и выше (PHP 8.0 исключён ограничением Composer)
* Расширения PHP: JSON, mbstring, PDO и драйвер PDO MySQL (`pdo_mysql`)
* Manticore Search, доступный по SQL-интерфейсу (по умолчанию порт 9306).
  HTTP/JSON API не используется.

### Что требует большего, чем один сервер

Почти всё в билдере общается только с сервером. Нескольким методам нужна библиотека,
загруженная сервером, либо запущенный рядом Manticore Buddy:

| Метод | Что нужно | Что будет без этого |
|---|---|---|
| всё остальное | только сервер | — |
| `floatVector()`, `whereKnn()`, `knn()`, `hybridSearch()` и его сокращения | библиотека KNN | создание векторной таблицы или поиск завершатся ошибкой |
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

// При ошибке выполнения insert() вернёт false, update()/delete() — 0.
// Причина доступна в последнем ResultSet; ошибки аргументов могут бросать исключение.
if (!ManticoreDb::table('?products')->insert($singleRow)) {
    $error = ManticoreDb::lastResultSet()->error();
}

// get() при ошибке выполнения бросает исключение вместо кажущегося пустым результата
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

## 2.6: строки, метаданные и ID записей

Доступно начиная с [2.6.0](CHANGELOG.md). Сигнатуры существующих методов и поведение
по умолчанию сохраняются.

```php
$query = ManticoreDb::table('?products')->limit(20);
$rows = $query->getRows();       // без SHOW META
$returned = count($rows);       // полученные строки, а не все совпадения
$meta = $query->getMeta();      // необязательный явный SHOW META до следующего поиска
$total = $meta->totalFound();   // доступен и как $meta['total_found']

$result = $query->search();     // строки и метаданные вместе
$array = $result->meta();       // прежний массив
$object = $result->metaObject(); // объект над загруженными метаданными, без SQL
```

`Meta` доступен только для чтения. Его методы возвращают `null` для отсутствующих значений;
`count($meta)` считает поля метаданных. Настройка соединения `lazy_insert_ids` откладывает
получение ID для булевых `insert()`/`replace()` до `lastResultSet()->result()`. Получите
отложенные ID до любой следующей команды на том же соединении. По умолчанию настройка
выключена; `*GetId()` и `*ResultSet()` по-прежнему получают ID сразу.

Полные контракты и примеры: [поиск](docs/ru/searching.md#чтение-без-метаданных),
[ResultSet](docs/ru/result_set.md), [запись документов](docs/ru/writing.md).

## Связь с Laravel Query Builder

Часть имён методов и цепочек вызовов знакома по Laravel Query Builder. Здесь они
реализованы самостоятельно поверх SQL Manticore, со своими типами результатов
и правилами выполнения. Библиотека работает без Laravel и `illuminate/*`.

* `get()` возвращает PHP-массивы. `search()` / `exec()` возвращают `ResultSet`, в том числе
  при ошибке выполнения. Методы непосредственного получения строк, например `get()`
  и `first()`, при ошибке бросают исключение.
* `whereFullText()` / `orWhereFullText()` (с версии 2.7) объединяют текстовые условия
  по полям и по умолчанию экранируют текст. `match()` / `whereMatch()` принимают готовое
  выражение MATCH; для обычного пользовательского текста в этом API нужен `escapeMatch()`.
* `whereRegex()` фильтрует строковые атрибуты. Методы `whereLike()`, `whereColumn()`
  и `distinct()` на уровне запроса отсутствуют. `Facet::distinct()` — отдельная опция фасета.
* `chunk()` и `lazy()` читают страницы; `cursor()` — алиас `lazy()`, а не поток одного
  SELECT. Обход со смещением зависит от настройки `max_matches` запроса.
* `upsert()` сначала ищет совпадение для каждой входной строки, затем делает INSERT/REPLACE.
  Атомарность всей операции автоматически не гарантируется; `uniqueBy` не создаёт
  ограничение уникальности в БД.
* Условия по календарным датам интерпретируются в UTC. `when()` / `unless()` / `tap()`
  возвращают построитель; контракт обработчиков описан в руководстве по поиску.
* `join()` / `leftJoin()` строят соединения по равенству без алиасов таблиц и `joinSub()`.
  Отдельные методы UNION, подзапросов и блокировок строк отсутствуют. Manticore поддерживает
  ограниченные [sub-selects](https://manual.manticoresearch.com/Searching/Sub-selects);
  отсутствие метода построителя само по себе не означает ограничение сервера.

Поддерживаемые аргументы и поведение описаны в разделах о [поиске](docs/ru/searching.md),
[записи](docs/ru/writing.md), [результатах и ошибках](docs/ru/result_set.md)
и [транзакциях](docs/ru/configuration.md).

## Документация

Подробная документация на русском — в [оглавлении](/docs/ru/README.md):
[конфигурация](/docs/ru/configuration.md),
[поиск](/docs/ru/searching.md),
[вставка и замена документов](/docs/ru/writing.md),
[таблицы](/docs/ru/tables.md),
[ResultSet и ошибки](/docs/ru/result_set.md),
[тестирование и Buddy](/docs/ru/testing.md),
[логирование](/docs/ru/logging.md).

## Тесты

```bash
vendor/bin/phpunit --testsuite unit   # построение и разбор SQL, сервер не нужен
vendor/bin/phpunit                    # плюс интеграционные тесты
vendor/bin/phpunit --exclude-group buddy # без автодополнения; rename без Buddy может пропускаться
```

Интеграционные тесты сами создают и удаляют свои таблицы на живом сервере. Если на
`MANTICORE_HOST:MANTICORE_PORT` (по умолчанию `127.0.0.1:9306`) никто не слушает, они
пропускаются, а не падают.

GitHub Actions запускает полный набор на Manticore 28.6.6 с PHP 8.4,
на Manticore 29.9.0 с PHP 7.4 и 8.4, а также на `latest` с PHP 8.4. Перед PHPUnit скрипт
`tests/ci/check-manticore.php` проверяет выполнение SQL, фильтрацию Columnar,
поиск KNN и переименование таблицы через Buddy. Временные таблицы удаляются после
проверки. Запускайте этот скрипт только на тестовом сервере. Время ожидания — 90 секунд
по умолчанию (`MANTICORE_READY_TIMEOUT`, от 1 до 120 секунд); адрес и порт задаются
теми же переменными окружения, что и для интеграционных тестов.

В CI используются `--fail-on-skipped --fail-on-incomplete`: отсутствие компонентов
не позволяет полному прогону завершиться успешно. Локальное поведение пропусков
сохранено. Отчёты JUnit, журнал проверки готовности, логи установки пакета и диагностика контейнера сохраняются
как артефакты на 14 дней, в том числе при ошибке. После попадания workflow в основную
ветку его можно запускать вручную из вкладки Actions. Успешными должны быть все прогоны
фиксированных версий и `latest`.

Workflow запускается при пуше в `main`, для pull request и вручную. Модульная матрица
проверяет PHP 7.4 и 8.1–8.4. Полные прогоны CI включают все Buddy-тесты. Настройка Docker,
проверка готовности и отдельный запуск Buddy-тестов описаны в
[руководстве по тестированию](docs/ru/testing.md).

Каждая интеграционная конфигурация также устанавливает ZIP-дистрибутив коммита в отдельное
Composer-приложение только с runtime-зависимостями и запускает пример из установленного пакета.

## Хотите поддержать?

Если пакет оказался полезным, поставьте звезду на [GitHub](https://github.com/aVadim483/manticore-query-builder-php) :)
