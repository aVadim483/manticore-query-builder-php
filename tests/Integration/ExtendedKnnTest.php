<?php

namespace avadim\Manticore\Tests\Integration;

use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use avadim\Manticore\QueryBuilder\Schema\SchemaTable;
use avadim\Manticore\Tests\Support\IntegrationTestCase;

final class ExtendedKnnTest extends IntegrationTestCase
{
    public function testOptionsDistanceProjectionAndFiltersExecute(): void
    {
        $table = $this->createTable(function (SchemaTable $schema) {
            $schema->text('title');
            $schema->integer('stock');
            $schema->floatVector('vec', 2);
        }, 'extended_knn');
        $this->assertTrue(ManticoreDb::table($table)->insert([
            ['id' => 1, 'title' => 'phone', 'stock' => 1, 'vec' => [1, 0]],
            ['id' => 2, 'title' => 'tablet', 'stock' => 1, 'vec' => [0, 1]],
            ['id' => 3, 'title' => 'other', 'stock' => 0, 'vec' => [.9, .1]],
        ]));
        $rows = ManticoreDb::table($table)->where('stock', 1)
            ->knn('vec', [1, 0], 2, ['ef' => 30, 'oversampling' => 2, 'rescore' => true, 'early_termination' => false, 'prefilter' => true])
            ->withKnnDistance('distance')->orderByKnnDistance()->select('id', 'title')->get();
        $this->assertSame([1, 2], array_column($rows, 'id'));
        $this->assertEqualsWithDelta(0, $rows[1]['distance'], .0001);
        $this->assertArrayNotHasKey('__mqb_knn_order_distance', $rows[1]);
    }

    public function testExpressionRankerExecutes(): void
    {
        $table = $this->createTable(['title' => 'text'], 'ranker_expression');
        $this->assertTrue(ManticoreDb::table($table)->insert(['title' => 'apple phone'], 1));
        $rows = ManticoreDb::table($table)->match('phone')->rankerExpression('sum(lcs)*1000+bm25')->get();
        $this->assertCount(1, $rows);
        $this->assertGreaterThan(0, $rows[1]['_score']);
    }
}
