<?php

namespace avadim\Manticore\Tests\Unit;

use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use avadim\Manticore\QueryBuilder\Query;
use avadim\Manticore\Tests\Support\UnitTestCase;

/**
 * The SQL of the helpers borrowed from the Laravel query builder: the ones that only shape the
 * statement. What they answer with is covered by the integration test of the same name.
 */
final class QueryHelpersSqlTest extends UnitTestCase
{
    public function testTakeAndSkipAreLimitAndOffset(): void
    {
        $this->assertSqlSame(
            'SELECT * FROM products LIMIT 10',
            $this->query()->take(10)->toSql()
        );
        $this->assertSqlSame(
            'SELECT * FROM products LIMIT 20,10',
            $this->query()->skip(20)->take(10)->toSql()
        );
    }

    public function testForPageCountsFromOne(): void
    {
        $this->assertSqlSame('SELECT * FROM products LIMIT 15', $this->query()->forPage(1, 15)->toSql());
        $this->assertSqlSame('SELECT * FROM products LIMIT 15,15', $this->query()->forPage(2, 15)->toSql());
        $this->assertSqlSame('SELECT * FROM products LIMIT 40,20', $this->query()->forPage(3, 20)->toSql());
    }

    public function testLatestAndOldest(): void
    {
        $this->assertSqlSame(
            'SELECT * FROM products ORDER BY created_at DESC',
            $this->query()->latest()->toSql()
        );
        $this->assertSqlSame(
            'SELECT * FROM products ORDER BY price ASC',
            $this->query()->oldest('price')->toSql()
        );
    }

    public function testInRandomOrder(): void
    {
        $this->assertSqlSame(
            'SELECT * FROM products ORDER BY RAND()',
            $this->query()->inRandomOrder()->toSql()
        );
    }

    public function testReorderDropsTheOrderingSetSoFar(): void
    {
        $this->assertSqlSame(
            'SELECT * FROM products',
            $this->query()->orderBy('price')->reorder()->toSql()
        );
        $this->assertSqlSame(
            'SELECT * FROM products ORDER BY id DESC',
            $this->query()->orderBy('price')->reorder('id', 'desc')->toSql()
        );
    }

    public function testOrderByRawTakesTheExpressionAsItIs(): void
    {
        $this->assertSqlSame(
            'SELECT * FROM products ORDER BY WEIGHT() DESC, price ASC',
            $this->query()->orderByRaw('WEIGHT() DESC, price ASC')->toSql()
        );
    }

    public function testAddSelectAppendsColumns(): void
    {
        $this->assertSqlSame(
            'SELECT id, title, price FROM products',
            $this->query()->select('id', 'title')->addSelect('price')->toSql()
        );
    }

    public function testSelectRawTakesTheExpressionAsItIs(): void
    {
        $this->assertSqlSame(
            'SELECT price * 2 as double_price FROM products',
            $this->query()->selectRaw('price * 2 as double_price')->toSql()
        );
    }

    public function testWhereRaw(): void
    {
        $this->assertSqlSame(
            'SELECT * FROM products WHERE (price > 100 AND qty < 10)',
            $this->query()->whereRaw('price > 100 AND qty < 10')->toSql()
        );
    }

    public function testWhereNotNegatesASingleCondition(): void
    {
        $this->assertSqlSame(
            'SELECT * FROM products WHERE NOT((qty=1))',
            $this->query()->whereNot('qty', 1)->toSql()
        );
    }

    public function testWhereNotNegatesAWholeGroup(): void
    {
        $sql = $this->query()
            ->whereNot(static function ($condition) {
                $condition->where('qty', 1)->orWhere('qty', 2);
            })
            ->toSql();

        $this->assertSqlSame('SELECT * FROM products WHERE NOT(((qty=1)OR(qty=2)))', $sql);
    }

    public function testWhereAnyMatchesOneOfTheColumns(): void
    {
        $this->assertSqlSame(
            "SELECT * FROM products WHERE ((title='acme')OR(manufacturer='acme'))",
            $this->query()->whereAny(['title', 'manufacturer'], 'acme')->toSql()
        );
    }

    public function testWhereAllMatchesEveryColumn(): void
    {
        $this->assertSqlSame(
            'SELECT * FROM products WHERE ((price>10)AND(qty>10))',
            $this->query()->whereAll(['price', 'qty'], '>', 10)->toSql()
        );
    }

    public function testWhereNoneMatchesNoneOfTheColumns(): void
    {
        $this->assertSqlSame(
            'SELECT * FROM products WHERE NOT(((price>10)OR(qty>10)))',
            $this->query()->whereNone(['price', 'qty'], '>', 10)->toSql()
        );
    }

    public function testWhereRegexBuildsTheRegexCall(): void
    {
        $this->assertSqlSame(
            "SELECT * FROM products WHERE (REGEX(manufacturer, '(?i)^acme'))",
            $this->query()->whereRegex('manufacturer', '(?i)^acme')->toSql()
        );
    }

    public function testOrWhereRegexAndWhereNotRegex(): void
    {
        $this->assertSqlSame(
            "SELECT * FROM products WHERE (price>10)OR(REGEX(manufacturer, '^acme'))",
            $this->query()->where('price', '>', 10)->orWhereRegex('manufacturer', '^acme')->toSql()
        );
        $this->assertSqlSame(
            "SELECT * FROM products WHERE NOT((REGEX(manufacturer, '^acme')))",
            $this->query()->whereNotRegex('manufacturer', '^acme')->toSql()
        );
    }

