<?php

namespace avadim\Manticore\Tests\Integration;

use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use avadim\Manticore\QueryBuilder\Schema\SchemaTable;
use avadim\Manticore\Tests\Support\IntegrationTestCase;

/**
 * KNN search over a float_vector column.
 *
 * Needs the KNN library of the Manticore Columnar Library loaded by the server; without it the
 * table cannot even be created, so the whole case is skipped rather than failed.
 */
final class VectorSearchTest extends IntegrationTestCase
{
    /** @var string */
    private string $table;

    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->knnAvailable()) {
            $this->markTestSkipped('The server has no KNN library loaded');
        }

        $this->table = $this->createTable(function (SchemaTable $table) {
            $table->text('title');
            $table->string('brand');
            $table->floatVector('vec', 3);
        }, 'vectors');

        ManticoreDb::table($this->table)->insert([
            ['id' => 1, 'title' => 'red apple', 'brand' => 'acme', 'vec' => [1.0, 2.0, 3.0]],
            ['id' => 2, 'title' => 'green pear', 'brand' => 'acme', 'vec' => [1.1, 2.1, 3.1]],
            ['id' => 3, 'title' => 'blue car', 'brand' => 'other', 'vec' => [9.0, 8.0, 7.0]],
        ]);
    }

    /**
     * @return bool
     */
    private function knnAvailable(): bool
    {
        $probe = 'knn_probe_' . str_replace('.', '', uniqid('', true));
        $result = ManticoreDb::connection()->sql(
            'CREATE TABLE ' . $probe . " (title text, vec float_vector knn_type='hnsw' knn_dims='2' hnsw_similarity='l2')"
        )->exec();

        if ($result->success()) {
            ManticoreDb::dropIfExists($probe);

            return true;
        }

        return false;
    }

    public function testTheSchemaDeclaresTheVectorColumn(): void
    {
        $columns = ManticoreDb::tableDescribe($this->table);

        $this->assertArrayHasKey('vec', $columns);
        $this->assertSame('float_vector', $columns['vec']['Type']);
    }

    public function testVectorsAreWrittenAndReadBackAsArrays(): void
    {
        $row = ManticoreDb::table($this->table)->find(1);

        $this->assertSame([1.0, 2.0, 3.0], $row['vec']);
    }

    public function testNeighboursComeBackClosestFirst(): void
    {
        $rows = ManticoreDb::table($this->table)->whereKnn('vec', 3, [1.0, 2.0, 3.0])->get();

        $this->assertNotEmpty($rows);
        $ids = array_column($rows, 'id');
        $this->assertSame(1, reset($ids), 'the exact match must come first');
    }

    /**
     * The server adds the distance to a "SELECT *" itself; the row carries it under a name of
     * the same shape as the "_score" of a full-text query
     */
    public function testTheDistanceIsPartOfTheRow(): void
    {
        $row = ManticoreDb::table($this->table)->whereKnn('vec', 3, [1.0, 2.0, 3.0])->first();

        $this->assertArrayHasKey('_knn_dist', $row);
        $this->assertIsFloat($row['_knn_dist']);
        $this->assertArrayNotHasKey('@knn_dist', $row);
    }

    public function testKnnCombinesWithTheOtherConditions(): void
    {
        $table = ManticoreDb::table($this->table);

        $this->assertSame(2, $table->clone()->whereKnn('vec', 3, [1.0, 2.0, 3.0])->where('brand', 'acme')->count());
        $this->assertSame(1, $table->clone()->whereKnn('vec', 3, [1.0, 2.0, 3.0])->match('apple')->count());
        $this->assertCount(1, $table->clone()->whereKnn('vec', 3, [1.0, 2.0, 3.0])->limit(1)->get());
    }

    /**
     * knn() has to be the first condition of the WHERE, the server rejects it anywhere else
     */
    public function testTheConditionOrderIsTheOneTheServerTakes(): void
    {
        $sql = ManticoreDb::table($this->table)
            ->where('brand', 'acme')
            ->match('apple')
            ->whereKnn('vec', 3, [1.0, 2.0, 3.0])
            ->toSql();

        $this->assertStringContainsString("WHERE knn(vec, 3, (1,2,3)) AND MATCH('apple') AND (brand='acme')", $sql);
    }

    public function testASecondCallReplacesTheFirst(): void
    {
        $query = ManticoreDb::table($this->table)
            ->whereKnn('vec', 3, [1.0, 2.0, 3.0])
            ->whereKnn('vec', 2, [9.0, 8.0, 7.0]);

        $this->assertStringContainsString('knn(vec, 2, (9,8,7))', $query->toSql());
        $this->assertStringNotContainsString('(1,2,3)', $query->toSql());
    }
}
