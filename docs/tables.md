# Manticore Search Query Builder for PHP (unofficial PHP client)

## Listing tables

### SHOW TABLES

```php
// Plain SQL
$res = ManticoreDb::sql('SHOW TABLES')->get();
// or with the method
$res = ManticoreDb::showTables();
```
| Index         | Table         | Name        | Type |
|---------------|---------------|-------------|------|
| test_products | test_products |  ?_products | rt   |


```php
// Get tables by pattern
$res = ManticoreDb::sql('SHOW TABLES LIKE abc%')->get();
$res = ManticoreDb::showTables('abc%');

// Get tables with prefix
$res = ManticoreDb::showTables('?%');
```

### DESCRIBE TABLE

```php
$res = ManticoreDb::sql('DESC test')->get();
$res = ManticoreDb::table('test')->describe()->result();
$res = ManticoreDb::describe('test');
```
| Field   | Type     | Properties     |
|---------|----------|----------------|
| id      | bigint   |                |
| title   | text     | indexed stored |
| price   | float    |                |

### SHOW CREATE TABLE

```php
$res = ManticoreDb::showCreate('test');
$res = ManticoreDb::showCreateTable('test');
// Result is string like
// "CREATE TABLE test (
//      price float,
//      title text,
//      tags text
//  ) morphology='lemmatize_ru_all,lemmatize_en_all'"
```
