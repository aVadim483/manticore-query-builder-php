<?php

namespace avadim\Manticore\Tests\Integration;

use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use avadim\Manticore\QueryBuilder\Query;
use avadim\Manticore\QueryBuilder\QueryErrorException;
use avadim\Manticore\QueryBuilder\Schema\SchemaTable;
use avadim\Manticore\Tests\Support\IntegrationTestCase;

final class HybridSearchTest extends IntegrationTestCase
{
    private function hybridTable(): string
    {
        $table = $this->createTable(function (SchemaTable $schema) {
            $schema->text('title');
            $schema->integer('stock');
            $schema->json('info');
            $schema->multi('tags');
            $schema->floatVector('vec', 2);
        }, 'hybrid');
        $this->assertTrue(ManticoreDb::table($table)->insert([
            ['id' => 1, 'title' => 'phone', 'stock' => 1, 'info' => ['kind' => 'mobile'], 'tags' => [1], 'vec' => [10, 10]],
            ['id' => 2, 'title' => 'semantic', 'stock' => 1, 'info' => ['kind' => 'mobile'], 'tags' => [1], 'vec' => [0, 1]],
            ['id' => 3, 'title' => 'phone', 'stock' => 1, 'info' => ['kind' => 'mobile'], 'tags' => [1], 'vec' => [0, 0]],
            ['id' => 4, 'title' => 'phone', 'stock' => 0, 'info' => ['kind' => 'mobile'], 'tags' => [1], 'vec' => [0, 0]],
        ]));
        return $table;
    }

    public function testIndependentBranchesContributeAndCommonFiltersApply(): void
    {
        $table = $this->hybridTable();
        $query = ManticoreDb::table($table)->where('stock', 1)->whereMvaAny('tags', [1])
            ->whereJsonContainsKey('info.kind');
        $before = $query->toSql();
        $result = $query->hybridSearch('phone', 'vec', [0, 0], [
            'text_candidates' => 2, 'vector_candidates' => 2, 'rank_constant' => 60,
        ]);
        $ids = array_column($result->rows(), 'id');
        $this->assertSame(3, $ids[0]);
        sort($ids);
        $this->assertSame([1, 2, 3], $ids);
        $this->assertSame($before, $query->toSql());
        $this->assertSame(2, $result->textMeta()->totalFound());
        $this->assertNotNull($result->vectorMeta());
        $this->assertSame(2, $result->firstHit()->textRank());
        $this->assertSame(1, $result->firstHit()->vectorRank());
        $this->assertNull($result->totalFound());
        $this->assertSame(3, $result->windowTotal());
        $this->assertCount(1, $result->paginateWindow(2, 2)['data']);
        foreach ($result->rows() as $row) {
            $this->assertArrayNotHasKey('__mqb_hybrid_id', $row);
            $this->assertArrayNotHasKey('__mqb_hybrid_text_score', $row);
            $this->assertArrayNotHasKey('__mqb_hybrid_distance', $row);
        }
    }

    public function testUnsigned64BitIdsRemainDistinctThroughRealPdoAndFusion(): void
    {
        $table = $this->hybridTable();
        $insert = ManticoreDb::connection()->sql('INSERT INTO ' . $table
            . " (id,title,stock,info,tags,vec) VALUES (18446744073709551614,'phone',2,'{}',(1),(1,0)),"
            . "(18446744073709551613,'phone',2,'{}',(1),(0,1))")->exec();
        $this->assertTrue($insert->success(), (string)$insert->error());
        $result = ManticoreDb::table($table)->where('stock', 2)
            ->hybridSearch('phone', 'vec', [0, 0], ['text_candidates' => 2, 'vector_candidates' => 2]);
        $ids = array_column($result->rows(), 'id');
        sort($ids, SORT_STRING);
        $this->assertSame(['18446744073709551613', '18446744073709551614'], $ids);
        $this->assertSame(2, $result->windowTotal());
    }

    public function testEmptyTextBranchDoesNotHideVectorResults(): void
    {
        $table = $this->hybridTable();
        $query = ManticoreDb::table($table)->where('stock', 1);
        $result = $query->hybridSearch('absentword', 'vec', [0, 0], ['vector_candidates' => 1]);
        $this->assertSame(3, $result->first()['id']);
        $this->assertSame(0, $result->textMeta()->totalFound());
        $this->assertNull($result->firstHit()->textRank());
        $this->assertSame(3, $query->hybridFirst('', 'vec', [0, 0])['id']);
        $this->assertNotEmpty($query->hybridGet('phone', 'vec', [0, 0]));
    }

    public function testFailedVectorBranchDoesNotReturnSuccessfulTextFallback(): void
    {
        $table = $this->hybridTable();
        $this->expectException(QueryErrorException::class);
        ManticoreDb::table($table)->hybridSearch('phone', 'missing_vec', [0, 0]);
    }
}
