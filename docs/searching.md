# Manticore Search Query Builder for PHP (unofficial PHP client)

Jump To:
* [Retrieving Rows From A Table](#retrieving-rows-from-a-table)
* [Retrieving a single row or column from a table](#retrieving-a-single-row-or-column-from-a-table)
* [Select statements](#select-statements)
* [The MATCH clauses](#the-match-clauses)
* [The WHERE Clause](#the-where-clause)
* [limit() and offset()](#limit---and-offset--)
* [maxMatches()](#maxmatches--)
* [Working with JSON attributes](#working-with-json-attributes)
* [Faceted search](#faceted-search)

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
    // also each row has two additional fields: _id and _score 
}

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

// Returns array of values from field 'name'
$record = ManticoreDb::table('?products')->match('galaxy')->where('price', '>', 500)->pluck('name');
```

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
$res = ManticoreDb::table('?products')->match('phone')->limit(100)->offset(500)->get();
```

### orderBy() and orderByDesc()
```php
$query->orderBy('price')->get();
$query->orderByDesc('created_at')->get();
```

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
// Here we have to use a bind() because the select() escapes the quotes
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
* orderBy(string $names)
* orderByDesc(string $names)
* limit(int $limit)
* limit(int $offset, int $limit)
* offset(int $offset)