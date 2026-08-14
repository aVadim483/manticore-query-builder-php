# Manticore Search Query Builder for PHP (неофициальный PHP-клиент)

Содержание:
* [Получение строк из таблицы](#получение-строк-из-таблицы)
* [Одна строка или одна колонка](#одна-строка-или-одна-колонка)
* [Как посмотреть SQL](#как-посмотреть-sql)
* [SELECT-запросы](#select-запросы)
* [Условия поиска](#условия-поиска)
* [limit() и offset()](#limit-и-offset)
* [orderBy() и orderByDesc()](#orderby-и-orderbydesc)
* [groupBy() и having()](#groupby-и-having)
* [maxMatches()](#maxmatches)
* [explain()](#explain)
* [Соединение таблиц](#соединение-таблиц)
* [Условия по датам](#условия-по-датам)
* [Агрегаты и одиночные значения](#агрегаты-и-одиночные-значения)
* [Обход большой выборки](#обход-большой-выборки)
* [Условное построение запроса](#условное-построение-запроса)
* [Работа с JSON-атрибутами](#работа-с-json-атрибутами)
* [Фасетный поиск](#фасетный-поиск)

## Получение строк из таблицы

```php
use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;

// возвращает объект ResultSet
$res = ManticoreDb::table('?products')->match('galaxy')->where('price', '>', 1100)->exec();
// $res->result() возвращает набор строк
// если среди колонок есть id, его значения становятся ключами набора
foreach ($res->result() as $id => $row) {
    // $id — идентификатор найденной записи
    // $row — массив <имя_поля> => <значение>
    // плюс служебное поле "_score", когда в запросе есть match()
}

// без полнотекстового условия weight() равен единице у всех строк, поэтому "_score"
// выбирается только для полнотекстового запроса. В обе стороны это можно задать явно:
$res = ManticoreDb::table('?products')->where('price', '>', 1100)->withScore()->get();
$res = ManticoreDb::table('?products')->match('galaxy')->withoutScore()->get();

// выбрать указанные колонки и вернуть ResultSet
$res = ManticoreDb::table('?products')->match('galaxy')->where('price', '>', 1100)->search(['name', 'price']);

// вернуть массивы, то же самое, что exec()->result()
$res = ManticoreDb::table('?products')->match('galaxy')->where('price', '>', 1100)->get();

// то же, что search('*')->result()
$res = ManticoreDb::table('?products')->match('galaxy')->where('price', '>', 1100)->get('*');

// ... либо по частям
$query = ManticoreDb::table('?products')->match('galaxy');
$query->where('price', '>', 1100);
$res = $query->get('*');
```

## Одна строка или одна колонка

```php
// первая строка, подходящая под условия
$record = ManticoreDb::table('?products')->match('galaxy')->where('price', '>', 500)->first();

// список значений колонки 'name'
$names = ManticoreDb::table('?products')->match('galaxy')->where('price', '>', 500)->pluck('name');
// ['Galaxy S10', 'Galaxy S20', ...]

// второй аргумент задаёт ключи этого списка
$names = ManticoreDb::table('?products')->match('galaxy')->pluck('name', 'id');
// [128 => 'Galaxy S10', 129 => 'Galaxy S20', ...]
```

Выбираются только запрошенные колонки, поэтому `pluck()` переопределяет заданный ранее
`select()`.

## Как посмотреть SQL

```php
$query = ManticoreDb::table('?products')->where('country=:country')->bind([':country' => 'de']);

// запрос в том виде, в каком он построен, с именованными параметрами bind() на месте
$query->toSql();      // SELECT * FROM test_products WHERE (country=:country)

// запрос в том виде, в каком он уходит на сервер, с подставленными параметрами
$query->toRawSql();   // SELECT * FROM test_products WHERE (country=de)
```

Значения условий попадают в запрос при его сборке в любом случае — они не биндятся, — поэтому
две формы различаются только для запроса с `bind()`.

## SELECT-запросы

```php
// возвращает объект ResultSet
$res = ManticoreDb::table('?products')->match('galaxy')->where('price', '>', 1100)->select(['id', 'name', 'price'])->exec();
// то же самое короче
$res = ManticoreDb::table('?products')->match('galaxy')->where('price', '>', 1100)->search(['id', 'name', 'price']);

// возвращает набор строк
$res = ManticoreDb::table('?products')->match('galaxy')->where('price', '>', 1100)->select(['id', 'name', 'price'])->get();
// то же самое короче
$res = ManticoreDb::table('?products')->match('galaxy')->where('price', '>', 1100)->get(['id', 'name', 'price']);
```

Список колонок можно записать любым из этих способов, результат одинаковый (`search()` и
`get()` принимают те же аргументы):
```php
$query->select(['id', 'name', 'price']);
$query->select('id, name, price');
$query->select('id', 'name', 'price');
```
Запятые внутри скобок и кавычек список не разбивают, так что выражение остаётся целым:
```php
// SELECT id, IN(color, 1, 2) as f FROM ?products
$query->select('id, IN(color, 1, 2) as f');
```
Обратите внимание: `select()` заменяет предыдущий список колонок, а не дополняет его. Дополняет
`addSelect()`, а `selectRaw()` добавляет выражение как есть.

## Условия поиска

### MATCH

Метод `match()` выполняет полнотекстовый поиск по текстовым полям:
```php
$res = ManticoreDb::table('zoo')->match('cats|birds')->get();
$res = ManticoreDb::table('zoo')->match('looking for ( cat | dog | mouse )')->get();
$res = ManticoreDb::table('articles')->match('hello MAYBE world')->get();
$res = ManticoreDb::table('articles')
    ->match('"hello world" @title "example program"~5 @body python -(php|perl) @* code')
    ->get();
```

### WHERE

```php
// SELECT * FROM ?products WHERE price <= 999.0;
$res = ManticoreDb::table('?products')->where('price', '<=', 999.0)->get();

// SELECT * FROM ?products WHERE price <= 999.0 AND color='red';
$res = ManticoreDb::table('?products')->where('price', '<=', 999.0)->andWhere('color', '=', 'red')->get();

// SELECT * FROM ?products WHERE price <= 999.0 OR price >= 1100.0;
$res = ManticoreDb::table('?products')->where('price', '<=', 999.0)->orWhere('price', '>=', 1100)->get();

// SELECT * FROM ?products WHERE price BETWEEN 999.0 AND 1100.0;
$res = ManticoreDb::table('?products')->whereBetween('price', [999, 1100])->get();

// SELECT * FROM ?products WHERE updated_at IS NOT NULL;
$res = ManticoreDb::table('?products')->whereNotNull('updated_at')->get();

// SELECT * FROM ?products WHERE info.color IS NULL;
// значение null означает IS NULL и в двух-, и в трёхаргументной форме
$res = ManticoreDb::table('?products')->where('info.color', null)->get();
$res = ManticoreDb::table('?products')->where('info.color', '=', null)->get();
// ... а "!=" / "<>" с null дают IS NOT NULL. Любой другой оператор с null бросает
// InvalidArgumentException: выражение "price > null" не во что компилировать.

// SELECT * FROM ?products WHERE (color='red' AND price=10);
// массив — это набор условий, объединённых через AND
$res = ManticoreDb::table('?products')->where(['color' => 'red', 'price' => 10])->get();
// то же с явными операторами
$res = ManticoreDb::table('?products')->where([['price', '>', 10], ['color', 'red']])->get();
```

### Поиск того, что ввёл пользователь

Аргумент `match()` — это выражение на языке запросов Manticore, а не обычный текст: `|` —
альтернатива, `-` исключает слово, кавычки задают фразу, `@` ограничивает поиск полем. Текст
из строки поиска нужно сначала превратить в литерал, иначе он означает не то, что ввели, — а
непарная кавычка и вовсе заставит сервер отвергнуть запрос:

```php
$found = ManticoreDb::table('?products')->match(ManticoreDb::escapeMatch($request->get('q')))->get();
```

| Ввод | `match($input)` | `match(escapeMatch($input))` |
|---|---|---|
| `iPhone -Pro` | исключает «Pro» | ищет слова так, как они введены |
| `iPhone\|Galaxy` | любое из слов | оба слова |
| `iPhone " 15` | запрос отвергнут сервером | ищет слова |

Поиск можно ограничить конкретными полнотекстовыми полями вторым аргументом — это оператор
`@(title,description)` языка запросов:

```php
$res = ManticoreDb::table('?products')->match('galaxy', 'title')->get();
$res = ManticoreDb::table('?products')->match('galaxy', ['title', 'description'])->get();
```

Метода `whereFullText()` из Laravel здесь нет намеренно: там `$value` — обычный текст, а тут та
же строка является выражением, и `MATCH` в запросе может быть только один, а не одно условие
среди прочих. `whereMatch()` — тот же `match()`, названный в стиле семейства `where*()`.

### Сопоставление по образцу: whereRegex() и whereMatch()

Метода `whereLike()` нет, потому что Manticore не принимает `LIKE` в `WHERE` вообще. Что он
принимает — это `REGEX()` по строковым атрибутам и полнотекстовый поиск по текстовым полям. Это
два разных механизма, поэтому билдер называет их по-разному, а не прячет оба за привычным
именем:

```php
// строковый атрибут, сопоставление регулярным выражением
$res = ManticoreDb::table('?products')->whereRegex('manufacturer', '^acme')->get();
$res = ManticoreDb::table('?products')->orWhereRegex('manufacturer', '^other')->get();
$res = ManticoreDb::table('?products')->whereNotRegex('manufacturer', '^acme')->get();

// текстовое поле, поиск по полнотекстовому индексу — псевдоним match()
$res = ManticoreDb::table('?products')->whereMatch('galaxy')->get();
```

Три вещи, которые стоит помнить про `whereRegex()`:

* он работает **только по строковым атрибутам**. Полнотекстовое поле атрибутом не является, и
  `REGEX` по нему сервер отвергает — чтение тогда бросает `QueryErrorException` с этим текстом;
* он **чувствителен к регистру**, в отличие от `LIKE` в MySQL. Чтобы это изменить, добавьте в
  начало шаблона флаг `(?i)`: `whereRegex('manufacturer', '(?i)^acme')`;
* он **не использует индекс** — проверяется каждое значение атрибута, то есть это полный
  перебор.

Шаблон не привязан к границам значения: он совпадает в любом месте, пока `^` и `$` не скажут
иначе. Шаблон экранируется как значение, поэтому кавычка или обратный слэш в нём безопасны.

`orWhereMatch()` и `whereNotMatch()` не сделаны намеренно: `MATCH` — это отдельная клауза, а не
условие `WHERE`, и Manticore принимает её одну на запрос. Альтернативы и отрицание пишутся
внутри самого выражения: `match('acme|corp')`, `match('acme -corp')`.

Остальные помощники, знакомые по Laravel, тоже на месте:
```php
// SELECT * FROM ?products WHERE NOT((color='red'))
$res = ManticoreDb::table('?products')->whereNot('color', 'red')->get();
// точно так же можно отрицать целую группу
$res = ManticoreDb::table('?products')->whereNot(function ($condition) {
    $condition->where('color', 'red')->orWhere('color', 'green');
})->get();

// совпадает хотя бы одна из колонок
$res = ManticoreDb::table('?products')->whereAny(['title', 'manufacturer'], 'acme')->get();
// совпадают все
$res = ManticoreDb::table('?products')->whereAll(['price', 'qty'], '>', 10)->get();
// не совпадает ни одна
$res = ManticoreDb::table('?products')->whereNone(['price', 'qty'], '>', 10)->get();

// условие как оно написано
$res = ManticoreDb::table('?products')->whereRaw('price > 100 AND qty < 10')->get();
```

Иногда несколько условий нужно сгруппировать скобками, чтобы получить нужную логику. Для этого
в `where()` передаётся замыкание:
```php
// SELECT * FROM products WHERE ((price>999 AND color='red') OR (price<999 AND (color='green' OR color='black')))
$res = ManticoreDb::table('products')
    ->where(function($condition) {
        $condition->where('price', '>', 999);
        $condition->where('color', '=', 'red');
    })
    ->orWhere(function($condition) {
        $condition->where('price', '<', 999);
        $condition->where(function($condition) {
            $condition->where('color', 'green');
            $condition->where('color', 'black');
        });
    })
    ->get();
```
Внутри замыкания доступны те же методы, что и у самого билдера, включая `whereIn()`,
`whereNull()` и `whereBetween()`:
```php
$res = ManticoreDb::table('products')
    ->where(function($condition) {
        $condition->whereIn('country', ['de', 'us']);
        $condition->orWhereNull('info.color');
    })
    ->get();
```

Доступные методы:
* where(\<поле>, \<оператор>, \<значение>)
* where(\<поле>, \<значение>) => where(\<поле>, '=', \<значение>)
* where(\<поле>, null) => where(\<поле>, 'IS NULL')
* where(\<массив>) — набор условий через AND
* where(\<замыкание>)
* andWhere(\<поле>, \<оператор>, \<значение>)
* andWhere(\<поле>, \<значение>) => andWhere(\<поле>, '=', \<значение>)
* andWhere(\<массив>)
* andWhere(\<замыкание>)
* orWhere(\<поле>, \<оператор>, \<значение>)
* orWhere(\<поле>, \<значение>) => orWhere(\<поле>, '=', \<значение>)
* orWhere(\<массив>)
* orWhere(\<замыкание>)
* whereNull(\<поле>)
* andWhereNull(\<поле>)
* orWhereNull(\<поле>)
* whereNotNull(\<поле>)
* andWhereNotNull(\<поле>)
* orWhereNotNull(\<поле>)

`IS NULL` / `IS NOT NULL` задаются либо отдельными методами, либо значением `null`: в
двухаргументной форме `where('updated_at', 'IS NULL')` второй аргумент — это значение, а не
оператор, поэтому такой вызов сравнивает колонку со строкой `'IS NULL'`. Учтите также, что
`IS NULL` применим к атрибутам (включая ключи JSON вроде `info.color`), но не к полнотекстовым
полям.

* whereIn(\<поле>, \<массив>)
* andWhereIn(\<поле>, \<массив>)
* orWhereIn(\<поле>, \<массив>)
* whereNotIn(\<поле>, \<массив>)
* andWhereNotIn(\<поле>, \<массив>)
* orWhereNotIn(\<поле>, \<массив>)
* whereBetween(\<поле>, \<массив>)
* andWhereBetween(\<поле>, \<массив>)
* orWhereBetween(\<поле>, \<массив>)
* whereNotBetween(\<поле>, \<массив>)
* andWhereNotBetween(\<поле>, \<массив>)
* orWhereNotBetween(\<поле>, \<массив>)

### limit() и offset()
```php
$res = ManticoreDb::table('?products')->match('phone')->limit(100)->get();
// порядок вызовов не важен
$res = ManticoreDb::table('?products')->match('phone')->limit(100)->offset(500)->get();
$res = ManticoreDb::table('?products')->match('phone')->offset(500)->limit(100)->get();
// limit(<offset>, <limit>) задаёт оба значения сразу
$res = ManticoreDb::table('?products')->match('phone')->limit(500, 100)->get();
```
`offset()` без `limit()` бросает `LogicException`: собственного `OFFSET` в Manticore нет
(`SELECT ... OFFSET 5` — синтаксическая ошибка), а обходной приём MySQL с огромным `LIMIT`
заставляет сервер игнорировать смещение — то есть запрошенную страницу вернуть невозможно, и
сказать об этом лучше, чем молча отдать первую.

`take()` и `skip()` — псевдонимы этих методов, а `forPage()` задаёт окно целиком:
```php
// LIMIT 30,15 — третья страница по пятнадцать строк
$res = ManticoreDb::table('?products')->forPage(3, 15)->get();
```

### orderBy() и orderByDesc()
```php
// ORDER BY price ASC
$query->orderBy('price')->get();
// ORDER BY created_at DESC — две записи равнозначны
$query->orderByDesc('created_at')->get();
$query->orderBy('created_at', 'desc')->get();
```
Можно передать несколько колонок сразу, направление применится ко всем:
```php
// ORDER BY price DESC,id DESC
$query->orderBy('price, id', 'desc')->get();
$query->orderByDesc(['price', 'id'])->get();
```
Направление, записанное в самом выражении, сохраняется как есть: `orderBy('price DESC')` даёт
`ORDER BY price DESC`, а не `price DESC ASC`. Любое направление, кроме `asc` / `desc`, бросает
`InvalidArgumentException`.

```php
// ORDER BY created_at DESC / ASC
$query->latest()->get();
$query->oldest()->get();
// по другой колонке
$query->latest('published_at')->get();

// ORDER BY RAND()
$query->inRandomOrder()->get();

// выражение как оно написано
$query->orderByRaw('WEIGHT() DESC, price ASC')->get();

// сбросить заданную сортировку, при желании задав вместо неё другую
$query->reorder()->get();
$query->reorder('id', 'desc')->get();
```

### groupBy() и having()
```php
// SELECT country, count(*) as cnt FROM ?products GROUP BY country HAVING cnt > 2
$res = ManticoreDb::table('?products')
    ->select(['country', 'count(*) as cnt'])
    ->groupBy('country')
    ->having('cnt', '>', 2)
    ->get();
```
`groupBy()` принимает список колонок в тех же формах, что и `select()`:
```php
$query->groupBy('country', 'brand');
$query->groupBy(['country', 'brand']);
$query->groupBy('country, brand');
```
`having()` принимает такие формы; значение в трёх- и двухаргументной экранируется и берётся в
кавычки, а одноаргументная передаётся как сырое выражение:
```php
$query->having('cnt', '>', 2);
$query->having('cnt', 2);                 // => having('cnt', '=', 2)
$query->having('cnt', 'IN', [2, 3]);      // HAVING cnt IN (2,3)
$query->having('cnt', 'BETWEEN', [2, 5]); // HAVING cnt BETWEEN 2 AND 5
$query->having('cnt > 2');                // сырое выражение
$query->havingRaw('COUNT(*) > 1');        // то же самое явно
```
Задать можно только **одно** выражение — больше сервер и не принимает: его синтаксис —
`[HAVING where_condition]` с единственным условием, а `HAVING a AND b`, `HAVING (a AND b)` и
`HAVING a, b` отвергаются как синтаксические ошибки. Поэтому повторный вызов `having()` бросает
`LogicException`, вместо того чтобы собирать запрос, который Manticore откажется выполнять.
Учтите также, что условие работает по выражению группировки — алиасу агрегата, `COUNT(*)` или
`GROUPBY()`, — а не по произвольной колонке.

### maxMatches()
Задаёт `max_matches` для поиска.
```php
$query->maxMatches(10000);
```

### explain()
Показывает, во что превращается полнотекстовое выражение `match()`, не выполняя сам поиск.
Полезно, когда запрос находит не то, что ожидалось.
```php
$res = ManticoreDb::table('?products')->match('brown fox')->explain();

$res->variable('transformed_tree');
// AND(
//   AND(KEYWORD(brown, querypos=1)),
//   AND(KEYWORD(fox, querypos=2)))
```
Формат можно задать аргументом, например `dot` для graphviz:
```php
$res = ManticoreDb::table('?products')->match('brown | fox')->explain('dot');
// digraph "transformed_tree" { 0 [shape=record,style=filled label="OR"] ... }
```
Строки `$res->result()` содержат дерево под ключами `Variable_name` / `Value` — так же, как
`tableStatus()` и `tableSettings()` возвращают свои значения.

## Соединение таблиц

```php
// SELECT * FROM test_products INNER JOIN test_groups ON test_products.gid = test_groups.id
$res = ManticoreDb::table('?products')->join('?groups', 'products.gid', 'groups.id')->get();

// то же самое через LEFT JOIN и с явно написанным оператором
$res = ManticoreDb::table('?products')->leftJoin('?groups', 'gid', 'id')->get();
$res = ManticoreDb::table('?products')->join('?groups', 'products.gid', '=', 'groups.id')->get();
```

Колонка, записанная без таблицы, принадлежит той таблице, рядом с которой стоит, поэтому
`join('?groups', 'gid', 'id')` означает то же, что и запись с обоими именами. У колонки с
таблицей имя можно писать плейсхолдером (`?groups.id`), реальным именем (`test_groups.id`) или
голым (`groups.id`) — все три сопоставляются с таблицами запроса.

Колонки присоединённой таблицы возвращаются **с префиксом её имени** — именно это разводит две
одноимённые колонки:

```php
$row = ManticoreDb::table('?products')->join('?groups', 'gid', 'id')->first();

$row['title'];                 // из products
$row['test_groups.title'];     // из groups
```

Как и в Laravel, собственное имя такой колонке даёт алиас:

```php
$row = ManticoreDb::table('?products')
    ->join('?groups', 'gid', 'id')
    ->select(['title', '?groups.title as group_title'])
    ->first();
// ['title' => 'laptop', 'group_title' => 'computers']
```

Значения присоединённой таблицы приводятся по её собственной схеме, поэтому MVA приходит
массивом целых, а не строкой, которую присылает сервер.

Чего Manticore не умеет — того не умеет и этот метод: алиасов таблиц, подзапросов
(`joinSub()`) и любого условия соединения, кроме равенства, — другой оператор бросает
`InvalidArgumentException`, а не улетает на сервер за синтаксической ошибкой.

## Условия по датам

Колонку типа timestamp можно фильтровать по календарю:

```php
// календарный день, записывается диапазоном таймстемпов
$res = ManticoreDb::table('?products')->whereDate('created_at', '2024-01-31')->get();
$res = ManticoreDb::table('?products')->whereDate('created_at', '>=', '2024-01-31')->get();

// год — тоже диапазон
$res = ManticoreDb::table('?products')->whereYear('created_at', 2024)->get();

// месяц или день любого года, а также время суток
$res = ManticoreDb::table('?products')->whereMonth('created_at', 11)->get();
$res = ManticoreDb::table('?products')->whereDay('created_at', 31)->get();
$res = ManticoreDb::table('?products')->whereTime('created_at', '>=', '14:30')->get();
```

Про эти методы стоит знать две вещи.

**Даты трактуются как UTC.** `YEAR()`, `MONTH()` и остальные функции дат в Manticore считают в
UTC и ничего не знают о часовом поясе PHP, поэтому и диапазоны строятся в UTC — иначе один и
тот же запрос означал бы разное в зависимости от того, каким из двух способов он собран.
Приложение в другом часовом поясе приводит даты само.

**`whereMonth()`, `whereDay()` и `whereTime()` выбирают скрытую колонку.** Manticore не
принимает вызов функции в `WHERE` — `WHERE MONTH(created_at) = 11` является синтаксической
ошибкой, — но принимает алиас вычисляемой колонки. Поэтому выражение выбирается под
собственным именем, условие пишется по этому имени, а колонка удаляется из строк перед их
выдачей. В ответе её не видно, она заметна только в `toSql()`.

`whereDate()` и `whereYear()` в этом не нуждаются: дата — это диапазон таймстемпов, а диапазон
является обычным условием.

## Агрегаты и одиночные значения

```php
$max = ManticoreDb::table('?products')->max('price');
$min = ManticoreDb::table('?products')->min('price');
$sum = ManticoreDb::table('?products')->sum('qty');
$avg = ManticoreDb::table('?products')->avg('price');
$num = ManticoreDb::table('?products')->count();

// любая другая агрегатная функция
$distinct = ManticoreDb::table('?products')->aggregate('COUNT', 'DISTINCT manufacturer');

// одна колонка первой подходящей строки
$name = ManticoreDb::table('?products')->where('id', 12)->value('title');

// есть ли хоть что-то
if (ManticoreDb::table('?products')->where('price', '>', 1000)->exists()) { /* ... */ }
if (ManticoreDb::table('?products')->where('price', '>', 1000)->doesntExist()) { /* ... */ }

// единственная подходящая строка; бросает исключение, если её нет или их больше одной
$row = ManticoreDb::table('?products')->where('sku', 'A-1')->sole();
```

Агрегат пустой выборки равен `null`, а не нулю.

## Обход большой выборки

```php
// постранично, через LIMIT/OFFSET
ManticoreDb::table('?products')->chunk(500, function (array $rows, int $page) {
    // верните false, чтобы прервать обход
});

// постранично по колонке id — глубокие страницы не замедляются, и max_matches обход не ограничивает
ManticoreDb::table('?products')->chunkById(500, function (array $rows) { /* ... */ });

// построчно
ManticoreDb::table('?products')->each(function (array $row) { /* ... */ });

// генератором, страница подгружается по мере необходимости
foreach (ManticoreDb::table('?products')->lazy(500) as $row) { /* ... */ }
foreach (ManticoreDb::table('?products')->cursor() as $row) { /* ... */ }
```

## Условное построение запроса

```php
$query = ManticoreDb::table('?products')
    ->when($request->get('brand'), function ($query, $brand) {
        $query->where('manufacturer', $brand);
    })
    ->unless($showAll, function ($query) {
        $query->where('on_sale', true);
    });

// передать запрос в колбэк и продолжить его собирать
$query->tap(function ($query) { /* ... */ });

// ответвиться от общей части — копия не делит условия с оригиналом
$cheap = $query->clone()->where('price', '<', 100);

// напечатать SQL, либо напечатать и остановиться
$query->dump();
$query->dd();
```

## Работа с JSON-атрибутами

Раздел основан на примерах из [Manticore Search Courses](https://play.manticoresearch.com/json/).
Возьмём простой документ с id, названием и атрибутом metadata, описывающим товар:
```json
{
  "locations": [
    {
      "name": "location1",
      "lat": 23.000000,
      "long": 46.500000,
      "stock": 30
    },
    {
      "name": "location2",
      "lat": 24.000000,
      "long": 47.500000,
      "stock": 20
    },
    {
      "name": "location3",
      "lat": 24.500000,
      "long": 47.500000,
      "stock": 10
    }
  ],
  "color": [
    "blue",
    "black",
    "yellow"
  ],
  "price": 210.00,
  "cpu": {
    "model": "Kyro 345",
    "cores": 8,
    "chipset": "snapdragon 845"
  },
  "memory": 128
}
```
Фильтрация по metadata:
```php
// SELECT * FROM t WHERE DOUBLE(metadata.price)>200;
$res = ManticoreDb::table('t')->where('DOUBLE(metadata.price)', '>', 250)->get();

// SELECT * FROM t WHERE metadata.cpu.model='Kyro 345';
$res = ManticoreDb::table('t')->where('metadata.cpu.model', 'Kyro 345')->get();

// SELECT id, ANY(x.stock > 0 AND GEODIST(23.0,46.5, DOUBLE(x.lat), DOUBLE(x.long), {out=mi}) < 10 FOR x IN metadata.locations) AS close_to_you FROM t ORDER BY close_to_you DESC;
$res = ManticoreDb::table('t')->select(['id', 'ANY(x.stock > 0 AND GEODIST(23.0,46.5, DOUBLE(x.lat), DOUBLE(x.long), {out=mi}) < 10 FOR x IN metadata.locations) AS close_to_you'])
    ->orderByDesc('close_to_you')->get();

// SELECT * FROM t ORDER BY INTEGER(metadata.video_rec[0]) DESC;
$res = ManticoreDb::table('t')->orderByDesc('INTEGER(metadata.video_rec[0])')->get();

// SELECT *, IN(metadata.color, 'black', 'white') AS color_filter WHERE color_filter=1;
// список колонок не экранируется, поэтому литералы пишутся как есть
$res = ManticoreDb::table($table)->select(['*', "IN(metadata.color, 'black', 'white') as color_filter"])
    ->where('color_filter=1')
    ->get();

// то же с именованными параметрами — используйте их для значений, приходящих извне:
// они передаются серверу как связанные параметры, а не вставляются в текст SQL
$res = ManticoreDb::table($table)->select(['*', 'IN(metadata.color, :black, :white) as color_filter'])
    ->where('color_filter=1')
    ->bind([':black' => 'black', ':white' => 'white'])
    ->get();
```

## Фасетный поиск

```php
$res = ManticoreDb::table('products')
    ->match('big')
    ->facet('country', function (Facet $facet) {
        $facet->limit(2);
    })
    ->facet('price', function ($facet) {
        $facet->alias('cost')->limit(3);
    })
    ->exec()
;

// результаты поиска
foreach ($res->result() as $id => $row) {
    // ...
}

// все фасеты
foreach ($res->facets() as $key => $facet) {
    // ...
}

// конкретный фасет №0
foreach ($res->facets(0) as $key => $facet) {
    foreach ($facet as $row) {
        $country = $row['country'];
        $count = $row['_count']; // добавляется автоматически
    }
}
// конкретный фасет №1
foreach ($res->facets(1) as $key => $facet) {
    foreach ($facet as $row) {
        $cost = $row['cost'];    // задано через alias()
        $count = $row['_count']; // добавляется автоматически
    }
}
```
Методы фасета, доступные внутри замыкания:
* alias(string $alias)
* byExpr(string $expr)
* distinct(string $column)
* orderBy(string|array $names, string $direction = 'asc')
* orderByDesc(string|array $names)
* limit(int $limit)
* limit(int $offset, int $limit)
* offset(int $offset)
