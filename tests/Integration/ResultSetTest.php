<?php

namespace avadim\Manticore\Tests\Integration;

use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use avadim\Manticore\Tests\Support\IntegrationTestCase;

/**
 * The read-only response model: metadata, counters and the query description.
 */
final class ResultSetTest extends IntegrationTestCase
{
    /** @var string */
    private string $table;

    protected function setUp(): void
    {
        parent::setUp();
        $this->table = $this->createTable([
            'title' => 'text',
            'price' => 'float',
        ], 'resultset');
        $rows = [];
        for ($i = 1; $i <= 25; $i++) {
            $rows[] = ['id' => $i, 'title' => 'item number ' . $i, 'price' => (float)$i];
        }
        ManticoreDb::table($this->table)->insert($rows);
    }

    public function testCountReturnsNumberOfReturnedRows(): void
    {
        $result = ManticoreDb::table($this->table)->limit(5)->search();

        $this->assertSame(5, $result->count());
        $this->assertCount(5, $result->result());
    }

    public function testTotalReturnsNumberOfMatchingRows(): void
    {
        // a full scan without MATCH() reports total_relation=gte and does not count the rest,
        // so a full-text query is used here
        $result = ManticoreDb::table($this->table)->match('item')->limit(5)->search();

        $this->assertSame(5, $result->count());
        $this->assertSame(25, $result->total());
    }

    public function testTotalRespectsCondition(): void
    {
        $result = ManticoreDb::table($this->table)->match('item')->where('price', '>', 20)->search();

        $this->assertSame(5, $result->total());
    }

    public function testMetaContainsServerCounters(): void
    {
        $result = ManticoreDb::table($this->table)->match('item')->search();
        $meta = $result->meta();

        $this->assertArrayHasKey('total', $meta);
        $this->assertArrayHasKey('total_found', $meta);
        $this->assertArrayHasKey('time', $meta);
        $this->assertIsFloat($meta['time']);
        $this->assertIsInt($meta['total_found']);
    }

    public function testColumnsListSelectedFields(): void
    {
        $result = ManticoreDb::table($this->table)->search(['id', 'title']);

        $this->assertSame(['id', 'title'], $result->columns());
    }

    public function testColumnsIncludeGeneratedOnesForDefaultSelect(): void
    {
        $result = ManticoreDb::table($this->table)->search();

        $this->assertSame(['_id', '_score', 'id', 'title', 'price'], $result->columns());
    }

    public function testFirstReturnsFirstRow(): void
    {
        $result = ManticoreDb::table($this->table)->orderBy('price')->search();

        $this->assertSame('item number 1', $result->first()['title']);
    }

    public function testFirstIsNullOnEmptyResult(): void
    {
        $result = ManticoreDb::table($this->table)->where('price', '>', 1000)->search();

        $this->assertNull($result->first());
        $this->assertSame([], $result->result());
    }

    public function testCommandAndSqlQueryDescribeTheRequest(): void
    {
        $result = ManticoreDb::table($this->table)->where('price', '>', 20)->search(['id']);

        $this->assertSame('SELECT', $result->command());
        $this->assertSame('SELECT id FROM ' . $this->table . ' WHERE (price>20)', $result->sqlQuery());
    }

    public function testExecTimeIsMeasured(): void
    {
        $result = ManticoreDb::table($this->table)->search();

        $this->assertIsFloat($result->execTime());
        $this->assertGreaterThan(0, $result->execTime());
    }

    public function testStatusOfSuccessfulSelect(): void
    {
        $result = ManticoreDb::table($this->table)->search();

        $this->assertSame('done', $result->status());
        $this->assertTrue($result->success());
        $this->assertNull($result->error());
    }

    public function testVariablesAreExposedForSettingsQueries(): void
    {
        $table = $this->createTable(['title' => 'text'], 'settings', ['morphology' => 'stem_en']);

        $result = ManticoreDb::connection()->query()->table($table)->settings($table);

        $this->assertNotEmpty($result->variables());
        $this->assertSame('stem_en', $result->variable('morphology'));
    }

    public function testResultOfInsertIsId(): void
    {
        $result = ManticoreDb::table($this->table)->insertResultSet(['title' => 'one more']);

        $this->assertSame('inserted', $result->status());
        $this->assertIsInt($result->result());
    }

    public function testResultOfDeleteIsTheNumberOfDeletedRows(): void
    {
        $result = ManticoreDb::table($this->table)->where('price', '<=', 3)->deleteResultSet();

        $this->assertSame('deleted', $result->status());
        $this->assertSame(3, $result->result());
    }

    public function testResultOfDropIsBoolean(): void
    {
        $table = $this->createTable(['title' => 'text'], 'dropme');

        $result = ManticoreDb::table($table)->drop();

        $this->assertTrue($result->result());
        $this->assertSame([], $result->columns());
    }
}
