<?php

namespace avadim\Manticore\Tests\Integration;

use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use avadim\Manticore\Tests\Support\IntegrationTestCase;

/**
 * INSERT / REPLACE / UPDATE / DELETE against a live server, plus the row accessors.
 */
final class CrudTest extends IntegrationTestCase
{
    /** @var string */
    private string $table;

    protected function setUp(): void
    {
        parent::setUp();
        $this->table = $this->createTable([
            'created_at' => 'timestamp',
            'manufacturer' => 'string',
            'title' => 'text',
            'price' => 'float',
            'qty' => 'integer',
            'on_sale' => 'bool',
        ], 'crud');
    }

    /**
     * @param array $overrides
     *
     * @return array
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'created_at' => 1700000000,
            'manufacturer' => 'Samsung',
            'title' => 'Galaxy S23 Ultra',
            'price' => 1199.00,
            'qty' => 10,
            'on_sale' => true,
        ], $overrides);
    }

    public function testInsertResultSetReturnsGeneratedId(): void
    {
        $result = ManticoreDb::table($this->table)->insertResultSet($this->row());

        $this->assertTrue($result->success(), (string)$result->error());
        $this->assertSame('inserted', $result->status());
        $this->assertIsInt($result->result());
        $this->assertGreaterThan(0, $result->result());
    }

    public function testInsertReturnsTrueOnSuccess(): void
    {
        $this->assertTrue(ManticoreDb::table($this->table)->insert($this->row()));
    }

    public function testInsertGetIdReturnsGeneratedId(): void
    {
        $id = ManticoreDb::table($this->table)->insertGetId($this->row());

        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
        $this->assertNotNull(ManticoreDb::table($this->table)->find($id));
    }

    public function testInsertWithExplicitIdInData(): void
    {
        $this->assertTrue(ManticoreDb::table($this->table)->insert($this->row(['id' => 500])));
        $this->assertNotNull(ManticoreDb::table($this->table)->find(500));
    }

    public function testInsertMultipleRowsReturnsIdList(): void
    {
        $result = ManticoreDb::table($this->table)->insertResultSet([
            $this->row(['title' => 'first']),
            $this->row(['title' => 'second']),
            $this->row(['title' => 'third']),
        ]);

        $this->assertTrue($result->success(), (string)$result->error());
        $ids = $result->result();
        $this->assertIsArray($ids);
        $this->assertCount(3, $ids);
        $this->assertContainsOnly('int', $ids);
        $this->assertSame(3, ManticoreDb::table($this->table)->count());
    }

    public function testInsertGetIdOfMultipleRowsReturnsTheFirstId(): void
    {
        $ids = ManticoreDb::table($this->table)->insertResultSet([
            $this->row(['title' => 'first']),
            $this->row(['title' => 'second']),
        ])->result();

        // the whole list only lives in the ResultSet, the scalar wrapper answers as
        // LAST_INSERT_ID() of MySQL would
        $id = ManticoreDb::table($this->table)->insertGetId([
            $this->row(['title' => 'third']),
            $this->row(['title' => 'fourth']),
        ]);

        $this->assertIsInt($id);
        $this->assertGreaterThan(end($ids), $id);
        $this->assertSame('third', ManticoreDb::table($this->table)->find($id)['title']);
    }

    public function testInsertWithExplicitIdArgument(): void
    {
        $this->assertTrue(ManticoreDb::table($this->table)->insert($this->row(), 600));
        $this->assertNotNull(ManticoreDb::table($this->table)->find(600));
    }

    public function testInsertMultipleRowsWithDifferentColumnSets(): void
    {
        $this->assertTrue(ManticoreDb::table($this->table)->insert([
            $this->row(['title' => 'complete']),
            ['title' => 'partial'],
        ]));

        $rows = ManticoreDb::table($this->table)->orderBy('id')->get();
        $partial = end($rows);

        // the missing columns are filled with the empty value of their type
        $this->assertSame('partial', $partial['title']);
        $this->assertSame(0, $partial['created_at']);
        $this->assertSame('', $partial['manufacturer']);
        $this->assertSame(0.0, $partial['price']);
        $this->assertFalse($partial['on_sale']);
    }

    public function testFindReturnsRowById(): void
    {
        $id = ManticoreDb::table($this->table)->insertGetId($this->row());

        $row = ManticoreDb::table($this->table)->find($id);

        $this->assertIsArray($row);
        $this->assertSame('Galaxy S23 Ultra', $row['title']);
        $this->assertSame($id, $row['id']);
        // the row of find()/first() is shaped like the row of get(), generated "_id" included
        $this->assertSame($id, $row['_id']);
    }

    /**
     * Which method the row came from must not change its columns
     */
    public function testFirstAndGetShapeTheRowAlike(): void
    {
        ManticoreDb::table($this->table)->insert($this->row());

        $first = ManticoreDb::table($this->table)->first();
        $fromGet = reset(ManticoreDb::table($this->table)->get());

        $this->assertSame(array_keys($fromGet), array_keys($first));
    }

