<?php

namespace avadim\Manticore\Tests\Unit;

use avadim\Manticore\QueryBuilder\KnnOptions;
use avadim\Manticore\QueryBuilder\Query;
use avadim\Manticore\Tests\Support\FakeClient;
use avadim\Manticore\Tests\Support\UnitTestCase;

final class ExtendedKnnTest extends UnitTestCase
{
    public function testExtendedKnnSerializesOptionsAndSetsLimit(): void
    {
        $query = $this->query()->knn('vec', [1, 2.5], 7, [
            'ef' => 80, 'oversampling' => 2, 'rescore' => true, 'early_termination' => false, 'prefilter' => 1,
        ])->select('id');
        $this->assertSqlSame('SELECT id FROM products WHERE knn(vec, 7, (1,2.5), {ef=80, oversampling=2.0, rescore=1, early_termination=0, prefilter=1}) LIMIT 7', $query->toSql());
    }

    public function testBothKnnEntryPointsReplaceThePreviousCondition(): void
    {
        $query = $this->query()->knn('a', [1], 5)->whereKnn('b', 3, [2])->knn('c', [3], 2);
        $this->assertSqlSame('SELECT * FROM products WHERE knn(c, 2, (3)) LIMIT 2', $query->toSql());
    }

    public function testDistanceExpressionsSurviveSelectAndHiddenOrderAliasIsRemoved(): void
    {
        $query = $this->query()->knn('vec', [1], 3)->withKnnDistance('distance')->orderByKnnDistance()->select('id');
        $this->assertSqlSame('SELECT id, KNN_DIST() as __mqb_knn_order_distance, KNN_DIST() as distance FROM products WHERE knn(vec, 3, (1)) ORDER BY __mqb_knn_order_distance ASC LIMIT 3', $query->toSql());
    }

    public function testUnsupportedDescendingOrderIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->query()->orderByKnnDistance('desc');
    }

    public function testHiddenOrderAliasCannotDeleteARealTableColumn(): void
    {
        $query = $this->query('products', ['__mqb_knn_order_distance' => 'integer'])
            ->knn('vec', [1], 1)->orderByKnnDistance();
        $result = $query->search();
        $this->assertFalse($result->success());
        $this->assertStringContainsString('conflicts with a table column', $result->error());
    }

    public function testVisibleDistanceCannotOverwriteSelectedAlias(): void
    {
        $result = $this->query()->knn('vec', [1], 1)->withKnnDistance('distance')
            ->select('id', 'price AS distance')->search();
        $this->assertFalse($result->success());
        $this->assertStringContainsString('conflicts with the SELECT projection', $result->error());
    }

    public function testCachedCosineMetricRejectsZeroVector(): void
    {
        $client = new class(['vec' => 'float_vector']) extends FakeClient {
            public function query(string $query, ?array $params = []): array
            {
                $result = parent::query($query, $params);
                if (stripos($query, 'DESCRIBE') === 0) {
                    $result['data'][0]['Properties'] = "knn_dims='2' hnsw_similarity='cosine'";
                }
                return $result;
            }
        };
        $query = $this->queryFor($client);
        $query->columnTypes();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('nonzero vector');
        $query->knn('vec', [0, 0]);
    }

    public function invalidInputs(): array
    {
        return [
            ['vec', [], 1, []], ['vec', ['1'], 1, []], ['vec', [INF], 1, []], ['vec', [NAN], 1, []],
            ['vec', [1 => 2], 1, []], ['bad.name', [1], 1, []], ['vec', [1], 0, []],
            ['vec', [1], 1, ['ef' => 0]], ['vec', [1], 1, ['ef' => '2']],
            ['vec', [1], 1, ['oversampling' => INF]], ['vec', [1], 1, ['oversampling' => .5]],
            ['vec', [1], 1, ['oversampling' => new \stdClass()]],
            ['vec', [1], 1, ['prefilter' => 2]], ['vec', [1], 1, ['rescore' => '1']],
            ['vec', [1], 1, ['unknown' => 1]],
        ];
    }

    /** @dataProvider invalidInputs */
    public function testInvalidKnnIsRejectedBeforeExecution(string $column, array $vector, int $k, array $options): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->query()->knn($column, $vector, $k, $options);
    }

    public function testRankerExpressionEscapesAndDoesNotChangeLegacyApi(): void
    {
        $formula = "sum(lcs)*1000 + if(user_weight='x',1,0)";
        $sql = $this->query()->rankerExpression($formula)->toSql();
        $this->assertStringContainsString('OPTION ranker=expr(' . Query::quoteParam($formula) . ')', $sql);
        $this->assertStringContainsString("ranker=export('sum(lcs)')", $this->query()->rankerExpression('sum(lcs)', 'export')->toSql());
        $this->assertSame(1, (new \ReflectionMethod(Query::class, 'ranker'))->getNumberOfParameters());
        $this->assertSame(3, (new \ReflectionMethod(Query::class, 'whereKnn'))->getNumberOfParameters());
    }

    public function testEmptyRankerExpressionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->query()->rankerExpression(' ');
    }
}
