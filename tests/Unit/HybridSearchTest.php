<?php

namespace avadim\Manticore\Tests\Unit;

use avadim\Manticore\QueryBuilder\HybridResult;
use avadim\Manticore\QueryBuilder\Query;
use avadim\Manticore\QueryBuilder\QueryErrorException;
use avadim\Manticore\QueryBuilder\ResultSet;
use avadim\Manticore\Tests\Support\FakeClient;
use avadim\Manticore\Tests\Support\TestLogger;
use avadim\Manticore\Tests\Support\UnitTestCase;

final class HybridSearchTest extends UnitTestCase
{
    public function testFusionFormulaBranchRanksAndTextDocumentPrecedence(): void
    {
        $text = ResultSet::of([['id' => 1, 'title' => 'text one', '_score' => 90], ['id' => 2, 'title' => 'text two', '_score' => 70]]);
        $vector = ResultSet::of([['id' => 2, 'title' => 'vector two', '_knn_dist' => .1], ['id' => 3, 'title' => 'vector three', '_knn_dist' => .2]]);
        $result = HybridResult::fuse($text, $vector, ['rank_constant' => 0]);
        $this->assertSame([2, 1, 3], array_column($result->rows(), 'id'));
        $this->assertEqualsWithDelta(1.5, $result->firstHit()->score(), 1e-12);
        $this->assertSame(2, $result->firstHit()->textRank());
        $this->assertSame(1, $result->firstHit()->vectorRank());
        $this->assertSame(70.0, $result->firstHit()->textScore());
        $this->assertSame(.1, $result->firstHit()->distance());
        $this->assertSame('text two', $result->first()['title']);
        $this->assertSame(3, count($result));
        $this->assertSame(3, $result->windowTotal());
        $this->assertNull($result->totalFound());
        $this->assertCount(3, iterator_to_array($result));
    }

    public function testFusionRetainsExactBigIdsAndUsesUnsignedTieBreak(): void
    {
        $result = HybridResult::fuse(ResultSet::of([
            ['id' => '18446744073709551614'], ['id' => '18446744073709551613'],
        ]), ResultSet::of([
            ['id' => '18446744073709551613'], ['id' => '18446744073709551614'],
        ]));
        $this->assertSame(['18446744073709551613', '18446744073709551614'], array_column($result->rows(), 'id'));
        $this->assertSame(2, $result->windowTotal());
    }

    public function testSignedTransportIsReconstructedWithoutNumericCoercion(): void
    {
        $result = HybridResult::fuse(ResultSet::of([
            ['id' => PHP_INT_MAX, '__mqb_hybrid_id' => '-2'],
            ['id' => PHP_INT_MAX, '__mqb_hybrid_id' => '-3'],
        ]), null);
        $this->assertSame(['18446744073709551614', '18446744073709551613'], array_column($result->rows(), 'id'));
        $this->assertArrayNotHasKey('__mqb_hybrid_id', $result->first());
    }

    public function testDuplicateIdsInOneBranchAreRejected(): void
    {
        $this->expectException(\LogicException::class);
        HybridResult::fuse(ResultSet::of([['id' => '01'], ['id' => 1]]), null);
    }

