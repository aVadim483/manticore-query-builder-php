[English](../tables.md) | **Русский**

# Manticore Search Query Builder for PHP (неофициальный PHP-клиент)

Содержание:
* [Создание таблицы](#создание-таблицы)
* [Изменение таблицы](#изменение-таблицы)
* [Удаление таблиц](#удаление-таблиц)
* [SHOW TABLES](#show-tables)
* [SHOW CREATE TABLE](#show-create-table)
* [DESCRIBE TABLE](#describe-table)
* [SHOW TABLE STATUS](#show-table-status)
* [SHOW TABLE SETTINGS](#show-table-settings)

## Создание таблицы

Ниже приведены альтернативные способы создания таблицы; выберите один из них.

```php
use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use avadim\Manticore\QueryBuilder\Schema\SchemaTable;

// сырой SQL-запрос
$res = ManticoreDb::sql("create table products(title text, price float engine='columnar') engine='rowwise'")->exec();

// схему таблицы можно описать замыканием
$res = ManticoreDb::create('demo_test', function (SchemaTable $table) {
    // колонки
    $table->text('title')->stored();
    $table->text('article')->indexed();
    $table->string('country')->attribute();
    $table->timestamp('time')->columnar();
    $table->json('data');
    $table->float('price');
    $table->multi('list');
    $table->bool('boo');
    $table->floatVector('embedding', 384);   // векторная колонка для KNN-поиска

    // настройки таблицы
    $table->tableEngine('rowwise');
    $table->tableMorphology(['lemmatize_uk_all', 'lemmatize_de_all']);
    $table->tableOptions(['html_strip' => 1, 'html_index_attrs' => 'img=alt,title; a=title;']);
});

// либо массивом с описанием полей
$fields = [
    // 'имя_поля' => 'тип_поля',
    // либо 'имя_поля' => [опции],
    'title' => 'text stored',
    'article' => ['type' => 'text', 'indexed'],
    'country' => ['type' => 'string', 'attribute', 'fast_fetch' => 0],
    'time' => ['type' => 'timestamp', 'engine' => 'columnar'],
    'data' => 'json',
    'price' => 'float',
    'list' => 'multi',
    'boo' => 'bool',
];
// таблица без опций
$res = ManticoreDb::create('demo_test', $fields);
$res = ManticoreDb::table('demo_test')->create($fields);

// таблица с опциями
$options = [
    'morphology' => 'lemmatize_uk_all,lemmatize_de_all',
    'html_strip' => 1,
    'html_index_attrs' => 'img=alt,title; a=title;'
];
$res = ManticoreDb::table('demo_test')->options($options)->create($fields);
$res = ManticoreDb::create('demo_test', $fields, $options);

$res = ManticoreDb::createIfNotExists('demo_test', $fields, $options);
if (!ManticoreDb::hasTable('demo_test')) {
    $res = ManticoreDb::create('demo_test', $fields, $options);
}
```

## Изменение таблицы

Колонки добавляются, удаляются и расширяются по одной — сервер выполняет одну операцию на
один `ALTER`.

```php
// добавить колонку; тип пишется так же, как в create()
$res = ManticoreDb::table('products')->addColumn('group_id', 'integer');
$res = ManticoreDb::table('products')->addColumn('title', 'text indexed stored');
$res = ManticoreDb::table('products')->addColumn('article', 'text', 'indexed');
$res = ManticoreDb::table('products')->addColumn('time', ['type' => 'timestamp', 'engine' => 'columnar']);

// удалить колонку или несколько
$res = ManticoreDb::table('products')->dropColumn('price');
$res = ManticoreDb::table('products')->dropColumn(['price', 'qty']);

// расширить целочисленную колонку с int до bigint
$res = ManticoreDb::table('products')->modifyColumn('group_id', 'bigint');

// изменить полнотекстовые настройки таблицы (колонки не затрагиваются)
$res = ManticoreDb::table('products')->alterSettings(['html_strip' => 1, 'morphology' => ['lemmatize_en_all']]);

// переименовать таблицу (нужен Manticore Buddy, см. таблицу требований в README)
$res = ManticoreDb::table('?products')->rename('?goods');

// те же операции через фасад — имя таблицы первым аргументом
$res = ManticoreDb::addColumn('products', 'group_id', 'integer');
$res = ManticoreDb::dropColumn('products', 'price');
$res = ManticoreDb::modifyColumn('products', 'group_id', 'bigint');
$res = ManticoreDb::alterSettings('products', ['html_strip' => 1]);
$res = ManticoreDb::rename('?products', '?goods');
```

О чём стоит помнить:

* используйте конкретные методы выше: общий метод `alter()` не реализован;
* `modifyColumn()` отправляет серверу изменение типа, не удаляя и не создавая колонку заново.
  Неподдерживаемое преобразование возвращает ошибку сервера;
* новый скалярный атрибут заполняется пустым значением своего типа в уже существующих строках,
  и во время добавления колонки таблица недоступна для запросов;
* колонку `id` нельзя ни удалить, ни изменить;
* поле, которое одновременно является полнотекстовым и строковым атрибутом, требует двух
  удалений одного имени: `dropColumn(['title', 'title'])`;
* `ALTER` не транзакционен. Если отправлена цепочка операций (`dropColumn()` с несколькими
  именами), она останавливается на первой ошибке, предыдущие остаются применёнными, а
  `$res->sqlQuery()` показывает запросы, которые действительно дошли до сервера;
* ошибки сервера при этих изменениях схемы возвращаются в `ResultSet`: `$res->success()`
  вернёт `false`, а `$res->error()` — текст ошибки. Недопустимые аргументы могут вызвать
  исключение до отправки SQL.

```php
$res = ManticoreDb::table('products')->dropColumn('nonexistent');
if (!$res->success()) {
    echo $res->error();
}
```

## Удаление таблиц

```php
$res = ManticoreDb::table('test')->drop();
$res = ManticoreDb::table('test')->dropIfExists();

$res = ManticoreDb::drop('test');
$res = ManticoreDb::dropIfExists('test');
```

## Список таблиц

### SHOW TABLES

```php
// обычный SQL
$res = ManticoreDb::sql('SHOW TABLES')->get();
// либо методом
$res = ManticoreDb::showTables();
```
| Index         | Table         | Name        | Type |
|---------------|---------------|-------------|------|
| test_products | test_products |  ?products  | rt   |

При настроенном префиксе `test_` поле `Name` заменяет этот префикс на `?`.

```php
// таблицы по шаблону
$res = ManticoreDb::sql("SHOW TABLES LIKE 'abc%'")->get();
$res = ManticoreDb::showTables('abc%');

// таблицы с префиксом
$res = ManticoreDb::showTables();
// ... то же самое, что
$res = ManticoreDb::showTables('?%');

// все таблицы (префикс игнорируется)
$res = ManticoreDb::showTables('%');
```

### SHOW CREATE TABLE

```php
$res = ManticoreDb::showCreate('test');
// результат — строка вида
// "CREATE TABLE test (
//      price float,
//      title text,
//      tags text
//  ) morphology='lemmatize_ru_all,lemmatize_en_all'"
```

### DESCRIBE TABLE

```php
$res = ManticoreDb::sql('DESC test')->get();
$res = ManticoreDb::table('test')->describe()->result();
$res = ManticoreDb::tableDescribe('test');
```

Первые два варианта возвращают список строк. `tableDescribe()` использует `Field` как ключ
для каждой строки.

| Field   | Type     | Properties     |
|---------|----------|----------------|
| id      | bigint   |                |
| title   | text     | indexed stored |
| price   | float    |                |

### SHOW TABLE STATUS

```php
$res = ManticoreDb::sql('SHOW TABLE test STATUS')->get();
$res = ManticoreDb::table('test')->status()->result();
$res = ManticoreDb::table('other')->status('test')->result(); // аргумент важнее, чем table()
$res = ManticoreDb::tableStatus('test');
```

Варианты через запрос возвращают строки с `Variable_name` и `Value`. `tableStatus()`
возвращает ассоциативный массив с именами переменных в ключах.

### SHOW TABLE SETTINGS

```php
$res = ManticoreDb::sql('SHOW TABLE test SETTINGS')->get();
$res = ManticoreDb::table('test')->settings()->result();
$res = ManticoreDb::table('other')->settings('test')->result(); // аргумент важнее, чем table()
$res = ManticoreDb::tableSettings('test');
```

Варианты через запрос возвращают строки с `Variable_name` и `Value`. `tableSettings()`
возвращает ассоциативный массив с именами настроек в ключах. Методы инспекции фасада
(`showTables()`, `showCreate()`, `tableDescribe()`, `tableStatus()` и `tableSettings()`)
бросят `QueryErrorException`, если сервер вернёт ошибку.
