<?php

namespace avadim\Manticore\Tests\Unit;

use avadim\Manticore\Tests\Support\UnitTestCase;

/**
 * SELECT clause assembly: columns, grouping, ordering, limits, OPTION and FACET.
 */
final class SelectSqlTest extends UnitTestCase
{
    public function testSelectAllByDefault(): void
    {
        $this->assertSqlSame('SELECT * FROM products', $this->query()->select()->toSql());
    }

    public function testSelectColumnsFromArray(): void
    {
        $this->assertSqlSame(
            'SELECT id, title FROM products',
            $this->query()->select(['id', 'title'])->toSql()
        );
    }

    public function testSelectColumnsFromString(): void
    {
        $this->assertSqlSame(
            'SELECT id, title FROM products',
            $this->query()->select('id, title')->toSql()
        );
    }

    public function testSelectKeepsFunctionCallsWithCommas(): void
    {
        $this->assertSqlSame(
            'SELECT id, IN(color, 1, 2) as f FROM products',
            $this->query()->select('id, IN(color, 1, 2) as f')->toSql()
        );
    }

    public function testTableNameIsResolvedWithPrefix(): void
    {
        $query = $this->query('?products', [], ['prefix' => 'pre_']);

        $this->assertSqlSame('SELECT * FROM pre_products', $query->select()->toSql());
    }

    public function testForcePrefixAppliesToPlainNames(): void
    {
        $query = $this->query('products', [], ['prefix' => 'pre_', 'force_prefix' => true]);

        $this->assertSqlSame('SELECT * FROM pre_products', $query->select()->toSql());
    }

    public function testIndexIsAliasOfTable(): void
    {
        $this->assertSame(
            $this->query('a')->table('other')->toSql(),
            $this->query('a')->index('other')->toSql()
        );
    }

    public function testGroupByAndHaving(): void
    {
        $sql = $this->query()
            ->select(['cat', 'count(*) as c'])
            ->groupBy('cat')
            ->having('c>1')
            ->toSql();

        $this->assertSqlSame('SELECT cat, count(*) as c FROM products GROUP BY cat HAVING c>1', $sql);
    }

    public function testOrderByAscAndDesc(): void
    {
        $sql = $this->query()->orderBy('price')->orderByDesc('id')->toSql();

        $this->assertSqlSame('SELECT * FROM products ORDER BY price ASC,id DESC', $sql);
    }

    public function testLimit(): void
    {
        $this->assertSqlSame('SELECT * FROM products LIMIT 10', $this->query()->limit(10)->toSql());
    }

    public function testLimitWithOffsetMethod(): void
    {
        $this->assertSqlSame(
            'SELECT * FROM products LIMIT 5,10',
            $this->query()->limit(10)->offset(5)->toSql()
        );
    }

    public function testLimitWithTwoArguments(): void
    {
        // limit(<offset>, <limit>)
        $this->assertSqlSame(
            'SELECT * FROM products LIMIT 5,10',
            $this->query()->limit(5, 10)->toSql()
        );
    }

    public function testOptionsAreJoinedIntoSingleClause(): void
    {
        $sql = $this->query()
            ->maxMatches(100)
            ->ranker('bm25')
            ->maxQueryTime(50)
            ->toSql();

        $this->assertSqlSame(
            'SELECT * FROM products OPTION max_matches=100,ranker=bm25,max_query_time=50',
            $sql
        );
    }

    public function testBooleanOptionIsRenderedAsInt(): void
    {
        $this->assertSqlSame(
            'SELECT * FROM products OPTION expand_keywords=1',
            $this->query()->expandKeywords(true)->toSql()
        );
        $this->assertSqlSame(
            'SELECT * FROM products OPTION expand_keywords=0',
            $this->query()->expandKeywords(false)->toSql()
        );
    }

    public function testAgentQueryTimeoutOption(): void
    {
        $this->assertSqlSame(
            'SELECT * FROM products OPTION agent_query_timeout=1000',
            $this->query()->agentQueryTimeout(1000)->toSql()
        );
    }

    public function testArbitraryOption(): void
    {
        $this->assertSqlSame(
            'SELECT * FROM products OPTION cutoff=5',
            $this->query()->option('cutoff', 5)->toSql()
        );
    }

