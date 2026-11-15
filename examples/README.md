# Runnable examples

[Русский](README.ru.md)

## Batch import, JSON filters and hybrid search

`hybrid-search.php` creates a uniquely named local RT table, imports five documents
from a generator in three batches, applies JSON and composed full-text filters,
runs independent text/vector searches with RRF in PHP, and pages the result window.
It prints one JSON report and removes its own table before printing the report.

Use a test Manticore server with SQL access, permission to create/drop RT tables,
and its KNN library. The example works with the release's tested Manticore versions
(28.6.6, 29.9.0 and `latest`). It needs PHP 7.4 or PHP 8.1+, the library's Composer
dependencies and PDO MySQL. Buddy and an embedding provider are not required;
the small vectors are fixed demonstration data, not meaningful language embeddings.

From a checkout or an extracted source archive:

```sh
composer install
php examples/hybrid-search.php
```

From an application where this version of the package is installed with Composer:

```sh
php vendor/avadim/manticore-query-builder-php/examples/hybrid-search.php
```

The script uses `127.0.0.1:9306` by default. To select a separate test instance:

```powershell
$env:MANTICORE_HOST = '127.0.0.1'
$env:MANTICORE_PORT = '19306'
php examples/hybrid-search.php
```

```sh
MANTICORE_HOST=127.0.0.1 MANTICORE_PORT=19306 php examples/hybrid-search.php
```

Optional `MANTICORE_USERNAME` / `MANTICORE_PASSWORD` configure SQL authentication.
No credentials are included in the successful report. Restore environment variables
after testing when returning to another server.

The JSON contains:

- `batch`: five acknowledged input documents and three completed SQL batches;
- `fulltext`: documents 1, 2 and 3 from an ordinary composed MATCH with common filters;
- `hybrid`: ranked hits 3, 1 and 2 with fused scores, branch evidence and metadata;
- `page`: document 2 on page 2 with a page size of 2.

Document 1 contributes through text, document 2 through the vector window, and
document 3 through both. Stock and JSON filters exclude documents 4 and 5 from
both branches. The script checks these expected results and exits with a nonzero
status on errors or a failed cleanup. It only drops the randomly named table after
its CREATE was acknowledged; it does not delete an existing application table.

`window_total` is 3, while `total_found` is null: paging the materialized candidate
window does not reveal the global total or issue another SQL query. The hybrid
export includes each document, fused score, text/vector ranks and branch metadata.
See the [KNN/hybrid guide](https://github.com/aVadim483/manticore-query-builder-php/blob/main/docs/knn-hybrid.md) in the repository for the API and
limitations. Documentation under `docs/` is omitted from the release dist archive;
the runnable example and this README are included.