    /**
     * The pattern is a value, so a quote in it must not end the string literal - and the
     * backslashes of the regex must survive the escaping
     */
    public function testWhereRegexEscapesThePattern(): void
    {
        $this->assertSqlSame(
            "SELECT * FROM products WHERE (REGEX(manufacturer, 'o\'brien'))",
            $this->query()->whereRegex('manufacturer', "o'brien")->toSql()
        );
        // the backslash is doubled in the literal and the server unescapes it back, so the
        // pattern still means "a literal dot"
        $this->assertSqlSame(
            'SELECT * FROM products WHERE (REGEX(manufacturer, \'^x\\\\.y$\'))',
            $this->query()->whereRegex('manufacturer', '^x\.y$')->toSql()
        );
    }

    public function testWhereRegexWorksInsideAGroup(): void
    {
        $sql = $this->query()
            ->where(static function ($condition) {
                $condition->whereRegex('manufacturer', '^acme')->orWhereRegex('manufacturer', '^other');
            })
            ->toSql();

        $this->assertSqlSame(
            "SELECT * FROM products WHERE ((REGEX(manufacturer, '^acme'))OR(REGEX(manufacturer, '^other')))",
            $sql
        );
    }

    public function testWhereMatchIsAnAliasOfMatch(): void
    {
        $this->assertSqlSame(
            "SELECT * FROM products WHERE MATCH('galaxy')",
            $this->query()->whereMatch('galaxy')->toSql()
        );
        $this->assertSqlSame(
            $this->query()->match('galaxy')->toSql(),
            $this->query()->whereMatch('galaxy')->toSql()
        );
    }

    public function testMatchCanBeLimitedToFields(): void
    {
        $this->assertSqlSame(
            "SELECT * FROM products WHERE MATCH('@(title) galaxy')",
            $this->query()->match('galaxy', 'title')->toSql()
        );
        $this->assertSqlSame(
            "SELECT * FROM products WHERE MATCH('@(title,info) galaxy')",
            $this->query()->whereMatch('galaxy', ['title', 'info'])->toSql()
        );
    }

    /**
     * What a user typed is not an expression: every operator of the query language has to
     * become a literal, or the search means something else than what was asked for
     */
    public function testEscapeMatchTurnsOperatorsIntoLiterals(): void
    {
        $this->assertSame('iPhone \\-Pro', Query::escapeMatch('iPhone -Pro'));
        $this->assertSame('a \\| b', Query::escapeMatch('a | b'));
        $this->assertSame('\\@title x', Query::escapeMatch('@title x'));
        $this->assertSame('say \\"hi\\"', Query::escapeMatch('say "hi"'));
        $this->assertSame('plain words 15', Query::escapeMatch('plain words 15'));
    }

    public function testEscapeMatchIsAlsoOnTheFacade(): void
    {
        $this->assertSame(Query::escapeMatch('iPhone -Pro'), ManticoreDb::escapeMatch('iPhone -Pro'));
    }

    public function testHavingRaw(): void
    {
        $this->assertSqlSame(
            'SELECT * FROM products GROUP BY manufacturer HAVING COUNT(*) > 1',
            $this->query()->groupBy('manufacturer')->havingRaw('COUNT(*) > 1')->toSql()
        );
    }

    public function testWhenAppliesTheCallbackOnlyForATruthyValue(): void
    {
        $applied = $this->query()->when(true, static function ($query) {
            $query->where('qty', 1);
        });
        $skipped = $this->query()->when(false, static function ($query) {
            $query->where('qty', 1);
        });

        $this->assertSqlSame('SELECT * FROM products WHERE (qty=1)', $applied->toSql());
        $this->assertSqlSame('SELECT * FROM products', $skipped->toSql());
    }

    public function testWhenFallsBackToTheDefaultCallback(): void
    {
        $query = $this->query()->when(
            false,
            static function ($query) {
                $query->where('qty', 1);
            },
            static function ($query) {
                $query->where('qty', 2);
            }
        );

        $this->assertSqlSame('SELECT * FROM products WHERE (qty=2)', $query->toSql());
    }

    public function testUnlessIsTheOtherWayRound(): void
    {
        $query = $this->query()->unless(false, static function ($query) {
            $query->where('qty', 1);
        });

        $this->assertSqlSame('SELECT * FROM products WHERE (qty=1)', $query->toSql());
    }

    public function testTapHandsTheQueryOverAndGoesOn(): void
    {
        $seen = null;
        $query = $this->query()->tap(static function ($query) use (&$seen) {
            $seen = $query;
        })->where('qty', 1);

        $this->assertNotNull($seen);
        $this->assertSqlSame('SELECT * FROM products WHERE (qty=1)', $query->toSql());
    }

    /**
     * The conditions must not be shared with the copy, or a branch would narrow the query
     * it branched off
     */
    public function testCloneDoesNotShareTheConditions(): void
    {
        $base = $this->query()->where('price', '>', 10);
        $branch = $base->clone()->where('qty', 1);

        $this->assertSqlSame('SELECT * FROM products WHERE (price>10)', $base->toSql());
        $this->assertSqlSame('SELECT * FROM products WHERE (price>10)AND(qty=1)', $branch->toSql());
    }
}
