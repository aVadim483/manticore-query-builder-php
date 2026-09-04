# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.4.0] - 2026-09-04

### Added

* `ResultSet::of(array $rows, array $meta = [])` and `ResultSet::empty()` — a result set built out
  of rows rather than out of an answer of the server. Whoever has rows and no server to get them
  from — a driver answering with nothing where the index is not there yet, a test standing in for
  an answer — had to write out the array the constructor reads, which is the shape of the class
  rather than of its API. What `meta` says wins over the total the rows themselves make, so a page
  of a larger result can carry the total of it.

## [2.3.0] - 2026-09-04

### Added

* `Builder::forgetConnection(?string $name = null)` — drops one connection from the pool, so that
  the next call for it opens a new one. Until now the only way to reach the pool was `init()`,
  which is the reconfiguration of the whole builder: it replaces the config and drops the logger
  along with every connection. A process that lives for one request never needs either; a queue
  worker whose handle the server closed overnight, an Octane process handing the application to
  the next request, or a test that wants the connection of the previous one gone needs exactly
  this. No name means the default connection, the answer says whether there was one to forget, and
  the object itself is not closed — whoever holds a reference goes on using it.

  This is what `avadim/manticore-query-builder-laravel` builds `Manager::purge()` on.

## [2.2.0] - 2026-08-30

A release of fixes, two of which change behaviour that was there before — see **Breaking**.

### Breaking

* **A value is quoted whatever it looks like.** Until now a string matching `:word` was
  written into the statement as a named parameter instead of being escaped, so an ordinary
  value — `':30'` is a time someone typed — built SQL with a placeholder that was never bound
  and the server answered *Invalid parameter number*. A parameter in the place of a value is
  now asked for explicitly:

  ```php
  // before
  ->where('country', ':country')->bind([':country' => $country])
  // now
  ->where('country', ManticoreDb::param('country'))->bind([':country' => $country])
  ```

  Named parameters inside an expression are untouched: `where('country=:country')` and
  `select(['IN(color, :black) as f'])` work exactly as before.

* **The rows of a join come back as a list, not as a dictionary keyed by the document id.**
  A join answers with one row per pair, so the id of the left table repeats itself as soon as
  the relation is one to many — and keying by it kept only the last row of every document.
  Code reading `$rows[$id]` after `join()`/`leftJoin()` has to walk the list instead.

* `PDOClient::prepare()` returns `\PDOStatement` instead of `?\PDOStatement`. Only a subclass
  of the client can notice this.

### Added

* `ManticoreDb::param()` / `Query::param()` — a named parameter in the place of a value.
* Continuous integration: the unit suite on PHP 7.4 and 8.1–8.4, the whole suite on 7.4 and
  8.4 against a Manticore service container.
* `.gitattributes` — the tests, the documentation and the CI config are no longer part of the
  package: the dist archive is about a third of its previous size.
* `.gitignore` — `vendor/`, `composer.lock` and the caches of PHPUnit.

### Fixed

* **Non-ASCII text is no longer cut off.** The parser walks the statement byte by byte but
  counted its length in characters, so everything after a non-ASCII word was dropped:
  `sql("UPDATE ?products SET title='Привет, мир', qty=5 WHERE id=1")` reached the server as
  `UPDATE test_products SET title='Привет, ми`. The same applied to column lists —
  `select("id, 'Привет, мир' as greeting, price")`.
* **A rejected statement is never reported as a success.** A statement PDO refused to prepare
  came back as an answer without rows, which the builder read as a successful query with an
  empty result; the same answer is now raised as an error and lands in `ResultSet::error()`.
* **`timeout` limits the opening of the connection.** It was applied to the PDO handle after
  the connection was already made, i.e. never to the connecting itself — an unreachable server
  held the process until the TCP timeout of the system rather than the configured seconds.
* **A column named after a PHP function is a column.** `is_callable('time')` is true, so
  `where('time', 30)` took the column name for a group of conditions and called the function
  itself. Affected `time`, `date`, `key`, `count`, `link`, `hash`, `sort` and the like.
* **Aggregates answer with a number on PHP 7.4 too.** `sum()`, `avg()`, `min()` and `max()`
  came back as strings (`'30.000000'`) there, while PHP 8.1 and above turned them into floats
  by themselves: a computed column has no type in `DESCRIBE` to be cast by, so the type the
  server reports for it is read instead. The same applies to any float expression selected
  under an alias.
* A `Query` built without the `client` key of the config no longer raises a warning, and opens
  its connection with the config it was given instead of with an empty one.

## [2.1.0] - 2026-08-15

### Added

* Every CALL statement of the server as a method: `callSuggest()`, `callQsuggest()`,
  `callKeywords()`, `callSnippets()` and `callPq()`.

### Changed

* `ext-curl` dropped from `require`: the library talks SQL over PDO and has no HTTP branch.
* The readme points at the Laravel wrapper and the Scout driver.

## [2.0.0] - 2026-08-14

### Added

* Vector search: `float_vector` columns and `whereKnn()`.
* `join()` and `leftJoin()`.
* Statements, transactions, conditions on dates, raw expressions; `escapeMatch()`, and
  `match()` limited to given fields.
* The helpers of the Laravel query builder: aggregates, chunked walks, conditional building,
  `upsert()` and the rest.
* The imperative ALTER statements: `addColumn()`, `dropColumn()`, `modifyColumn()`,
  `rename()`, `alterSettings()`.
* Hooks for a framework wrapper: `Connection::$queryClass` and `Builder::setConnectionClass()`.
* Documentation in `docs/`, with a Russian translation.

### Changed

* A rejected read throws `QueryErrorException`; a rejected write answers with `false` or `0`
  and keeps its reason in the `ResultSet`.
* `insert()` / `update()` / `delete()` / `replace()` answer the Laravel way, with the
  `*ResultSet()` twins keeping the whole answer.
* The schema cache belongs to the connection and is dropped by the statements that change
  columns.

### Fixed

* `multi64` columns were read as a string, an empty MVA column as `[0]`.
* Implicitly nullable parameters (deprecated since PHP 8.4).
* `explain()` reimplemented on top of `EXPLAIN QUERY`.
* A computed column named by an int no longer breaks the type casting.

## Earlier releases

For 1.x see the commit history: <https://github.com/aVadim483/manticore-query-builder-php/commits/main>

[2.4.0]: https://github.com/aVadim483/manticore-query-builder-php/compare/v2.3.0...v2.4.0
[2.3.0]: https://github.com/aVadim483/manticore-query-builder-php/compare/v2.2.0...v2.3.0
[2.2.0]: https://github.com/aVadim483/manticore-query-builder-php/compare/v2.1.0...v2.2.0
[2.1.0]: https://github.com/aVadim483/manticore-query-builder-php/compare/v2.0.0...v2.1.0
[2.0.0]: https://github.com/aVadim483/manticore-query-builder-php/compare/v1.15.0...v2.0.0
