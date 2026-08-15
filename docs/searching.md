**English** | [Русский](ru/searching.md)

# Manticore Search Query Builder for PHP (unofficial PHP client)

Jump To:
* [Retrieving Rows From A Table](#retrieving-rows-from-a-table)
* [Retrieving a single row or column from a table](#retrieving-a-single-row-or-column-from-a-table)
* [Select statements](#select-statements)
* [The MATCH clauses](#the-match-clauses)
* [The WHERE Clause](#the-where-clause)
* [limit() and offset()](#limit---and-offset--)
* [orderBy() and orderByDesc()](#orderby---and-orderbydesc--)
* [groupBy() and having()](#groupby---and-having--)
* [maxMatches()](#maxmatches--)
* [Working with JSON attributes](#working-with-json-attributes)
* [Searching for what a user typed](#searching-for-what-a-user-typed)
* [Joining tables](#joining-tables)
* [Vector search (KNN)](#vector-search-knn)
* [Conditions on dates](#conditions-on-dates)
* [Aggregates and single values](#aggregates-and-single-values)
* [Walking over a large result](#walking-over-a-large-result)
* [Conditional building](#conditional-building)
* [Faceted search](#faceted-search)
* [The `CALL *` statements](#the-call--statements)

## Retrieving rows from a table

```php
use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;

// Returns object of ResultSet
$res = ManticoreDb::table('?products')->match('galaxy')->where('price', '>', 1100)->exec();
// $res->result() returns collection of rows
// If one of the columns contains ID then these values will be used as keys for result records
foreach($res->result() as $id => $row) {
    // $id - ID of found record
    // $row - array <field_name> => <field_value>
    // plus the generated "_score" field when the query has a match()
}

// weight() is 1 for every row of a query without a match(), so "_score" is only selected
// for a full-text one. Both ways round it can be asked for explicitly:
$res = ManticoreDb::table('?products')->where('price', '>', 1100)->withScore()->get();
$res = ManticoreDb::table('?products')->match('galaxy')->withoutScore()->get();

// Selects specified columns and returns object of ResultSet
$res = ManticoreDb::table('?products')->match('galaxy')->where('price', '>', 1100)->search(['name', 'price']);

// Returns arrays, equals of exec()->result();
$res = ManticoreDb::table('?products')->match('galaxy')->where('price', '>', 1100)->get();

// Returns arrays, equals of search('*')->result();
$res = ManticoreDb::table('?products')->match('galaxy')->where('price', '>', 1100)->get('*');

// ...or the other way
$query = ManticoreDb::table('?products')->match('galaxy');
$query->where('price', '>', 1100);
$res = $query->get('*');
```

## Retrieving a single row or column from a table

```php
// Returns the first row according to the given conditions
$record = ManticoreDb::table('?products')->match('galaxy')->where('price', '>', 500)->first();

// Returns a list of values of the column 'name'
$names = ManticoreDb::table('?products')->match('galaxy')->where('price', '>', 500)->pluck('name');
// ['Galaxy S10', 'Galaxy S20', ...]

// The second argument keys that list by another column
$names = ManticoreDb::table('?products')->match('galaxy')->pluck('name', 'id');
// [128 => 'Galaxy S10', 129 => 'Galaxy S20', ...]
```

Only the columns asked for are selected, so `pluck()` overrides an earlier `select()`.

## Looking at the SQL

```php
$query = ManticoreDb::table('?products')->where('country=:country')->bind([':country' => 'de']);

// The statement as it is built, with the named parameters of bind() left in place
$query->toSql();      // SELECT * FROM test_products WHERE (country=:country)

// The statement as it goes to the server, with the parameters put in
$query->toRawSql();   // SELECT * FROM test_products WHERE (country=de)
```

Values of the conditions are part of the statement either way — they are put in when it is built,
not bound — so the two differ only for a query that uses `bind()`.

## Select statements

```php
// Returns object of ResultSet
$res = ManticoreDb::table('?products')->match('galaxy')->where('price', '>', 1100)->select(['id', 'name', 'price'])->exec();
// The same result (shorter notation)
$res = ManticoreDb::table('?products')->match('galaxy')->where('price', '>', 1100)->search(['id', 'name', 'price']);

// Returns collection of rows
$res = ManticoreDb::table('?products')->match('galaxy')->where('price', '>', 1100)->select(['id', 'name', 'price'])->get();
// The same result (shorter notation)
$res = ManticoreDb::table('?products')->match('galaxy')->where('price', '>', 1100)->get(['id', 'name', 'price']);

```

The column list can be written in any of these forms, they all give the same result
(```search()``` and ```get()``` accept the same arguments):
```php
$query->select(['id', 'name', 'price']);
$query->select('id, name, price');
$query->select('id', 'name', 'price');
```
Commas inside brackets and quotes do not split the list, so an expression stays in one piece:
```php
// SELECT id, IN(color, 1, 2) as f FROM ?products
$query->select('id, IN(color, 1, 2) as f');
```
Note that ```select()``` replaces the previous column list rather than adding to it.

## Search conditions

### The MATCH clauses
The match() method allows to perform full-text searches in text fields
```php
$res = ManticoreDb::table('zoo')->match('cats|birds')->get();
$res = ManticoreDb::table('zoo')->match('looking for ( cat | dog | mouse )')->get();
$res = ManticoreDb::table('articles')->match('hello MAYBE world')->get();
$res = ManticoreDb::table('articles')
    ->match('"hello world" @title "example program"~5 @body python -(php|perl) @* code')
    ->get();
```

### The WHERE Clause

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
// a null value asks for IS NULL, in both the two- and the three-argument form
$res = ManticoreDb::table('?products')->where('info.color', null)->get();
$res = ManticoreDb::table('?products')->where('info.color', '=', null)->get();
// ...and "!=" / "<>" with null gives IS NOT NULL. Any other operator with null throws
// an InvalidArgumentException: "price > null" has no meaning to compile into SQL.

// SELECT * FROM ?products WHERE (color='red' AND price=10);
// an array is a set of conditions joined with AND
$res = ManticoreDb::table('?products')->where(['color' => 'red', 'price' => 10])->get();
// the same with explicit operators
$res = ManticoreDb::table('?products')->where([['price', '>', 10], ['color', 'red']])->get();
```
### Searching for what a user typed

The argument of `match()` is written in the query language of Manticore, not taken as plain
text: `|` is an alternative, `-` excludes a word, quotes make a phrase, `@` limits the search to
a field. Text coming from a search box has to be turned into a literal first, or it means
something else than what was typed — and an unpaired quote makes the server reject the query:

```php
$found = ManticoreDb::table('?products')->match(ManticoreDb::escapeMatch($request->get('q')))->get();
```

| Input | `match($input)` | `match(escapeMatch($input))` |
|---|---|---|
| `iPhone -Pro` | excludes "Pro" | searches for the words as they were typed |
| `iPhone\|Galaxy` | either word | both words |
| `iPhone " 15` | rejected by the server | searches for the words |

A search can be limited to certain full-text fields with the second argument, which is what the
`@(title,description)` operator of the language does:

```php
$res = ManticoreDb::table('?products')->match('galaxy', 'title')->get();
$res = ManticoreDb::table('?products')->match('galaxy', ['title', 'description'])->get();
```

There is no `whereFullText()` of the Laravel query builder: its `$value` is plain text, while
here the same string is an expression, and `MATCH` is one per query rather than a condition
among others. `whereMatch()` is the same method under the `where*()` naming.

### Pattern matching: whereRegex() and whereMatch()

There is no `whereLike()`, because Manticore takes no `LIKE` in `WHERE` at all. What it takes is
`REGEX()` over string attributes, and full-text search over text fields — two different
mechanisms, so the builder names them apart instead of hiding both behind a familiar name:

```php
// a string attribute, matched by a regular expression
$res = ManticoreDb::table('?products')->whereRegex('manufacturer', '^acme')->get();
$res = ManticoreDb::table('?products')->orWhereRegex('manufacturer', '^other')->get();
$res = ManticoreDb::table('?products')->whereNotRegex('manufacturer', '^acme')->get();

// a text field, searched through the full-text index - an alias of match()
$res = ManticoreDb::table('?products')->whereMatch('galaxy')->get();
```

Three things to keep in mind about `whereRegex()`:

* it works on **string attributes only**. A full-text field is not an attribute, and a `REGEX`
  over one is rejected by the server — the read then throws a `QueryErrorException` telling so;
* it is **case sensitive**, unlike the `LIKE` of MySQL. Prefix the pattern with the inline flag
  `(?i)` to match either case: `whereRegex('manufacturer', '(?i)^acme')`;
* it goes **through no index** — every value of the attribute is matched, which is a full scan.

The pattern is not anchored: it matches anywhere in the value unless `^` and `$` say otherwise.
It is escaped as a value, so a quote or a backslash in it is safe to pass through.

`whereMatch()` is `match()` under the naming of the `where*()` family. There is deliberately no
`orWhereMatch()` or `whereNotMatch()`: `MATCH` is a clause of its own rather than a condition of
`WHERE`, and Manticore takes one per query — alternatives and negation belong inside the
expression itself (`match('acme|corp')`, `match('acme -corp')`).

The helpers of the Laravel query builder are there as well:
```php
// SELECT * FROM ?products WHERE NOT((color='red'))
$res = ManticoreDb::table('?products')->whereNot('color', 'red')->get();
// a whole group can be negated the same way
$res = ManticoreDb::table('?products')->whereNot(function ($condition) {
    $condition->where('color', 'red')->orWhere('color', 'green');
})->get();

// at least one of the columns matches
$res = ManticoreDb::table('?products')->whereAny(['title', 'manufacturer'], 'acme')->get();
// every one of them
$res = ManticoreDb::table('?products')->whereAll(['price', 'qty'], '>', 10)->get();
// none of them
$res = ManticoreDb::table('?products')->whereNone(['price', 'qty'], '>', 10)->get();

// a condition as it is written
$res = ManticoreDb::table('?products')->whereRaw('price > 100 AND qty < 10')->get();
```

Sometimes you may need to group several "WHERE" clauses within parentheses in order to achieve your query's desired logical grouping.
To accomplish this, you may pass a closure to the ```where()``` method:
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
Inside the closure the same methods are available as on the builder itself, including
```whereIn()```, ```whereNull()``` and ```whereBetween()```:
```php
$res = ManticoreDb::table('products')
    ->where(function($condition) {
        $condition->whereIn('country', ['de', 'us']);
        $condition->orWhereNull('info.color');
    })
    ->get();
```

You can use methods:
* where(\<field>, \<condition>, \<value>)
* where(\<field>, \<value>) => where(\<field>, '=', \<value>)
* where(\<field>, null) => where(\<field>, 'IS NULL')
* where(\<array>) - a set of conditions joined with AND
* where(\<callback>)
* andWhere(\<field>, \<condition>, \<value>)
* andWhere(\<field>, \<value>) => andWhere(\<field>, '=', \<value>)
* andWhere(\<array>)
* andWhere(\<callback>)
* orWhere(\<field>, \<condition>, \<value>)
* orWhere(\<field>, \<value>) => where(\<field>, '=', \<value>)
* orWhere(\<array>)
* orWhere(\<callback>)
* whereNull(\<field>)
* andWhereNull(\<field>)
* orWhereNull(\<field>)
* whereNotNull(\<field>)
* andWhereNotNull(\<field>)
* orWhereNotNull(\<field>)

Ask for ```IS NULL``` / ```IS NOT NULL``` either by the dedicated methods or by a null value:
in the two-argument form ```where('updated_at', 'IS NULL')``` the second argument is a value,
not an operator, so that call compares the column with the string ```'IS NULL'```.
Note also that ```IS NULL``` applies to attributes (including JSON keys such as
```info.color```), not to full-text fields.
* whereIn(\<field>, \<array>)
* andWhereIn(\<field>, \<array>)
* orWhereIn(\<field>, \<array>)
* whereNotIn(\<field>, \<array>)
* andWhereNotIn(\<field>, \<array>)
* orWhereNotIn(\<field>, \<array>)
* whereBetween(\<field>, \<array>)
* andWhereBetween(\<field>, \<array>)
* orWhereBetween(\<field>, \<array>)
* whereNotBetween(\<field>, \<array>)
* andWhereNotBetween(\<field>, \<array>)
* orWhereNotBetween(\<field>, \<array>)


### limit() and offset()
```php
$res = ManticoreDb::table('?products')->match('phone')->limit(100)->get();
// the order of the two calls does not matter
$res = ManticoreDb::table('?products')->match('phone')->limit(100)->offset(500)->get();
$res = ManticoreDb::table('?products')->match('phone')->offset(500)->limit(100)->get();
// limit(<offset>, <limit>) sets both at once
$res = ManticoreDb::table('?products')->match('phone')->limit(500, 100)->get();
```
An ```offset()``` without a ```limit()``` throws a ```LogicException```: Manticore has no
```OFFSET``` of its own (```SELECT ... OFFSET 5``` is a syntax error), and the MySQL workaround
of a huge ```LIMIT``` makes the server ignore the offset - so the page asked for cannot be
returned, and saying so beats quietly answering with the first one.

```take()``` and ```skip()``` are the aliases of the two, and ```forPage()``` sets the whole
window at once:
```php
// LIMIT 30,15 - the third page, fifteen rows each
$res = ManticoreDb::table('?products')->forPage(3, 15)->get();
```

### orderBy() and orderByDesc()
```php
// ORDER BY price ASC
$query->orderBy('price')->get();
// ORDER BY created_at DESC - the two notations are equal
$query->orderByDesc('created_at')->get();
$query->orderBy('created_at', 'desc')->get();
```
Several columns can be passed at once, the direction then applies to all of them:
```php
// ORDER BY price DESC,id DESC
$query->orderBy('price, id', 'desc')->get();
$query->orderByDesc(['price', 'id'])->get();
```
A direction written into the expression itself is kept as is, so ```orderBy('price DESC')```
gives ```ORDER BY price DESC``` and not ```price DESC ASC```. Any direction other than
```asc``` / ```desc``` throws an ```InvalidArgumentException```.

```php
// ORDER BY created_at DESC / ASC
$query->latest()->get();
$query->oldest()->get();
// by another column
$query->latest('published_at')->get();

// ORDER BY RAND()
$query->inRandomOrder()->get();

// an expression as it is written
$query->orderByRaw('WEIGHT() DESC, price ASC')->get();

// drop the ordering set so far, optionally putting another one in its place
$query->reorder()->get();
$query->reorder('id', 'desc')->get();
```

### groupBy() and having()
```php
// SELECT country, count(*) as cnt FROM ?products GROUP BY country HAVING cnt > 2
$res = ManticoreDb::table('?products')
    ->select(['country', 'count(*) as cnt'])
    ->groupBy('country')
    ->having('cnt', '>', 2)
    ->get();
```
```groupBy()``` takes the same column list forms as ```select()```:
```php
$query->groupBy('country', 'brand');
$query->groupBy(['country', 'brand']);
$query->groupBy('country, brand');
```
```having()``` accepts these forms; the value of the three- and two-argument ones is quoted
and escaped, the one-argument one is passed through as a raw expression:
```php
$query->having('cnt', '>', 2);
$query->having('cnt', 2);                 // => having('cnt', '=', 2)
$query->having('cnt', 'IN', [2, 3]);      // HAVING cnt IN (2,3)
$query->having('cnt', 'BETWEEN', [2, 5]); // HAVING cnt BETWEEN 2 AND 5
$query->having('cnt > 2');                // raw
```
Only **one** expression can be set, because that is all the server takes: its syntax is
```[HAVING where_condition]``` with a single condition, and ```HAVING a AND b```,
```HAVING (a AND b)``` and ```HAVING a, b``` are all rejected as syntax errors. Calling
```having()``` twice therefore throws a ```LogicException``` instead of building a query
Manticore would refuse. Note also that the condition works on a grouping expression -
an aggregate alias, ```COUNT(*)``` or ```GROUPBY()``` - not on an arbitrary column.

### maxMatches()
Set max_matches for the search.
```php
$query->maxMatches(10000);
```

### explain()
Shows how the full-text expression of ```match()``` is transformed, without running the search.
Useful when a query does not match what you expect it to.
```php
$res = ManticoreDb::table('?products')->match('brown fox')->explain();

$res->variable('transformed_tree');
// AND(
//   AND(KEYWORD(brown, querypos=1)),
//   AND(KEYWORD(fox, querypos=2)))
```
Pass a format to render the tree differently, e.g. ```dot``` for graphviz:
```php
$res = ManticoreDb::table('?products')->match('brown | fox')->explain('dot');
// digraph "transformed_tree" { 0 [shape=record,style=filled label="OR"] ... }
```
The rows of ```$res->result()``` carry the tree under the ```Variable_name``` / ```Value```
keys, the same way ```tableStatus()``` and ```tableSettings()``` report their values.

## Joining tables

```php
// SELECT * FROM test_products INNER JOIN test_groups ON test_products.gid = test_groups.id
$res = ManticoreDb::table('?products')->join('?groups', 'products.gid', 'groups.id')->get();

// the same with LEFT JOIN, and with the operator written out
$res = ManticoreDb::table('?products')->leftJoin('?groups', 'gid', 'id')->get();
$res = ManticoreDb::table('?products')->join('?groups', 'products.gid', '=', 'groups.id')->get();
```

A column written on its own belongs to the table it stands next to, so `join('?groups', 'gid', 'id')`
means the same as spelling both tables out. A qualified column may name its table with the
placeholder (`?groups.id`), with the real name (`test_groups.id`) or with the bare one
(`groups.id`) — all three are matched against the tables of the query.

The columns of the joined table come back **prefixed with its name**, which is what keeps two
columns of the same name apart:

```php
$row = ManticoreDb::table('?products')->join('?groups', 'gid', 'id')->first();

$row['title'];                 // of the products
$row['test_groups.title'];     // of the groups
```

As in the Laravel query builder, an alias is how such a column gets a name of its own:

```php
$row = ManticoreDb::table('?products')
    ->join('?groups', 'gid', 'id')
    ->select(['title', '?groups.title as group_title'])
    ->first();
// ['title' => 'laptop', 'group_title' => 'computers']
```

Values of the joined table are cast by its own schema, so an MVA of it is an array of integers
rather than the string the server sends.

What Manticore does not do, and neither does this method: table aliases, subqueries
(`joinSub()`), and any join condition other than equality — a different operator throws an
`InvalidArgumentException` instead of reaching the server as a syntax error.

## Vector search (KNN)

KNN stands for *k-nearest neighbours*: instead of asking for the rows where a column equals
something, the query asks for the `k` rows whose vector is closest to a given one.

A vector here is a list of numbers describing an object - usually the output of an embedding
model, which turns a text or an image into, say, 384 numbers. Objects close in meaning get
close numbers, which is what makes this a semantic search: `match('apple')` finds the documents
containing the word, while a KNN query over embeddings also finds the ones about apples that
never use it.

A `float_vector` column holds such a vector, of a number of dimensions fixed by the schema:

```php
use avadim\Manticore\QueryBuilder\Schema\SchemaTable;

ManticoreDb::create('?products', function (SchemaTable $table) {
    $table->text('title');
    $table->floatVector('embedding', 384);                       // L2 by default
    // $table->floatVector('embedding', 384, 'cosine', ['hnsw_m' => 16]);
});

// vectors are written and read back as arrays of floats
ManticoreDb::table('?products')->insert(['title' => 'red apple', 'embedding' => [0.12, 0.4, ...]]);

// the five nearest neighbours of the given vector
$rows = ManticoreDb::table('?products')->whereKnn('embedding', 5, $vector)->get();
```

The metric of closeness is chosen when the column is declared:

| Similarity | What it measures | Usually for |
|---|---|---|
| `l2` (default) | euclidean distance | vectors of comparable length |
| `cosine` | the angle between vectors, ignoring their length | text embeddings |
| `ip` | inner product | models trained for it |

The distance comes back in the row as `_knn_dist`, the way the weight of a full-text query comes
back as `_score` - the server adds it to a `SELECT *` itself. With `l2` a smaller distance means
a closer match: an exact hit gives nearly zero.

`whereKnn()` combines with the rest of the query, and the builder writes the parts in the order
the server takes them - `knn()` first, then `MATCH()`, then everything else:

```php
$rows = ManticoreDb::table('?products')
    ->whereKnn('embedding', 10, $vector)
    ->match('apple')
    ->where('in_stock', true)
    ->limit(5)
    ->get();
// SELECT * FROM ... WHERE knn(embedding, 10, (…)) AND MATCH('apple') AND (in_stock=1) LIMIT 5
```

Manticore takes one KNN condition per query, so a second `whereKnn()` replaces the first. The
feature needs the KNN library loaded by the server - without it a table with a `float_vector`
column cannot even be created; see [what needs more than the bare server](../README.md#what-needs-more-than-the-bare-server).

## Conditions on dates

A timestamp column can be filtered by the calendar:

```php
// a calendar day, written as a range of timestamps
$res = ManticoreDb::table('?products')->whereDate('created_at', '2024-01-31')->get();
$res = ManticoreDb::table('?products')->whereDate('created_at', '>=', '2024-01-31')->get();

// a year, likewise a range
$res = ManticoreDb::table('?products')->whereYear('created_at', 2024)->get();

// a month or a day of any year, and a time of day
$res = ManticoreDb::table('?products')->whereMonth('created_at', 11)->get();
$res = ManticoreDb::table('?products')->whereDay('created_at', 31)->get();
$res = ManticoreDb::table('?products')->whereTime('created_at', '>=', '14:30')->get();
```

Two things are worth knowing about these.

**They are read as UTC.** `YEAR()`, `MONTH()` and the rest of the date functions of Manticore
count in UTC and know nothing of the timezone of PHP, so the ranges are built in UTC as well —
otherwise the same query would mean two different things depending on which of the two methods
answered it. An application in another timezone shifts its dates before passing them in.

**`whereMonth()`, `whereDay()` and `whereTime()` select a hidden column.** Manticore takes no
function call in `WHERE` — `WHERE MONTH(created_at) = 11` is a syntax error — but it does take
the alias of a computed column. So the expression is selected under a name of its own, the
condition is written against that name, and the column is dropped from the rows before they are
handed over. Nothing of it shows up in the answer; it is only visible in `toSql()`.

`whereDate()` and `whereYear()` need none of that: a date is a range of timestamps, and a range
is a plain condition.

## Aggregates and single values

```php
$max = ManticoreDb::table('?products')->max('price');
$min = ManticoreDb::table('?products')->min('price');
$sum = ManticoreDb::table('?products')->sum('qty');
$avg = ManticoreDb::table('?products')->avg('price');
$num = ManticoreDb::table('?products')->count();

// any other aggregate function
$distinct = ManticoreDb::table('?products')->aggregate('COUNT', 'DISTINCT manufacturer');

// one column of the first matching row
$name = ManticoreDb::table('?products')->where('id', 12)->value('title');

// whether anything matches
if (ManticoreDb::table('?products')->where('price', '>', 1000)->exists()) { /* ... */ }
if (ManticoreDb::table('?products')->where('price', '>', 1000)->doesntExist()) { /* ... */ }

// the only matching row, throws when there is none or more than one
$row = ManticoreDb::table('?products')->where('sku', 'A-1')->sole();
```

An aggregate of a result without rows is `null`, not zero.

## Walking over a large result

```php
// page by page, with LIMIT/OFFSET
ManticoreDb::table('?products')->chunk(500, function (array $rows, int $page) {
    // return false to stop the walk
});

// page by page, by the id column - the deeper pages do not get slower, and max_matches
// does not bound the walk
ManticoreDb::table('?products')->chunkById(500, function (array $rows) { /* ... */ });

// row by row
ManticoreDb::table('?products')->each(function (array $row) { /* ... */ });

// as a generator, fetching a page at a time
foreach (ManticoreDb::table('?products')->lazy(500) as $row) { /* ... */ }
foreach (ManticoreDb::table('?products')->cursor() as $row) { /* ... */ }
```

## Conditional building

```php
$query = ManticoreDb::table('?products')
    ->when($request->get('brand'), function ($query, $brand) {
        $query->where('manufacturer', $brand);
    })
    ->unless($showAll, function ($query) {
        $query->where('on_sale', true);
    });

// hand the query over and go on building it
$query->tap(function ($query) { /* ... */ });

// branch off a common part - the copy does not share the conditions
$cheap = $query->clone()->where('price', '<', 100);

// print the SQL, or print it and stop
$query->dump();
$query->dd();
```

## Working with JSON attributes

This section based on examples from [Manticore Search Courses](https://play.manticoresearch.com/json/).
We are going to use a simple document with id, name and a metadata attribute representing a product like this:
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
Let's perform a filtering by metadata
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
// The column list is not escaped, so literals can be written as they are
$res = ManticoreDb::table($table)->select(['*', "IN(metadata.color, 'black', 'white') as color_filter"])
    ->where('color_filter=1')
    ->get();

// The same with named parameters - use them for values that come from the outside,
// they are passed to the server as bound parameters instead of being pasted into the SQL
$res = ManticoreDb::table($table)->select(['*', 'IN(metadata.color, :black, :white) as color_filter'])
    ->where('color_filter=1')
    ->bind([':black' => 'black', ':white' => 'white'])
    ->get();
```


## Faceted search

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

// get results of search
foreach ($res->result() as $id => $row) {
    // do something
}

// get all facets
foreach ($res->facets() as $key => $facet) {
    // do something
}

// get specified facet #0
foreach ($res->facets(0) as $key => $facet) {
    foreach ($facet as $row) {
        $country = $row['country'];
        $count = $row['_count']; // auto defined field 
    }
}
// get specified facet #1
foreach ($res->facets(0) as $key => $facet) {
    foreach ($facet as $row) {
        $country = $row['cost']; // defined in alias()
        $count = $row['_count']; // auto defined field 
    }
}

```
Facet methods you can use in a closure:
* alias(string $alias)
* byExpr(string $expr)
* distinct(string $column)
* orderBy(string|array $names, string $direction = 'asc')
* orderByDesc(string|array $names)
* limit(int $limit)
* limit(int $offset, int $limit)
* offset(int $offset)

## The `CALL *` statements

Five statements of Manticore work through the table without being a search of it: they are
written as `CALL` and the builder wraps them as methods of the same name. All five answer with an
array of rows and throw `QueryErrorException` when the server rejects the statement, the same way
a read does.

### callSuggest()

`CALL SUGGEST` — the words of the table closest to the given one, i.e. "did you mean":

```php
ManticoreDb::table('?products')->create([
    'title' => 'text',
], ['min_infix_len' => 2]);

$rows = ManticoreDb::table('?products')->callSuggest('mantikore');
// [['suggest' => 'manticore', 'distance' => 1, 'docs' => 3]]

$rows = ManticoreDb::table('?products')->callSuggest('mantikore', ['limit' => 5, 'max_edits' => 2]);
```

`distance` is how many edits away the word is, `docs` is the number of documents it appears in.
A word that is spelled right comes back as itself with a distance of 0, and a word with nothing
close to it gives an empty set — so "is this a typo" is a question of the distance, not of
whether there is an answer at all. The conditions of the query are ignored: the statement works
on the dictionary of the whole table and takes no filters.

**The table has to be built with `min_infix_len`** — and with that one, not `min_prefix_len`.
Without it the server rejects the statement rather than answering with nothing:

```
QueryErrorException: … 1064 suggests work only for keywords dictionary with infix enabled
```

The setting belongs to `CREATE TABLE`, so an existing table cannot be given infixes without
being rebuilt.

### callQsuggest()

`CALL QSUGGEST` — the same, for a phrase rather than a single word. Only its last word is
corrected, which is what a search box needs while someone is still typing:

```php
$rows = ManticoreDb::table('?products')->callQsuggest('manticore serch');
// [['suggest' => 'search', 'distance' => 1, 'docs' => 2]]
```

Neither of the two corrects a whole phrase: `SUGGEST` answers for its first word, `QSUGGEST` for
its last. A phrase with a typo in more than one word takes a call per word, and `callKeywords()`
splits it the way the table itself would:

```php
$words = array_column(ManticoreDb::table('?products')->callKeywords($phrase), 'tokenized');

$fixed = [];
foreach ($words as $word) {
    $rows = ManticoreDb::table('?products')->callSuggest($word, ['limit' => 1, 'max_edits' => 2]);
    $fixed[] = ($rows && $rows[0]['distance'] > 0) ? $rows[0]['suggest'] : $word;
}

$corrected = implode(' ', $fixed);
```

`max_edits` is what keeps a word the table has never seen from being "corrected" into a distant
one, and `tokenized` is the token as it was written — `normalized` would be its lemma where the
table has morphology, i.e. a word the user did not type.

### callKeywords()

`CALL KEYWORDS` — what the tokenizer of the table makes of the text, which is the answer to "why
does this query match that row":

```php
$rows = ManticoreDb::table('?products')->callKeywords('Running Shoes');
// [
//   ['qpos' => 1, 'tokenized' => 'running', 'normalized' => 'running'],
//   ['qpos' => 2, 'tokenized' => 'shoes',   'normalized' => 'shoes'],
// ]

$rows = ManticoreDb::table('?products')->callKeywords('running', ['stats' => true]);
// each row also carries "docs" and "hits"
```

### callSnippets()

`CALL SNIPPETS` — the documents given to it with the matching parts marked up. The documents are
passed in rather than read from the table: the table only lends its tokenizer and its stopwords,
so the highlighting follows the same rules as the search.

```php
$rows = ManticoreDb::table('?products')->callSnippets('the quick brown fox', 'fox');
// [['snippet' => 'the quick brown <b>fox</b>']]

$rows = ManticoreDb::table('?products')->callSnippets(
    ['the quick brown fox', 'a lazy dog'],
    'fox|dog',
    ['before_match' => '<em>', 'after_match' => '</em>']
);
// a row per document
```

### callPq()

`CALL PQ` — the search the other way round. A percolate table (`type='pq'`) holds queries rather
than documents, and this asks which of them a document would have been an answer to — the way a
saved search or an alert works.

```php
ManticoreDb::table('?subscriptions')->create([
    'title' => 'text',
    'gid'   => 'int',
], ['type' => 'pq']);

ManticoreDb::connection()->statement("INSERT INTO subscriptions (query, tags) VALUES ('fox', 'animals')");

$rows = ManticoreDb::table('?subscriptions')->callPq(['title' => 'the quick brown fox']);
// [['id' => 6416985563647181697]]

// a set of documents, and the stored query itself in the answer
$rows = ManticoreDb::table('?subscriptions')->callPq(
    [['title' => 'the quick brown fox'], ['title' => 'a lazy dog']],
    ['docs' => true, 'query' => true]
);
// [['id' => …, 'documents' => '1', 'query' => 'fox', 'tags' => 'animals', 'filters' => ''], …]
```

An array is a document of its own and goes to the server as JSON. A string is taken as the text
of a document, and `0 AS docs_json` — which the server needs to read it that way — is added along
the way; pass `['docs_json' => true]` yourself to send a string that already holds JSON.

`documents` of the answer is the list of positions of the documents that matched, `'1'` or
`'1,2'`, so it stays a string even when it holds a single number.

### Options and types of the answer

Everything after the arguments is an option of the statement, rendered as `<value> AS <name>`:
`['limit' => 5]` becomes `5 AS limit`, `['stats' => true]` becomes `1 AS stats`. Values are
escaped, so what a user typed can be passed in as it is; the names are not, and a name that is
not a plain word is refused with `InvalidArgumentException`.

Numbers of the answer are read as numbers — `distance`, `docs`, `hits` and `qpos` come back as
`int`, while `suggest`, `snippet`, `tokenized`, `normalized`, `query`, `tags`, `filters` and
`documents` stay strings.
