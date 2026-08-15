<?php

namespace avadim\Manticore\Tests\Integration;

use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use avadim\Manticore\QueryBuilder\QueryErrorException;
use avadim\Manticore\Tests\Support\IntegrationTestCase;

/**
 * CALL SUGGEST, CALL QSUGGEST, CALL KEYWORDS and CALL SNIPPETS against a live server.
 */
final class CallCommandsTest extends IntegrationTestCase
{
    /** @var string */
    private string $table;

    /** @var array ids of the queries stored in the percolate table */
    private array $storedQueryIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        // SUGGEST compares infixes, so a table without them has nothing to answer with
        $this->table = $this->createTable(
            ['title' => 'text', 'content' => 'text'],
            'call',
            ['min_infix_len' => 2]
        );
        ManticoreDb::table($this->table)->insert([
            ['id' => 1, 'title' => 'manticore search', 'content' => 'the quick brown fox jumps over the lazy dog'],
            ['id' => 2, 'title' => 'running shoes', 'content' => 'a pair of running shoes'],
        ]);
    }

    public function testSuggestFindsTheWordOfATypo(): void
    {
        $rows = ManticoreDb::table($this->table)->callSuggest('mantikore');

        $this->assertNotEmpty($rows);
        $this->assertSame('manticore', $rows[0]['suggest']);
    }

    public function testSuggestReadsItsNumbersAsNumbers(): void
    {
        $rows = ManticoreDb::table($this->table)->callSuggest('mantikore');

        $this->assertIsString($rows[0]['suggest']);
        $this->assertIsInt($rows[0]['distance']);
        $this->assertIsInt($rows[0]['docs']);
        $this->assertSame(1, $rows[0]['distance'], 'one letter apart');
    }

    public function testSuggestTakesItsLimit(): void
    {
        $rows = ManticoreDb::table($this->table)->callSuggest('runing', ['limit' => 1]);

        $this->assertLessThanOrEqual(1, count($rows));
    }

    public function testSuggestOfATableWithoutInfixesIsRefused(): void
    {
        $plain = $this->createTable(['title' => 'text'], 'no_infix');
        ManticoreDb::table($plain)->insert(['id' => 1, 'title' => 'manticore search']);

        $this->expectException(QueryErrorException::class);
        $this->expectExceptionMessage('infix');

        ManticoreDb::table($plain)->callSuggest('mantikore');
    }

    public function testPrefixesAreNotEnoughForSuggest(): void
    {
        $prefixed = $this->createTable(['title' => 'text'], 'prefix', ['min_prefix_len' => 2]);
        ManticoreDb::table($prefixed)->insert(['id' => 1, 'title' => 'manticore search']);

        $this->expectException(QueryErrorException::class);
        $this->expectExceptionMessage('infix');

        ManticoreDb::table($prefixed)->callSuggest('mantikore');
    }

    public function testAWordThatIsSpelledRightComesBackWithoutADistance(): void
    {
        $rows = ManticoreDb::table($this->table)->callSuggest('manticore');

        $this->assertSame('manticore', $rows[0]['suggest']);
        $this->assertSame(0, $rows[0]['distance']);
    }

    public function testAWordWithNothingCloseToItGivesAnEmptySet(): void
    {
        $this->assertSame([], ManticoreDb::table($this->table)->callSuggest('zzzqqq'));
    }

    public function testSuggestAnswersForTheFirstWordOfAPhraseAndQsuggestForTheLast(): void
    {
        // neither statement corrects a whole phrase, which is why a phrase takes a call per word
        $first = ManticoreDb::table($this->table)->callSuggest('runing manticore');
        $last = ManticoreDb::table($this->table)->callQsuggest('runing manticore');

        $this->assertSame('running', $first[0]['suggest']);
        $this->assertSame('manticore', $last[0]['suggest']);
    }

    public function testQsuggestCorrectsTheLastWordOfAPhrase(): void
    {
        $rows = ManticoreDb::table($this->table)->callQsuggest('manticore serch');

        $this->assertNotEmpty($rows);
        $this->assertSame('search', $rows[0]['suggest'], 'only the last word is corrected');
        $this->assertIsInt($rows[0]['distance']);
    }

    public function testQsuggestTakesItsLimit(): void
    {
        $rows = ManticoreDb::table($this->table)->callQsuggest('a pair of runing', ['limit' => 1]);

        $this->assertLessThanOrEqual(1, count($rows));
    }

    public function testKeywordsSplitsTheTextTheWayTheTableDoes(): void
    {
        $rows = ManticoreDb::table($this->table)->callKeywords('Running Shoes');

        $this->assertCount(2, $rows);
        $this->assertSame(['running', 'shoes'], array_column($rows, 'normalized'));
        $this->assertSame([1, 2], array_column($rows, 'qpos'), 'the positions come back as numbers');
    }

    public function testKeywordsCountsDocumentsWithStats(): void
    {
        $rows = ManticoreDb::table($this->table)->callKeywords('running', ['stats' => true]);

        $this->assertArrayHasKey('docs', $rows[0]);
        $this->assertIsInt($rows[0]['docs']);
        $this->assertSame(1, $rows[0]['docs']);
    }

    public function testSnippetsMarksUpWhatMatches(): void
    {
        $rows = ManticoreDb::table($this->table)->callSnippets('the quick brown fox', 'fox');

        $this->assertCount(1, $rows);
        $this->assertStringContainsString('<b>fox</b>', $rows[0]['snippet']);
    }

    public function testSnippetsTakesItsMarkersAndASetOfDocuments(): void
    {
        $rows = ManticoreDb::table($this->table)->callSnippets(
            ['the quick brown fox', 'a lazy dog'],
            'fox|dog',
            ['before_match' => '<em>', 'after_match' => '</em>']
        );

        $this->assertCount(2, $rows);
        $this->assertStringContainsString('<em>fox</em>', $rows[0]['snippet']);
        $this->assertStringContainsString('<em>dog</em>', $rows[1]['snippet']);
    }

    /**
     * A percolate table holding two stored queries: "fox" tagged "animals", and "dog"
     *
     * @return string
     */
    private function createPercolateTable(): string
    {
        $table = $this->createTable(['title' => 'text', 'gid' => 'int'], 'pq', ['type' => 'pq']);

        $connection = ManticoreDb::connection();
        $connection->statement("INSERT INTO $table (query, tags) VALUES ('fox', 'animals')");
        $connection->statement("INSERT INTO $table (query, tags) VALUES ('dog', 'pets')");

        $this->storedQueryIds = array_column(
            $connection->select("SELECT id, query FROM $table ORDER BY id ASC"),
            'id'
        );
        $this->assertCount(2, $this->storedQueryIds, 'the stored queries are in place');

        return $table;
    }

    public function testPqAnswersWithTheStoredQueriesADocumentMatches(): void
    {
        $table = $this->createPercolateTable();

        $rows = ManticoreDb::table($table)->callPq(['title' => 'the quick brown fox']);

        $this->assertCount(1, $rows);
        $this->assertSame($this->storedQueryIds[0], $rows[0]['id']);
    }

    public function testPqTakesASetOfDocumentsAndTellsWhichOneMatched(): void
    {
        $table = $this->createPercolateTable();

        $rows = ManticoreDb::table($table)->callPq(
            [['title' => 'the quick brown fox'], ['title' => 'a lazy dog']],
            ['docs' => true]
        );

        $this->assertCount(2, $rows);
        $this->assertSame('1', $rows[0]['documents'], 'the position of the document, as a list');
        $this->assertSame('2', $rows[1]['documents']);
    }

    public function testPqTakesPlainTextWithoutBeingToldAboutDocsJson(): void
    {
        $table = $this->createPercolateTable();

        $rows = ManticoreDb::table($table)->callPq('the quick brown fox');

        $this->assertCount(1, $rows);
        $this->assertSame($this->storedQueryIds[0], $rows[0]['id']);
    }

    public function testPqShowsTheStoredQueryWhenAskedTo(): void
    {
        $table = $this->createPercolateTable();

        $rows = ManticoreDb::table($table)->callPq(['title' => 'the quick brown fox'], ['query' => true]);

        $this->assertSame('fox', $rows[0]['query']);
        $this->assertSame('animals', $rows[0]['tags']);
    }

    public function testPqCarriesTextThatWouldBreakTheJsonOrTheSql(): void
    {
        $table = $this->createPercolateTable();

        $rows = ManticoreDb::table($table)->callPq(['title' => "лиса it's a fox"]);

        $this->assertCount(1, $rows, 'a quote and a non-latin alphabet survive both layers');
    }

    public function testTheAnswerOfACallIsInTheLastResultSet(): void
    {
        ManticoreDb::table($this->table)->callKeywords('running');

        $resultSet = ManticoreDb::connection()->lastResultSet();
        $this->assertNotNull($resultSet);
        $this->assertSame('CALL', $resultSet->command());
        $this->assertTrue($resultSet->success());
        $this->assertStringContainsString('CALL KEYWORDS', (string)$resultSet->sqlQuery());
    }

    public function testACallOfAMissingTableThrowsTheWayAReadDoes(): void
    {
        $this->expectException(QueryErrorException::class);

        ManticoreDb::table('qb_no_such_table_for_call')->callKeywords('running');
    }

    public function testAWordWithAQuoteIsPassedThroughSafely(): void
    {
        $rows = ManticoreDb::table($this->table)->callSnippets("it's a fox", 'fox');

        $this->assertStringContainsString('<b>fox</b>', $rows[0]['snippet']);
        $this->assertStringContainsString("it's", $rows[0]['snippet']);
    }
}