    public function testFindReturnsNullForMissingId(): void
    {
        $this->assertNull(ManticoreDb::table($this->table)->find(999999));
    }

    public function testFirstReturnsSingleRow(): void
    {
        ManticoreDb::table($this->table)->insert([
            $this->row(['title' => 'first', 'price' => 1.0]),
            $this->row(['title' => 'second', 'price' => 2.0]),
        ]);

        $row = ManticoreDb::table($this->table)->orderBy('price')->first();

        $this->assertSame('first', $row['title']);
    }

    public function testFirstReturnsNullOnEmptyTable(): void
    {
        $this->assertNull(ManticoreDb::table($this->table)->first());
    }

    public function testGetReturnsDictionaryKeyedById(): void
    {
        $id = ManticoreDb::table($this->table)->insertGetId($this->row());

        $rows = ManticoreDb::table($this->table)->get();

        $this->assertArrayHasKey($id, $rows);
        $this->assertSame($id, $rows[$id]['_id']);
    }

    public function testSelectedColumnsOnlyAreReturned(): void
    {
        ManticoreDb::table($this->table)->insert($this->row());

        $rows = ManticoreDb::table($this->table)->get(['id', 'title']);
        $row = reset($rows);

        $this->assertSame(['id', 'title'], array_keys($row));
    }

    public function testSearchReturnsResultSet(): void
    {
        ManticoreDb::table($this->table)->insert($this->row());

        $result = ManticoreDb::table($this->table)->search();

        $this->assertTrue($result->success(), (string)$result->error());
        $this->assertCount(1, $result->result());
    }

    public function testCountRunsSeparateAggregateQuery(): void
    {
        ManticoreDb::table($this->table)->insert([
            $this->row(['price' => 10.0]),
            $this->row(['price' => 20.0]),
            $this->row(['price' => 30.0]),
        ]);

        $this->assertSame(3, ManticoreDb::table($this->table)->count());
        $this->assertSame(2, ManticoreDb::table($this->table)->where('price', '>', 15)->count());
    }

    public function testPluckReturnsAList(): void
    {
        ManticoreDb::table($this->table)->insert([
            $this->row(['title' => 'first']),
            $this->row(['title' => 'second']),
        ]);

        $titles = ManticoreDb::table($this->table)->pluck('title');

        $this->assertSame(['first', 'second'], $titles);
        $this->assertSame([0, 1], array_keys($titles));
    }

    public function testPluckKeyedByAnotherColumn(): void
    {
        ManticoreDb::table($this->table)->insert([
            $this->row(['title' => 'first', 'manufacturer' => 'acme']),
            $this->row(['title' => 'second', 'manufacturer' => 'other']),
        ]);

        $titles = ManticoreDb::table($this->table)->pluck('title', 'manufacturer');

        $this->assertSame(['acme' => 'first', 'other' => 'second'], $titles);
    }

    public function testPluckOfAnEmptyResultIsAnEmptyArray(): void
    {
        $this->assertSame([], ManticoreDb::table($this->table)->where('price', '>', 1e9)->pluck('title'));
    }

