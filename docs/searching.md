# Manticore Search Query Builder for PHP (unofficial PHP client)

## Searching

```php
// Returns object of ResultSet
$res = ManticoreDb::table('?products')->match('galaxy')->where('price', '>', 1100)->exec();
// $res->result() returns collection of rows, key of each row is ID of record in table
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

### The WHERE clause

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
You can use methods:
* where(\<field>, \<condition>, \<value>)
* where(\<field>, \<value>) => where(\<field>, '=', \<value>)
* where(\<callback>)
* andWhere(\<field>, \<condition>, \<value>)
* andWhere(\<field>, \<value>) => andWhere(\<field>, '=', \<value>)
* andWhere(\<callback>)
* orWhere(\<field>, \<condition>, \<value>)
* orWhere(\<field>, \<value>) => where(\<field>, '=', \<value>)
* orWhere(\<callback>)
* whereNull(\<field>)
* andWhereNull(\<field>)
* orWhereNull(\<field>)
* whereNotNull(\<field>)
* andWhereNotNull(\<field>)
* orWhereNotNull(\<field>)
* whereIn(\<field>, \<array>)
* andWhereIn(\<field>, \<array>)
* orWhereIn(\<field>, \<array>)
* whereNotIn(\<field>, \<array>)
* andWhereNotIn(\<field>, \<array>)
* orWhereNotIn(\<field>, \<array>)
* whereBetween(\<field>, \<array>)
* orWhereBetween(\<field>, \<array>)
* whereNotBetween(\<field>, \<array>)
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

### Faceted search

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
