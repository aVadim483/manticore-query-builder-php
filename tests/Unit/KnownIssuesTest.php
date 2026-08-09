<?php

namespace avadim\Manticore\Tests\Unit;

use avadim\Manticore\QueryBuilder\Facet;
use avadim\Manticore\QueryBuilder\Parser;
use avadim\Manticore\Tests\Support\FakeClient;
use avadim\Manticore\Tests\Support\UnitTestCase;

/**
 * Defects found while writing the test suite that are still open.
 *
 * Each test states the behaviour the public API promises; every one of them is marked
 * incomplete so that a red suite does not hide real regressions. Drop the markTestIncomplete()
 * line once the corresponding bug is fixed - the assertion below it is the acceptance criterion.
 */
final class KnownIssuesTest extends UnitTestCase
{
    /**
     * Query::insert(array $data, ?int $id = 0) declares the second parameter but never uses it,
     * unlike replace(), which does apply it. The id is silently dropped.
     */
    public function testInsertAppliesExplicitId(): void
    {
        $this->markTestIncomplete('insert($data, 42) ignores the $id argument');

        $client = new FakeClient($this->productColumnTypes());
        $this->queryFor($client)->insert(['title' => 'x'], 42);

        $this->assertSqlSame("INSERT INTO products(title,id) VALUES('x',42)", $client->lastQuery());
    }

    /**
     * Parser::resolveTableName() supports `?table`, but the raw-SQL path does not: the per-command
     * regexps in Parser::parseSql() match \w+ table names only, so a backticked placeholder
     * is left in the query as is.
     */
    public function testRawSqlResolvesBacktickedPlaceholder(): void
    {
        $this->markTestIncomplete('raw SQL keeps "`?products`" unresolved');

        $sql = $this->query(null, [], ['prefix' => 'second_'])->sql('select * from `?products`')->toSql();

        $this->assertSame('select * from `second_products`', mb_strtolower($sql));
    }

    /**
     * With force_prefix the prefix is glued in front of the backtick instead of inside it.
     */
    public function testForcePrefixHandlesBacktickedNames(): void
    {
        $this->markTestIncomplete('resolveTableName("`products`", "pre_", true) returns "pre_`products`"');

        $this->assertSame('`pre_products`', Parser::resolveTableName('`products`', 'pre_', true));
    }

    /**
     * Facet::offset() writes into limit[0], which is where limit() keeps the limit itself,
     * so setting an offset silently replaces the limit.
     */
    public function testFacetOffsetKeepsLimit(): void
    {
        $this->markTestIncomplete('Facet::offset() overwrites the limit instead of adding an offset');

        $facet = new Facet('brand');
        $facet->limit(5)->offset(10);

        $this->assertSame('FACET brand LIMIT 10,5', (string)$facet);
    }
}
