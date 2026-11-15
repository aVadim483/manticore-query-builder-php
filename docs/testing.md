**English** | [Русский](ru/testing.md)

# Testing and Manticore Buddy

Run commands from the repository root after `composer install`. Use PHP 7.4 or PHP 8.1+
with JSON, mbstring, PDO and pdo_mysql. Dependencies must be compatible with the PHP version
running PHPUnit; do not reuse a vendor directory resolved for PHP 8 when testing PHP 7.4.

## Offline tests

```sh
php vendor/phpunit/phpunit/phpunit --testsuite unit
```

These tests use fake clients and require no Manticore server.

## A separate server with Buddy

The official Docker image includes Buddy and the Columnar/KNN libraries. Install and start
Docker before using these commands; see the [server Docker guide](https://manual.manticoresearch.com/Starting_the_server/Docker).

```sh
docker run --name manticore-tests --rm -d -p 127.0.0.1:19306:9306 -p 127.0.0.1:19308:9308 manticoresearch/manticore:29.9.0
```

The non-default host ports keep this test server separate from a local server on 9306/9308.
Select the test SQL port in the same shell as PHPUnit. In PowerShell:

```powershell
$env:MANTICORE_HOST = '127.0.0.1'
$env:MANTICORE_PORT = '19306'
```

In Bash:

```sh
export MANTICORE_HOST=127.0.0.1
export MANTICORE_PORT=19306
```

Check functional readiness, then run all tests:

```sh
php tests/ci/check-manticore.php
php vendor/phpunit/phpunit/phpunit --fail-on-skipped --fail-on-incomplete
```

The probe waits for SQL, Columnar filtering, KNN search and a Buddy table rename. An open
TCP port alone is insufficient. It creates and removes temporary tables; use a test server.
The readiness timeout defaults to 90 seconds and can be set with `MANTICORE_READY_TIMEOUT`
(1–120 seconds). Run tests against a given local server sequentially.

Stop the disposable server after testing:

```sh
docker stop manticore-tests
```

The `--rm` container has no persistent data volume; stopping it removes its container data.
Restore any previous environment variable values before returning to another server.

## Buddy-specific tests

```sh
# CALL AUTOCOMPLETE: two tests in the buddy group
php vendor/phpunit/phpunit/phpunit --testsuite integration --group buddy --fail-on-skipped

# Table rename also needs Buddy; this test is not in that group
php vendor/phpunit/phpunit/phpunit --filter 'AlterTableTest::testRename' --fail-on-skipped
```

Autocomplete tests create tables with `min_infix_len = 2` themselves. You do not need to
prepare a dictionary or application table. Missing Buddy may cause an autocomplete failure
or a skipped rename; `--fail-on-skipped` prevents a skip from counting as a successful check.

On a server without Buddy, this is a limited local check:

```sh
php vendor/phpunit/phpunit/phpunit --exclude-group buddy
```

It excludes autocomplete, but does not exclude the rename test, which can skip. If the server
is unreachable, integration tests skip entirely. Neither result establishes Buddy support.
Inspect `docker logs manticore-tests` when readiness fails. For native Windows installations,
Buddy requires Docker; see [Buddy installation](https://manual.manticoresearch.com/Installation/Manticore_Buddy).

## GitHub Actions

The [tests workflow](../.github/workflows/tests.yml) runs on pushes to `main`, on pull
requests, and through **Actions → tests → Run workflow**. A push to another branch without
a pull request does not start it automatically. Manual runs test the selected remote branch,
not uncommitted local changes.

| Job | PHP | Manticore |
|---|---|---|
| Unit | 7.4, 8.1, 8.2, 8.3, 8.4 | Not required |
| Full suite, including Buddy | 8.4 | 28.6.6 |
| Full suite, including Buddy | 7.4, 8.4 | 29.9.0 |
| Full suite, including Buddy | 8.4 | latest |

The matrix contains nine jobs: five unit jobs and four full-suite jobs.
Every full job runs the readiness probe and PHPUnit with `--fail-on-skipped
--fail-on-incomplete`, without excluding the Buddy group. Missing components, skipped tests
and incomplete tests fail the job. All matrix jobs must pass.

JUnit reports, readiness output and Manticore container diagnostics are retained as Actions
artifacts for 14 days, including on failures. They show whether a problem came from server
startup, a missing component or a library test.

## Compatibility checks for new APIs

Each of the four integration jobs also installs the committed ZIP distribution in a separate
Composer application and runs the [packaged example](../examples/README.md).
This requires ext-zip in the test environment, not as a new library dependency.
The consumer installs only runtime dependencies; its classes must resolve inside
the installed package, with no checkout autoloader or PHPUnit dependency.

To reproduce against a test server after committing the code:

```sh
mkdir -p build/package-dist
git archive --format=zip --prefix=package/ --output=build/package-dist/library.zip HEAD
php tests/ci/prepare-package.php build/package-dist/library.zip build/package-consumer
composer install --working-dir=build/package-consumer --no-dev --prefer-dist --no-progress --no-interaction --no-plugins --no-scripts
php tests/ci/check-package.php build/package-consumer
```

Set `MANTICORE_HOST` / `MANTICORE_PORT` as above. Use a new empty consumer directory
for another run. `git archive HEAD` includes committed files only. The test-only
`2.7.0-dev` package repository uses metadata from that archive and a checked ZIP
checksum; it contains no source URL and does not publish or modify a release.
Preparation, installation and smoke logs are retained with the integration artifacts.

Manticore 28.6.6 is the pinned baseline for the new batch, JSON/full-text and extended
numeric KNN/hybrid APIs. This does not raise the server requirement for older APIs.
The fixed 29.9.0 image and `latest` also run the complete suite with their bundled
components. Numeric vector fixtures do not require an external embedding provider.

Keep the existing signatures and default behavior of `whereKnn()`, `ranker()`,
ordinary reads/writes and custom clients. New APIs must have offline error/SQL tests
and real-server behavior tests, including nested filters, cloned queries and errors.
Do not treat generated SQL, skipped integration tests or local runs without Buddy
as proof that the entire matrix passes.