    public function testOptionsMethodBelongsToCreateAndDoesNotAffectSelect(): void
    {
        // options() sets table options for CREATE TABLE; the SELECT OPTION clause is built
        // by option() and the dedicated helpers - see testArbitraryOption()
        $this->assertSqlSame(
            'SELECT * FROM products',
            $this->query()->options(['morphology' => 'stem_en'])->toSql()
        );
    }

    public function testFieldWeightsFromArray(): void
    {
        $this->assertSqlSame(
            'SELECT * FROM products OPTION field_weights=(title=10,content=3)',
            $this->query()->fieldWeights(['title' => 10, 'content' => 3])->toSql()
        );
    }

    public function testFieldWeightsFromString(): void
    {
        $this->assertSqlSame(
            'SELECT * FROM products OPTION field_weights=(title=10,content=3)',
            $this->query()->fieldWeights('(title=10,content=3)')->toSql()
        );
    }

    public function testFieldWeightsFromStringWithoutBraces(): void
    {
        $this->assertSqlSame(
            'SELECT * FROM products OPTION field_weights=(title=10)',
            $this->query()->fieldWeights('title = 10')->toSql()
        );
    }

    public function testFieldWeightAddsSingleWeight(): void
    {
        $this->assertSqlSame(
            'SELECT * FROM products OPTION field_weights=(title=5)',
            $this->query()->fieldWeight('title', 5)->toSql()
        );
    }

    public function testFieldWeightsAccumulate(): void
    {
        $this->assertSqlSame(
            'SELECT * FROM products OPTION field_weights=(title=5,content=2)',
            $this->query()->fieldWeight('title', 5)->fieldWeight('content', 2)->toSql()
        );
    }

    public function testHighlightAddsColumn(): void
    {
        $this->assertSqlSame(
            "SELECT *, HIGHLIGHT() AS _highlight FROM products WHERE MATCH('x')",
            $this->query()->match('x')->highlight()->toSql()
        );
    }

    public function testHighlightWithStringOptions(): void
    {
        $sql = $this->query()->match('x')->highlight(['before_match' => '<b>', 'after_match' => '</b>'])->toSql();

        $this->assertSqlSame(
            "SELECT *, HIGHLIGHT({before_match='<b>',after_match='</b>'}) AS _highlight FROM products WHERE MATCH('x')",
            $sql
        );
    }

    public function testHighlightWithNumericOption(): void
    {
        $this->assertSqlSame(
            "SELECT *, HIGHLIGHT({limit='50'}) AS _highlight FROM products WHERE MATCH('x')",
            $this->query()->match('x')->highlight(['limit' => 50])->toSql()
        );
    }

    public function testHighlightKeepsExplicitColumns(): void
    {
        $sql = $this->query()->select(['id'])->match('x')->highlight()->toSql();

        $this->assertSqlSame("SELECT id, HIGHLIGHT() AS _highlight FROM products WHERE MATCH('x')", $sql);
    }

    public function testFacetIsAppendedAfterQuery(): void
    {
        $this->assertSqlSame(
            "SELECT * FROM products WHERE MATCH('x') FACET manufacturer",
            $this->query()->match('x')->facet('manufacturer')->toSql()
        );
    }

    public function testSeveralFacets(): void
    {
        $this->assertSqlSame(
            'SELECT * FROM products FACET a FACET b',
            $this->query()->facet('a')->facet('b')->toSql()
        );
    }

    public function testFacetCallbackConfiguresClause(): void
    {
        $sql = $this->query()
            ->facet('price', function ($facet) {
                $facet->alias('p')->orderByDesc('count(*)')->limit(5);
            })
            ->toSql();

        $this->assertSqlSame('SELECT * FROM products FACET price AS p ORDER BY count(*) DESC LIMIT 5', $sql);
    }

    public function testFacetsGoAfterLimitAndOptions(): void
    {
        $sql = $this->query()->limit(3)->maxMatches(10)->facet('brand')->toSql();

        $this->assertSqlSame('SELECT * FROM products LIMIT 3 OPTION max_matches=10 FACET brand', $sql);
    }

    public function testParseReturnsCommandAndTable(): void
    {
        $parsed = $this->query()->select()->parse();

        $this->assertSame('SELECT', $parsed['command']);
        $this->assertSame('products', $parsed['table']);
        $this->assertSame('SELECT * FROM products', $parsed['query']);
    }

    public function testParseExposesFacets(): void
    {
        $parsed = $this->query()->facet('brand')->parse();

        $this->assertArrayHasKey('facets', $parsed);
        $this->assertCount(1, $parsed['facets']);
    }
}
