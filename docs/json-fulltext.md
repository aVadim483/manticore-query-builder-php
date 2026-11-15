# JSON filters and composed full-text conditions

[Русский](ru/json-fulltext.md)

These helpers extend the existing JSON attributes and `match()` API. They do not
require Laravel. All examples start with `ManticoreDb::table('products')` and return
the same query builder until a terminal method is called.

## JSON array membership

```php
$rows = ManticoreDb::table('products')
    ->whereJsonContains('info->tags', ['sale', 'new'])
    ->where(function ($where) {
        $where->whereJsonOverlaps('info->categories', [2, 3])
            ->orWhereJsonLength('info.tags', 0);
    })->get();
```

| Method | Meaning |
| --- | --- |
| `whereJsonContains($path, $candidates)` | Every candidate occurs in the JSON array |
| `whereJsonDoesntContain($path, $candidates)` | At least one candidate is absent |
| `whereJsonOverlaps($path, $candidates)` | At least one candidate occurs |
| `whereJsonDoesntOverlap($path, $candidates)` | No candidate occurs |
| `whereJsonContainsKey($path)` | Path resolves to a non-null value |
| `whereJsonDoesntContainKey($path)` | Path is missing or JSON null |
| `whereJsonLength($path, $length)` | Non-null path has the native LENGTH given |
| `whereJsonLength($path, $operator, $length)` | Compare native LENGTH |

Every method also has an `orWhereJson...` variant and is available inside nested
`where()` groups. Membership accepts a scalar or a list of strings, booleans and
finite numbers. `null`, objects, associative arrays and nested candidate arrays
are rejected. Contains with an empty list is rejected; overlaps with an empty list
never matches and its negation always matches.

Membership targets array elements, not recursive object containment. A missing or
non-array target has no matching elements and therefore matches the negative
membership predicates. Comparisons use Manticore's native type coercion; this API
does not promise strict JSON scalar-type equality.

## Paths, null and length

Existing simple dot paths such as `info.tags[0]` work in these helpers. Arrow paths
support string keys, non-negative array indexes and JSON-quoted keys:

```php
$query->whereJsonContainsKey('info->dimensions->width');
$query->whereJsonContainsKey('info->tags[1]');
$query->whereJsonContainsKey('info->"key.with.dots"->value');
```

Indexes must fit a signed 32-bit integer. Wildcards, empty path segments and SQL
fragments are rejected. This does not change the existing general `where()` path
syntax and does not add JSON-path assignments for UPDATE.

Manticore treats missing paths and JSON `null` alike. Key presence excludes both;
zero, false, empty strings, empty arrays and empty objects are non-null values.
Store a separate presence attribute if the distinction matters to the application.

Length comparisons accept `=`, `!=`, `<>`, `<`, `<=`, `>` and `>=` with a non-negative
PHP integer. Missing/null paths are excluded even from length-zero predicates.
LENGTH follows the server's native rules for arrays, objects and scalar values.

Membership and length compile to hidden SELECT expressions because the SQL WHERE
grammar cannot evaluate them directly. Generated fields are removed from returned
rows, including `search()` and aggregate helper results. A generated alias collision
with a selected field or table attribute raises `LogicException` rather than losing
the user's field. These computed filters are SELECT-only: UPDATE and DELETE reject
them. Key checks can filter attribute UPDATE and DELETE normally.

## Composed full-text conditions

```php
$result = ManticoreDb::table('products')->where('stock', '>', 0)
    ->where(function ($where) {
        $where->whereFullText('title', 'phone')
            ->orWhereFullText(['title', 'body'], 'tablet');
    })->whereJsonContains('info.tags', 'sale')
    ->orderByDesc('_score')->limit(20)->search();
```

`whereFullText($fields, $text, $options = [])` and `orWhereFullText()` accept one
simple TEXT field name or a non-empty list of names, and non-empty search text.
Repeated conditions compose into one MATCH expression; AND binds more tightly
than OR and nested groups preserve explicit boundaries. `_score` is included by
the normal read path unless `withoutScore()` was requested.

The only option is `escape` (boolean, default true). Literal visitor text is escaped
for Manticore operators and then quoted as an SQL value. To use a trusted native
expression inside the field scope, choose `['escape' => false]`. This still escapes
the SQL literal; field scoping is a search convenience rather than access control.
Tokenization and morphology remain controlled by the table configuration.

Manticore cannot represent OR between a MATCH and an attribute predicate. These
helpers reject mixed text/attribute OR trees and negated text groups. Put text
alternatives in their own group and combine the attribute group through AND.
Attribute-only OR groups remain supported.

Use one full-text API per query. Mixing `match()` with `whereFullText()` raises
`LogicException`, including when the composed text is nested. Existing repeated
`match()` / `whereMatch()` calls still replace the previous complete expression.

## Expression ranking

```php
$rows = ManticoreDb::table('products')->whereFullText('title', 'phone')
    ->rankerExpression('sum(lcs*user_weight)')->get();
```

`rankerExpression($formula, $ranker = 'expr')` accepts `expr` or `export` and quotes
the formula safely. An empty formula, null byte or unsupported ranker is rejected.
The existing `ranker(string $value)` signature and behavior are unchanged.

Requirements follow the server's SQL JSON/MATCH/expression-ranker support; the
release testing guide records tested server versions. These helpers do not require
Buddy, automatic embeddings or external models.