    public function testUpdateByCondition(): void
    {
        ManticoreDb::table($this->table)->insert([
            $this->row(['title' => 'cheap', 'price' => 10.0]),
            $this->row(['title' => 'expensive', 'price' => 100.0]),
        ]);

        $result = ManticoreDb::table($this->table)->where('price', '<', 50)->updateResultSet(['qty' => 0]);

        $this->assertTrue($result->success(), (string)$result->error());
        $this->assertSame('updated', $result->status());
        $this->assertSame(1, $result->result(), 'update() returns the number of affected rows');

        $rows = ManticoreDb::table($this->table)->where('qty', 0)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('cheap', reset($rows)['title']);
    }

    public function testUpdateReturnsNumberOfUpdatedRows(): void
    {
        ManticoreDb::table($this->table)->insert([
            $this->row(['title' => 'cheap', 'price' => 10.0]),
            $this->row(['title' => 'also cheap', 'price' => 20.0]),
            $this->row(['title' => 'expensive', 'price' => 100.0]),
        ]);

        $affected = ManticoreDb::table($this->table)->where('price', '<', 50)->update(['qty' => 0]);

        $this->assertSame(2, $affected);
    }

    public function testUpdateById(): void
    {
        $id = ManticoreDb::table($this->table)->insertGetId($this->row());

        $this->assertSame(1, ManticoreDb::table($this->table)->update(['price' => 9.99], $id));
        $this->assertSame(9.99, ManticoreDb::table($this->table)->find($id)['price']);
    }

    public function testUpdateEscapesStringValue(): void
    {
        $id = ManticoreDb::table($this->table)->insertGetId($this->row());

        ManticoreDb::table($this->table)->update(['manufacturer' => "O'Neill"], $id);

        $this->assertSame("O'Neill", ManticoreDb::table($this->table)->find($id)['manufacturer']);
    }

    public function testUpdateOfMissingRowAffectsNothing(): void
    {
        $result = ManticoreDb::table($this->table)->where('id', 999999)->updateResultSet(['qty' => 1]);

        // 0 of the scalar update() alone would not tell this apart from a failed statement
        $this->assertTrue($result->success(), (string)$result->error());
        $this->assertSame(0, $result->result());
        $this->assertSame(0, ManticoreDb::table($this->table)->where('id', 999999)->update(['qty' => 1]));
    }

    public function testReplaceOverwritesWholeRow(): void
    {
        $id = ManticoreDb::table($this->table)->insertGetId($this->row());

        $result = ManticoreDb::table($this->table)->replaceResultSet([
            'title' => 'replaced',
            'price' => 1.0,
        ], $id);

        $this->assertTrue($result->success(), (string)$result->error());
        $this->assertSame('replaced', $result->status());
        $this->assertSame($id, $result->result(), 'REPLACE reports the id it wrote');

        $row = ManticoreDb::table($this->table)->find($id);
        $this->assertSame('replaced', $row['title']);
        // columns missing from the replacement are reset, not kept
        $this->assertSame(0, $row['qty']);
    }

    public function testReplaceInsertsWhenIdIsUnknown(): void
    {
        $this->assertTrue(ManticoreDb::table($this->table)->replace(['title' => 'new one'], 777));
        $this->assertSame('new one', ManticoreDb::table($this->table)->find(777)['title']);
    }

    public function testReplaceGetIdReturnsExplicitId(): void
    {
        $this->assertSame(777, ManticoreDb::table($this->table)->replaceGetId(['title' => 'x'], 777));
    }

    public function testReplaceGetIdGeneratesAnIdWhenNoneWasGiven(): void
    {
        $id = ManticoreDb::table($this->table)->replaceGetId($this->row());

        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
        $this->assertSame('Galaxy S23 Ultra', ManticoreDb::table($this->table)->find($id)['title']);
    }

    public function testDeleteByCondition(): void
    {
        ManticoreDb::table($this->table)->insert([
            $this->row(['price' => 10.0]),
            $this->row(['price' => 100.0]),
        ]);

        $result = ManticoreDb::table($this->table)->where('price', '<', 50)->deleteResultSet();

        $this->assertTrue($result->success(), (string)$result->error());
        $this->assertSame('deleted', $result->status());
        $this->assertSame(1, $result->result(), 'delete() returns the number of deleted rows');
        $this->assertSame(1, ManticoreDb::table($this->table)->count());
    }

