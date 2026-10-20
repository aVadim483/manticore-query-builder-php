**English** | [Русский](ru/README.md)

# Manticore Search Query Builder for PHP (unofficial PHP client)

## Documentation

---

* [Configuration](configuration.md)
* [Searching](searching.md)
* [Inserting and replacing documents](writing.md)
* [Create, Listing & Describe Tables](tables.md)
* [ResultSet Class](result_set.md)
* [Logging](logging.md)
* [Testing and Buddy](testing.md)

## Choosing the result API

| Need | Method |
|---|---|
| Rows with automatic metadata retrieval | `Query::get()` |
| Rows and metadata in one result object | `Query::search()` |
| Rows without SHOW META (2.6) | `Query::getRows()` |
| Explicit current connection metadata (2.6) | `Query::getMeta(): Meta` |
| Loaded metadata as an array | `ResultSet::meta(): array` |
| Loaded metadata as an immutable object (2.6) | `ResultSet::metaObject(): Meta` |
| Whether metadata was loaded (2.6) | `ResultSet::hasMeta(): bool` |

Features marked 2.6 are available since [2.6.0](../CHANGELOG.md).
Existing defaults are preserved. See [searching](searching.md),
[ResultSet](result_set.md) and [writing](writing.md) for details.

[Russian documentation](ru/README.md) and [quick start](../README.ru.md).

