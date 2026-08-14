**English** | [Русский](ru/result_set.md)

# Manticore Search Query Builder for PHP (unofficial PHP client)

## ResultSet

A read-only model of the answer to a single query. ```search()```, ```create()```,
```drop()``` and the rest of the schema commands return it directly.

### Getting a ResultSet out of a write

```insert()```, ```update()```, ```delete()``` and ```replace()``` answer the way the Laravel
query builder does - with a flag and a number - so their ResultSet has to be asked for
separately. There are two ways to do it, and they run exactly the same statement:

```php
// 1. the *ResultSet() twin of the method
$result = ManticoreDb::table('?products')->insertResultSet(['title' => 'Laptop']);
$result->success();     // did it work
$result->result();      // the id of the inserted row
$result->error();       // and why not, if it did not

// 2. or the last ResultSet of the connection, after the scalar call
if (!ManticoreDb::table('?products')->insert(['title' => 'Laptop'])) {
    $error = ManticoreDb::lastResultSet()->error();
}
```

This matters more here than it would in Laravel: a failed **write** is not thrown as an
exception, it is reported through ```success()``` and ```error()```. The bare ```false``` of
```insert()```/```replace()``` and the bare ```0``` of ```update()```/```delete()``` carry no
reason with them, and ```0``` does not even tell "nothing matched the condition" from "the
statement failed". Reads are the other way round - see [Errors](#errors) below.

The full set of write methods, three per statement where an id makes sense:

| statement | flag or number | id | ResultSet |
|---|---|---|---|
| INSERT | ```insert(): bool``` | ```insertGetId(): ?int``` | ```insertResultSet()``` |
| REPLACE | ```replace(): bool``` | ```replaceGetId(): ?int``` | ```replaceResultSet()``` |
| UPDATE | ```update(): int``` | - | ```updateResultSet()``` |
| DELETE | ```delete(): int``` | - | ```deleteResultSet()``` |

```lastResultSet()``` belongs to a connection and holds the last statement that went through it,
service ones included - the DESCRIBE that a write asks for before building its SQL lands there
too, though always before the write itself, so a write is never masked by it.

### result(): mixed
Depends on last query:
* Collection of rows for SELECT query
* Boolean for CREATE, DROP, TRUNCATE, OPTIMIZE and ALTER queries
* Bigint for INSERT and REPLACE (a list of them when a set of rows was written)
* Number of affected rows for UPDATE and DELETE
* Array for others   

### command(): ?string
Returns a command ('SELECT', 'INSERT', 'SHOW TABLES', etc)

### sqlQuery(): ?string
Returns SQL query

### execTime(): ?float
Returns execution time

### error(): ?string
Returns text of error or warning of last query

### success(): bool
Result without errors and warnings

### status(): ?string
The last result of query

## Specific methods for SELECT

### result(): array
Collection of rows

### columns(): array
Returns array of columns names

### count(): int
Returns count of result rows

### total(): int
Returns total number of rows that match the condition in table

### first(): array
Returns the first row of rows set

### meta(): array
Returns the meta data received after SQL request

### facets(): array
Returns facets

## Specific method for SHOW VARIABLES

### variable($name): mixed
Returns value of variable

## Errors

A statement asked for its **rows** throws when the server rejects it: `get()`, `first()`,
`find()`, `value()`, `pluck()`, `sole()`, `count()`, the aggregates and the walks all raise
`avadim\Manticore\QueryBuilder\QueryErrorException`, which carries the message of the server
and the statement itself through `sql()`. Answering with `null` or `0` would be
indistinguishable from an empty table.

```php
try {
    $rows = ManticoreDb::table('?products')->whereRegex('title', 'galaxy')->get();
}
catch (\avadim\Manticore\QueryBuilder\QueryErrorException $e) {
    $e->getMessage();   // what the server said
    $e->sql();          // the statement it rejected
}
```

`exec()` and `search()` keep answering with the `ResultSet` instead - the error is read out of
it rather than caught:

```php
$result = ManticoreDb::table('?products')->whereRegex('title', 'galaxy')->exec();
if (!$result->success()) {
    $error = $result->error();
}
```

Writes follow the same split: `insert()`, `update()`, `delete()` and `replace()` answer with a
scalar, and the whole answer of the last statement is available through `lastResultSet()`.