    public function testDeleteReturnsNumberOfDeletedRows(): void
    {
        ManticoreDb::table($this->table)->insert([
            $this->row(['price' => 10.0]),
            $this->row(['price' => 20.0]),
            $this->row(['price' => 100.0]),
        ]);

        $this->assertSame(2, ManticoreDb::table($this->table)->where('price', '<', 50)->delete());
        $this->assertSame(0, ManticoreDb::table($this->table)->where('price', '<', 50)->delete());
    }

    public function testDeleteById(): void
    {
        $id = ManticoreDb::table($this->table)->insertGetId($this->row());

        ManticoreDb::table($this->table)->where('id', $id)->delete();

        $this->assertNull(ManticoreDb::table($this->table)->find($id));
    }

    public function testDeleteByIdArgument(): void
    {
        $id = ManticoreDb::table($this->table)->insertGetId($this->row());

        $this->assertSame(1, ManticoreDb::table($this->table)->delete($id));
        $this->assertNull(ManticoreDb::table($this->table)->find($id));
    }

    public function testDeleteByMatch(): void
    {
        ManticoreDb::table($this->table)->insert([
            $this->row(['title' => 'keep me']),
            $this->row(['title' => 'delete me']),
        ]);

        ManticoreDb::table($this->table)->match('delete')->delete();

        $rows = ManticoreDb::table($this->table)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('keep me', reset($rows)['title']);
    }

    public function testValueWithQuotesSurvivesRoundTrip(): void
    {
        $value = "O'Neill \\ \"quoted\" %100";
        $id = ManticoreDb::table($this->table)->insertGetId($this->row(['manufacturer' => $value]));

        $this->assertSame($value, ManticoreDb::table($this->table)->find($id)['manufacturer']);
    }

    public function testValueThatLooksLikeSqlIsStoredLiterally(): void
    {
        $value = "x'; DROP TABLE " . $this->table . "; --";
        $id = ManticoreDb::table($this->table)->insertGetId($this->row(['manufacturer' => $value]));

        $this->assertSame($value, ManticoreDb::table($this->table)->find($id)['manufacturer']);
        $this->assertTrue(ManticoreDb::hasTable($this->table), 'the table must still be there');
    }

    public function testUnicodeValueSurvivesRoundTrip(): void
    {
        $id = ManticoreDb::table($this->table)->insertGetId($this->row(['manufacturer' => 'Ёлка «ёж»']));

        $this->assertSame('Ёлка «ёж»', ManticoreDb::table($this->table)->find($id)['manufacturer']);
    }

    public function testScalarWritesReportFailureWithoutThrowing(): void
    {
        $missing = 'no_such_table_' . uniqid();

        $this->assertFalse(ManticoreDb::table($missing)->insert(['title' => 'nope']));
        $this->assertNull(ManticoreDb::table($missing)->insertGetId(['title' => 'nope']));
        $this->assertSame(0, ManticoreDb::table($missing)->where('id', 1)->update(['title' => 'nope']));
        $this->assertSame(0, ManticoreDb::table($missing)->where('id', 1)->delete());
    }

    public function testLastResultSetCarriesTheErrorOfAScalarWrite(): void
    {
        $missing = 'no_such_table_' . uniqid();

        $this->assertFalse(ManticoreDb::table($missing)->insert(['title' => 'nope']));

        // the scalar answer says "it failed", the ResultSet left behind says why - note that
        // columnTypes() runs a DESCRIBE of its own before the INSERT, and it must not be the
        // one we end up looking at
        $result = ManticoreDb::lastResultSet();
        $this->assertNotNull($result);
        $this->assertSame('INSERT', $result->command());
        $this->assertFalse($result->success());
        $this->assertNotEmpty($result->error());
    }

    public function testLastResultSetFollowsTheSuccessfulWriteToo(): void
    {
        $id = ManticoreDb::table($this->table)->insertGetId($this->row());

        $result = ManticoreDb::lastResultSet();
        $this->assertSame('INSERT', $result->command());
        $this->assertTrue($result->success(), (string)$result->error());
        $this->assertSame('inserted', $result->status());
        $this->assertSame($id, $result->result());
        $this->assertStringContainsString('INSERT INTO', (string)$result->sqlQuery());
    }
}
