<?php

namespace avadim\Manticore\Tests\Integration;

use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use avadim\Manticore\Tests\Support\IntegrationTestCase;

/**
 * MATCH() operators, ranking options and HIGHLIGHT().
 *
 * The assertions on result order depend on the server ranker, which is the point:
 * the builder must pass the full-text syntax through untouched.
 */
final class FullTextSearchTest extends IntegrationTestCase
{
    /** @var string */
    private string $table;

    protected function setUp(): void
    {
        parent::setUp();
        $this->table = $this->createTable(['title' => 'text', 'content' => 'text'], 'fulltext');
        ManticoreDb::table($this->table)->insert($this->documents());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function documents(): array
    {
        $id = 0;

        return [
            ['id' => ++$id, 'title' => 'find me', 'content' => 'fast and quick'],
            ['id' => ++$id, 'title' => 'find me fast', 'content' => 'quick'],
            ['id' => ++$id, 'title' => 'find me slow', 'content' => 'quick'],
            ['id' => ++$id, 'title' => 'The quick brown fox jumps over the lazy dog', 'content' => 'The five boxing wizards jump quickly'],
            ['id' => ++$id, 'title' => 'find me quick and fast', 'content' => 'quick'],
            ['id' => ++$id, 'title' => 'find me fast now', 'content' => 'quick'],
            ['id' => ++$id, 'title' => 'The quick brown fox takes a step back and jumps over the lazy dog', 'content' => 'The five boxing wizards jump quickly'],
            ['id' => ++$id, 'title' => 'The brown and beautiful fox takes a step back and jumps over the lazy dog', 'content' => 'The five boxing wizards jump quickly'],
            ['id' => ++$id, 'title' => '<h1>Samsung Galaxy S10</h1><div>Is a smartphone introduced by Samsung in 2019</div>', 'content' => ''],
            ['id' => ++$id, 'title' => '<h1>Samsung</h1><div>Galaxy,Note,A,J</div>', 'content' => ''],
            ['id' => ++$id, 'title' => 'Hello world', 'content' => ''],
            ['id' => ++$id, 'title' => '<h1>Hello</h1> <h1>world</h1>', 'content' => ''],
            ['id' => ++$id, 'title' => '<h1>Hello world</h1>', 'content' => ''],
            ['id' => ++$id, 'title' => 'The brown fox takes a step back. Then it jumps over the lazy dog', 'content' => ''],
        ];
    }

    /**
     * @param array $rows
     *
     * @return int[]
     */
    private function ids(array $rows): array
    {
        return array_column($rows, 'id');
    }

    public function testPlainMatch(): void
    {
        $rows = ManticoreDb::table($this->table)->match('find me fast')->get();

        $this->assertSame([1, 2, 6, 5], $this->ids($rows));
    }

    public function testNegationOperator(): void
    {
        $rows = ManticoreDb::table($this->table)->match('find me !fast')->get();

        $this->assertSame([3], $this->ids($rows));
    }

    public function testMaybeOperator(): void
    {
        $rows = ManticoreDb::table($this->table)->match('find me MAYBE slow')->get();

        $this->assertSame([3, 1, 2, 5, 6], $this->ids($rows));
    }

    public function testFieldLimitOperator(): void
    {
        $rows = ManticoreDb::table($this->table)->match('@title find me fast')->get();

        $this->assertSame([2, 6, 5], $this->ids($rows));
    }

    public function testFieldLimitWithPositionLimit(): void
    {
        $rows = ManticoreDb::table($this->table)->match('@title[5] lazy dog')->get();

        $this->assertEmpty($rows);
    }

    public function testRelaxedFieldSet(): void
    {
        $rows = ManticoreDb::table($this->table)->match('@@relaxed @(title,keywords) lazy dog')->get();

        $this->assertSame([4, 7, 8, 14], $this->ids($rows));
    }

    public function testProximityOperator(): void
    {
        $rows = ManticoreDb::table($this->table)->match('"fox bird lazy dog"/3')->get();

        $this->assertSame([4, 7, 8, 14], $this->ids($rows));
    }

    public function testPhraseOperator(): void
    {
        $rows = ManticoreDb::table($this->table)->match('"find me fast"')->get();

        $this->assertSame([2, 6], $this->ids($rows));
    }

    public function testBeforeOperator(): void
    {
        $rows = ManticoreDb::table($this->table)->match('find << me << fast')->get();

        $this->assertSame([2, 6, 5], $this->ids($rows));
    }

    public function testNotNearOperator(): void
    {
        $rows = ManticoreDb::table($this->table)->match('"brown fox" NOTNEAR/5 jumps')->get();

        $this->assertSame([7, 14], $this->ids($rows));
    }

    public function testMatchCombinedWithCondition(): void
    {
        $rows = ManticoreDb::table($this->table)->match('find me')->where('id', '>', 3)->get();

        $this->assertSame([5, 6], $this->ids($rows));
    }

    public function testMatchWithQuoteInQuery(): void
    {
        $result = ManticoreDb::table($this->table)->match("it's")->search();

        $this->assertTrue($result->success(), (string)$result->error());
    }

    public function testScoreColumnIsAddedForDefaultSelect(): void
    {
        $rows = ManticoreDb::table($this->table)->match('find me')->get();
        $row = reset($rows);

        $this->assertArrayHasKey('_score', $row);
        $this->assertIsInt($row['_score']);
        $this->assertGreaterThan(0, $row['_score']);
    }

    public function testRankerOptionChangesScore(): void
    {
        $default = ManticoreDb::table($this->table)->match('find me')->get();
        $none = ManticoreDb::table($this->table)->match('find me')->ranker('none')->get();

        $this->assertSame(1, reset($none)['_score'], 'ranker=none gives every document weight 1');
        $this->assertGreaterThan(1, reset($default)['_score']);
    }

    public function testMaxMatchesLimitsMeta(): void
    {
        $result = ManticoreDb::table($this->table)->match('find me')->maxMatches(2)->limit(2)->search();

        $this->assertTrue($result->success(), (string)$result->error());
        $this->assertCount(2, $result->result());
    }

    public function testFieldWeightsAffectRanking(): void
    {
        $result = ManticoreDb::table($this->table)
            ->match('quick')
            ->fieldWeights(['title' => 100, 'content' => 1])
            ->search();

        $this->assertTrue($result->success(), (string)$result->error());
        $this->assertNotEmpty($result->result());
    }

    public function testMaxQueryTimeIsAccepted(): void
    {
        $result = ManticoreDb::table($this->table)->match('find me')->maxQueryTime(500)->search();

        $this->assertTrue($result->success(), (string)$result->error());
    }

    public function testExpandKeywordsIsAccepted(): void
    {
        $result = ManticoreDb::table($this->table)->match('find')->expandKeywords(true)->search();

        $this->assertTrue($result->success(), (string)$result->error());
    }

    public function testHighlightAddsMarkedUpColumn(): void
    {
        $rows = ManticoreDb::table($this->table)->match('lazy dog')->highlight()->get();
        $row = reset($rows);

        $this->assertArrayHasKey('_highlight', $row);
        $this->assertStringContainsString('<b>lazy dog</b>', implode(' ', (array)$row['_highlight']));
    }

    public function testHighlightWithCustomTags(): void
    {
        // the SQL interface names these before_match/after_match (pre_tags/post_tags is the HTTP API)
        $rows = ManticoreDb::table($this->table)
            ->match('lazy dog')
            ->highlight(['before_match' => '<em>', 'after_match' => '</em>'])
            ->get();
        $row = reset($rows);

        $this->assertStringContainsString('<em>lazy dog</em>', implode(' ', (array)$row['_highlight']));
    }

    public function testExplainReturnsTransformationTree(): void
    {
        $result = ManticoreDb::table($this->table)->match('brown fox')->explain();

        $this->assertTrue($result->success(), (string)$result->error());
        $this->assertSame('EXPLAIN', $result->command());

        $tree = $result->variable('transformed_tree');
        $this->assertNotNull($tree, 'the tree must be exposed as a variable');
        $this->assertStringContainsString('KEYWORD(brown', $tree);
        $this->assertStringContainsString('KEYWORD(fox', $tree);
    }

    public function testExplainRowsCarryVariableAndValue(): void
    {
        $result = ManticoreDb::table($this->table)->match('lazy')->explain();

        $this->assertSame(['Variable_name', 'Value'], $result->columns());
        $this->assertSame('transformed_tree', $result->first()['Variable_name']);
    }

    public function testExplainInDotFormat(): void
    {
        $result = ManticoreDb::table($this->table)->match('brown | fox')->explain('dot');

        $this->assertTrue($result->success(), (string)$result->error());
        $this->assertStringContainsString('digraph', (string)$result->variable('transformed_tree'));
    }

    public function testExplainOfMissingTableReportsError(): void
    {
        $result = ManticoreDb::table($this->tableName('missing'))->match('x')->explain();

        $this->assertFalse($result->success());
        $this->assertNotNull($result->error());
    }

    public function testLimitAndOffsetPaginateResults(): void
    {
        $all = $this->ids(ManticoreDb::table($this->table)->match('find me')->get());

        $page = $this->ids(ManticoreDb::table($this->table)->match('find me')->limit(2)->offset(1)->get());

        $this->assertSame(array_slice($all, 1, 2), $page);
    }
}