    public function testBranchWindowOverflowIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        HybridResult::fuse(ResultSet::of([['id' => 1], ['id' => 2]]), null, ['text_candidates' => 1]);
    }

    public function invalidOptions(): array
    {
        return [
            [['text_weight' => -1]], [['vector_weight' => INF]], [['text_weight' => '2']],
            [['text_weight' => 0, 'vector_weight' => 0]], [['rank_constant' => -1]],
            [['rank_constant' => 1.5]], [['text_candidates' => 0]], [['vector_candidates' => 10001]],
            [['text_candidates' => '5']], [['escape' => 1]], [['knn_options' => 'bad']],
            [['knn_options' => ['unknown' => 1]]], [['unknown' => true]],
        ];
    }

    /** @dataProvider invalidOptions */
    public function testInvalidOptionsFailBeforeAnySql(array $options): void
    {
        $client = new HybridFixtureClient();
        try {
            $this->queryFor($client)->hybridSearch('phone', 'vec', [1, 0], $options);
            $this->fail('Expected invalid options');
        }
        catch (\InvalidArgumentException $e) {
            $this->assertSame([], $client->queries);
        }
    }

    public function testCommonFiltersAndBindingsReachBothIndependentBranches(): void
    {
        $client = new HybridFixtureClient();
        $client->textRows = [['id' => 1, 'title' => 'phone', '__mqb_hybrid_text_score' => 8]];
        $client->vectorRows = [['id' => 2, 'title' => 'semantic', '__mqb_hybrid_distance' => .1]];
        $query = $this->queryFor($client)->where('stock', Query::param('stock'))->bind([':stock' => 1]);
        $before = $query->toSql();
        $result = $query->hybridSearch('phone | tablet', 'vec', [1, 0], ['text_candidates' => 3, 'vector_candidates' => 4]);
        $this->assertSame($before, $query->toSql());
        $this->assertSame([1, 2], array_column($result->rows(), 'id'));
        $this->assertSame(2, count($client->branches));
        $this->assertStringContainsString('MATCH(', $client->branches[0]['query']);
        $this->assertStringNotContainsString('knn(', $client->branches[0]['query']);
        $this->assertStringContainsString('knn(', $client->branches[1]['query']);
        $this->assertStringNotContainsString('MATCH(', $client->branches[1]['query']);
        foreach ($client->branches as $branch) {
            $this->assertStringContainsString('stock=:stock', $branch['query']);
            $this->assertSame([':stock' => 1], $branch['params']);
        }
        $this->assertSame(12, $result->textMeta()->totalFound());
        $this->assertSame(34, $result->vectorMeta()->totalFound());
        $this->assertNotNull($query->lastResultSet());
        $this->assertStringContainsString('knn(', $query->lastResultSet()->sqlQuery());
        $commands = count($client->queries);
        $page = $result->paginateWindow(1, 2);
        $this->assertSame(2, $page['data'][0]['id']);
        $this->assertSame(2, $page['window_total']);
        $this->assertNull($page['total_found']);
        $this->assertSame($commands, count($client->queries));
    }

    public function testCapturePrecedesSchemaCastingAndLoggerQueries(): void
    {
        $client = new HybridFixtureClient();
        $client->textRows = [['id' => 1]];
        $client->vectorRows = [['id' => 2]];
        $logger = new class($client) extends TestLogger {
            private FakeClient $client;
            public function __construct(FakeClient $client) { $this->client = $client; }
            public function log($level, $message, array $context = []): void
            {
                $this->client->query('LOGGER PROBE');
            }
        };
        $query = new Query(['client' => $client], 'products', $logger);
        $result = $query->hybridSearch('phone', 'vec', [1, 0]);
        $this->assertSame(12, $result->textMeta()->totalFound());
        $this->assertSame(34, $result->vectorMeta()->totalFound());
        foreach ($client->queries as $i => $sql) {
            if (stripos($sql, 'SELECT ') === 0) {
                $this->assertSame('SHOW META', $client->queries[$i + 1]);
            }
        }
    }

    public function testExactAliasPreventsCollapseBeforeFusion(): void
    {
        $client = new HybridFixtureClient();
        $client->textRows = [
            ['id' => PHP_INT_MAX, '__mqb_hybrid_id' => '-2'],
            ['id' => PHP_INT_MAX, '__mqb_hybrid_id' => '-3'],
        ];
        $result = $this->queryFor($client)->hybridSearch('phone', 'vec', [1, 0]);
        $this->assertCount(2, $result);
        $this->assertSame(['18446744073709551614', '18446744073709551613'], array_column($result->rows(), 'id'));
    }

    public function testEmptyTextSkipsTextBranchAndHelpersReturnDocuments(): void
    {
        $client = new HybridFixtureClient();
        $client->vectorRows = [['id' => 2, 'title' => 'semantic']];
        $query = $this->queryFor($client);
        $result = $query->hybridSearch('', 'vec', [1, 0]);
        $this->assertNull($result->textMeta());
        $this->assertCount(1, $client->branches);
        $this->assertSame(2, $query->hybridFirst('', 'vec', [1, 0])['id']);
        $this->assertSame([2], array_column($query->hybridGet('', 'vec', [1, 0]), 'id'));
    }

    public function testFailedVectorBranchPropagates(): void
    {
        $client = new HybridFixtureClient();
        $client->textRows = [['id' => 1]];
        $client->failVector = true;
        $query = $this->queryFor($client);
        try {
            $query->hybridSearch('phone', 'vec', [1, 0]);
            $this->fail('Expected the failed vector branch to propagate');
        }
        catch (QueryErrorException $e) {
            $this->assertStringContainsString('Vector branch failure', $e->getMessage());
            $this->assertFalse($query->lastResultSet()->success());
            $this->assertStringContainsString('knn(', $query->lastResultSet()->sqlQuery());
        }
    }

    public function testExistingResultWindowsAndSearchExpressionsAreRejected(): void
    {
        $queries = [$this->query()->limit(2), $this->query()->orderBy('id'),
            $this->query()->select('id'), $this->query()->match('phone'),
            $this->query()->whereKnn('vec', 2, [1]), $this->query()->facet('stock'),
            $this->query()->groupBy('stock'), $this->query()->where('stock > 0')];
        foreach ($queries as $query) {
            try {
                $query->hybridSearch('phone', 'vec', [1, 0]);
                $this->fail('Expected incompatible base query');
            }
            catch (\LogicException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testReservedSchemaAliasesAndTooSmallMaxMatchesFailBeforeBranches(): void
    {
        $client = new HybridFixtureClient(['__mqb_hybrid_id' => 'string']);
        try {
            $this->queryFor($client)->hybridSearch('phone', 'vec', [1, 0]);
            $this->fail('Expected reserved alias collision');
        }
        catch (\LogicException $e) { $this->assertSame([], $client->branches); }
        $client = new HybridFixtureClient();
        try {
            $this->queryFor($client)->option('max_matches', 2)->hybridSearch('phone', 'vec', [1, 0]);
            $this->fail('Expected too-small window');
        }
        catch (\InvalidArgumentException $e) { $this->assertSame([], $client->branches); }
    }
}

final class HybridFixtureClient extends FakeClient
{
    public array $textRows = [];
    public array $vectorRows = [];
    public array $branches = [];
    public bool $failVector = false;
    private int $currentTotal = 999;

    public function __construct(array $extraColumns = [])
    {
        parent::__construct($extraColumns + ['id' => 'bigint', 'title' => 'text', 'stock' => 'integer', 'vec' => 'float_vector']);
    }

    public function query(string $query, ?array $params = []): array
    {
        $this->currentTotal = 999;
        return parent::query($query, $params);
    }

    public function select(string $query, ?array $params = []): array
    {
        $this->queries[] = $query;
        if ($query === 'SHOW META') {
            return ['data' => [[['Variable_name' => 'total_found', 'Value' => (string)$this->currentTotal]]]];
        }
        $this->branches[] = ['query' => $query, 'params' => $params];
        $vector = stripos($query, 'WHERE knn(') !== false;
        if ($vector && $this->failVector) { throw new \RuntimeException('Vector branch failure'); }
        $this->currentTotal = $vector ? 34 : 12;
        return ['data' => [$vector ? $this->vectorRows : $this->textRows]];
    }
}
