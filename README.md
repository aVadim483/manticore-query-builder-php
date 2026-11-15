**English** | [Русский](README.ru.md)

[![GitHub Release](https://img.shields.io/github/v/release/aVadim483/manticore-query-builder-php)](https://packagist.org/packages/avadim/manticore-query-builder-php)
[![Packagist Downloads](https://img.shields.io/packagist/dt/avadim/manticore-query-builder-php?color=%23aa00aa)](https://packagist.org/packages/avadim/manticore-query-builder-php)
[![GitHub License](https://img.shields.io/github/license/aVadim483/manticore-query-builder-php)](https://packagist.org/packages/avadim/manticore-query-builder-php)
[![PHP](https://img.shields.io/badge/php-7.4%20%7C%20%3E%3D8.1-005fc7)](https://packagist.org/packages/avadim/manticore-query-builder-php)

# Manticore Search Query Builder for PHP (unofficial PHP client)

A standalone PHP query builder for Manticore Search with a fluent API inspired by Laravel.

Features
* Named Manticore connections over the SQL/MySQL protocol via PDO
* Placeholders as prefix in table names
* Named parameters in expressions
* Fluent query construction
* Multiple INSERT and REPLACE
* Support MATCH() and multi-level WHERE for SELECT
* Support faceted search, JOIN of two tables and KNN vector search
* MVA ANY/ALL filters and geographic radius searches, distance columns and sorting
* Column types are applied in both directions: PHP values on write, PHP types on read
* Selected helpers inspired by Laravel's query builder, implemented for Manticore:
  aggregates, chunked iteration, conditional query building, date filters and upserts
* CALL helpers: `callAutocomplete()`, `callSuggest()`, `callQsuggest()`,
  `callKeywords()`, `callSnippets()` and `callPq()` (percolate queries)
* Row-returning read helpers throw on execution errors; ordinary writes return a status
  or count, with execution details available through `lastResultSet()`
* PSR-3 logging of queries and EXPLAIN of full-text expressions
* Rows without metadata via `getRows()`, an immutable `Meta` object,
  and optional lazy retrieval of inserted IDs

Manticore Search server documentation: https://manual.manticoresearch.com/

## 2.7: batch import and hybrid search

Available since [2.7.0](CHANGELOG.md). This release adds [bounded iterable INSERT/REPLACE](docs/writing.md#streaming-batch-insertreplace),
[JSON filters and composed full-text conditions](docs/json-fulltext.md), and
[extended numeric KNN and hybrid search](docs/knn-hybrid.md).

The snippet below assumes `products` has a numeric `stock` attribute, a JSON
`info.tags` array and a KNN-indexed `embedding`. Supply a numeric `$vector` with
the declared dimensions; the runnable example below includes its own schema and data.

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
$globalTotal = $result->totalFound(); // null: only the candidate window is known
```

The application supplies the numeric vector; fusion runs in PHP. New APIs retain
PHP 7.4, existing method signatures and default read/write behavior. The new KNN
path requires the server KNN library; no Laravel or model provider is required.

Run the [complete import and search example](examples/README.md) to create a test
table, load a generator in batches, apply JSON/full-text filters, fuse text and
vector candidates, export JSON and page the result window.

## Related packages

This builder works without a framework. The related Laravel integrations below are
maintained separately; their 4.x releases use a native Laravel driver.

* [`avadim/manticore-query-builder-laravel`](https://github.com/aVadim483/manticore-query-builder-laravel) —
  version 4.x is a native SQL database driver for Laravel, including
  `DB::connection('manticore')`, schema operations and query builders. It has its own
  implementation and does not depend on this standalone package. Earlier wrapper
  releases used this builder.
* [`avadim/manticore-laravel-scout`](https://github.com/aVadim483/manticore-laravel-scout) —
  the ManticoreSearch driver for [Laravel Scout](https://laravel.com/docs/scout): full-text search
  of Eloquent models as `Post::search('manticore')->get()`. Its 4.x line uses the native
  Laravel driver above.

## Requirements

* PHP 7.4 or PHP 8.1 and above (PHP 8.0 is excluded by the Composer constraint)
* PHP extensions: JSON, mbstring, PDO and the PDO MySQL driver (`pdo_mysql`)
* Manticore Search reachable over its SQL interface (port 9306 by default).
  The HTTP/JSON API is not used.

### What needs more than the bare server

Most of the builder talks to the server and nothing else. A few methods depend on a library
loaded by the server, or on Manticore Buddy running next to it:

| Method | Needs | Without it |
|---|---|---|
| everything else | the server alone | — |
| `floatVector()`, `whereKnn()`, `knn()`, `hybridSearch()` and its shortcuts | KNN library | vector table creation or search fails |
| `columnar()`, `columnEngine('columnar')`, `engine => 'columnar'` | Columnar library | the table cannot be created: *columnar library not loaded* |
| `rename()` | Manticore Buddy | the server answers with a syntax error |
| `callAutocomplete()` | Manticore Buddy and table infixes | the server rejects the call |

The KNN and columnar libraries both come from the [Manticore Columnar Library](https://github.com/manticoresoftware/columnar);
the secondary-index library ships with them and speeds filtering up without changing anything
in the API.

The libraries have to match the daemon: it checks their ABI version on load and refuses one
that is too new, saying so only when started with `--console`. Which ones did load is written
in the version string:

```sql
SHOW STATUS LIKE 'version';
-- 28.6.6 … (columnar 13.8.3 …) (secondary 13.8.3 …) (knn 13.8.3 …)
```

A library that is missing from that line is not loaded, whatever the files on disk say.

## Installation

```bash
composer require avadim/manticore-query-builder-php
```

## Quick start guide

```php
use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use avadim\Manticore\QueryBuilder\Schema\SchemaTable;

// Define config
$config = [
    'defaultConnection' => 'default',
    'connections' => [
        // Named default connection; these explicit values are used as configured
        'default' => [
            'host' => 'localhost',
            'port' => 9306,
            'username' => null,
            'password' => null,
            'timeout' => 5,
            'prefix' => 'test_', // prefix that will replace the placeholder "?<table_name>"
            'force_prefix' => false,
        ],
    ],
];

// Init query builder
ManticoreDb::init($config);

// Create table
ManticoreDb::create('?products', function (SchemaTable $table) {
    $table->timestamp('created_at');
    $table->string('manufacturer');
    $table->text('title');
    $table->json('info');
    $table->float('price');
    $table->multi('categories');
    $table->bool('on_sale');
});

// Insert single row
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
// insert() returns true/false, insertGetId() - the <id> of the new record

// Insert multiple rows
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
// $res->result() => array of <id> of new records

// Update and delete answer with the number of affected rows
$updated = ManticoreDb::table('?products')->where('price', '<', 100)->update(['on_sale' => true]);
$deleted = ManticoreDb::table('?products')->where('price', '<', 1)->delete();

// On an insert execution failure, insert() returns false; update()/delete() return 0.
// Inspect the last ResultSet for the execution error; invalid arguments can still throw.
if (!ManticoreDb::table('?products')->insert($singleRow)) {
    $error = ManticoreDb::lastResultSet()->error();
}

// get() throws on a read execution error instead of returning an apparent empty result
try {
    $rows = ManticoreDb::table('?products')->where('nosuchcolumn', 1)->get();
}
catch (\avadim\Manticore\QueryBuilder\QueryErrorException $e) {
    $error = $e->getMessage();
}

// Search: get() returns rows keyed by document id, with values cast back to PHP types
// ('info' as an array, 'categories' as int[], 'on_sale' as bool)
$rows = ManticoreDb::table('?products')->match('galaxy')->where('price', '>', 1100)->get();
```

## 2.6: rows, metadata and insert IDs

Available since [2.6.0](CHANGELOG.md). Existing method signatures and default execution
behavior are preserved.

```php
$query = ManticoreDb::table('?products')->limit(20);
$rows = $query->getRows();       // no SHOW META
$returned = count($rows);       // returned rows, not total matches
$meta = $query->getMeta();      // optional explicit SHOW META, before another search
$total = $meta->totalFound();   // also available as $meta['total_found']

$result = $query->search();     // rows and metadata together
$array = $result->meta();       // existing array API
$object = $result->metaObject(); // object over loaded metadata, no SQL
```

`Meta` is read-only. Its accessors return `null` for absent values; `count($meta)` counts
metadata fields. Enable `lazy_insert_ids` in the connection configuration to defer ID
retrieval for boolean `insert()`/`replace()` until `lastResultSet()->result()`. Read pending
IDs before any subsequent command on that connection. The setting defaults to false;
`*GetId()` and `*ResultSet()` still retrieve IDs immediately.

See [searching](docs/searching.md#rows-without-metadata), [ResultSet](docs/result_set.md)
and [writing documents](docs/writing.md) for the complete contracts and examples.

## Relationship to Laravel Query Builder

Several method names and chaining patterns are familiar from Laravel's query builder.
This package implements a subset independently over Manticore SQL and has its own
result types and execution contracts. It works without Laravel or `illuminate/*`.

* `get()` returns PHP arrays; `search()` / `exec()` return `ResultSet`, including execution
  errors. Methods asking directly for rows, such as `get()` and `first()`, throw on errors.
* `whereFullText()` / `orWhereFullText()` (since 2.7) compose field-scoped text conditions
  and escape text by default. `match()` / `whereMatch()` accept a complete native MATCH
  expression; pass literal user input through `escapeMatch()` when using that API.
* `whereRegex()` filters string attributes. `whereLike()`, `whereColumn()` and a query-level
  `distinct()` are not provided. `Facet::distinct()` is a separate facet option.
* `chunk()` and `lazy()` read pages; `cursor()` is an alias of `lazy()`, not a single-SELECT
  stream. OFFSET-based walks are subject to the query's `max_matches` setting.
* `upsert()` reads each input row's match before INSERT/REPLACE. The whole operation is
  not automatically atomic, and `uniqueBy` does not create a database uniqueness constraint.
* Date helpers interpret calendar dates in UTC. `when()` / `unless()` / `tap()` return a
  query builder; their callback contracts are described in the searching guide.
* `join()` / `leftJoin()` build equality joins without table aliases or `joinSub()`.
  Dedicated UNION, subquery and row-locking methods are absent. Manticore supports limited
  [sub-selects](https://manual.manticoresearch.com/Searching/Sub-selects); an absent builder
  method does not establish a server limitation.

See [searching](docs/searching.md), [writing](docs/writing.md),
[results and errors](docs/result_set.md) and [transactions](docs/configuration.md)
for the supported arguments and behavior.

## Documentation

More detailed documentation is available in the [/docs](/docs/README.md) folder:
[configuration](/docs/configuration.md),
[searching](/docs/searching.md),
[inserting and replacing documents](/docs/writing.md),
[tables](/docs/tables.md),
[result set](/docs/result_set.md),
[testing and Buddy](/docs/testing.md),
[logging](/docs/logging.md).

Документация на русском языке: [README.ru.md](README.ru.md) и папка [/docs/ru](/docs/ru).

## Tests

```bash
vendor/bin/phpunit --testsuite unit   # SQL building and parsing, no server needed
vendor/bin/phpunit                    # adds the integration tests
vendor/bin/phpunit --exclude-group buddy # omit autocomplete tests; rename may still skip without Buddy
```

The integration tests create and drop their own tables on a live server. They are skipped,
not failed, when nothing listens on `MANTICORE_HOST:MANTICORE_PORT` (`127.0.0.1:9306`
by default).

GitHub Actions runs the full suite against Manticore 28.6.6 on PHP 8.4,
Manticore 29.9.0 on PHP 7.4 and 8.4, and `latest` on PHP 8.4.
Before PHPUnit, `tests/ci/check-manticore.php`
waits for working SQL, Columnar filtering, KNN search and a Buddy table rename,
using temporary tables that it removes afterwards. Run this probe only against a test server.
The readiness timeout defaults to 90 seconds (`MANTICORE_READY_TIMEOUT`, 1–120 seconds);
the probe uses the same host and port environment variables as the integration tests.

CI uses `--fail-on-skipped --fail-on-incomplete`: missing components cannot produce
a successful full run. Local test skipping remains unchanged. JUnit reports, readiness
output, package-install logs and container diagnostics are uploaded as artifacts, including on failure,
and retained for 14 days. The workflow also supports manual runs from the Actions tab
once the workflow is on the default branch. All pinned-version and `latest` runs must pass.

The workflow runs on pushes to `main`, on pull requests, and manually. The unit matrix covers
PHP 7.4 and 8.1–8.4. All Buddy-dependent tests are included in full CI runs. See the
[testing guide](docs/testing.md) for Docker setup, readiness checks and targeted Buddy tests.

Each integration configuration also installs the committed ZIP distribution in a
separate Composer application with runtime dependencies only and runs the packaged example.

## Want to support?

If you find this package useful, just give me a star on [GitHub](https://github.com/aVadim483/manticore-query-builder-php) :)
