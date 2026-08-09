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

    public function testInsertReturnsGeneratedId(): void
    {
        $result = ManticoreDb::table($this->table)->insert($this->row());

        $this->assertTrue($result->success(), (string)$result->error());
        $this->assertSame('inserted', $result->status());
        $this->assertIsInt($result->result());
        $this->assertGreaterThan(0, $result->result());
    }

    public function testInsertWithExplicitIdInData(): void
    {
        $result = ManticoreDb::table($this->table)->insert($this->row(['id' => 500]));

        $this->assertTrue($result->success(), (string)$result->error());
        $this->assertNotNull(ManticoreDb::table($this->table)->find(500));
    }

    public function testInsertMultipleRowsReturnsIdList(): void
    {
        $result = ManticoreDb::table($this->table)->insert([
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

    public function testInsertMultipleRowsWithDifferentColumnSets(): void
    {
        $result = ManticoreDb::table($this->table)->insert([
            $this->row(['title' => 'complete']),
            ['title' => 'partial'],
        ]);

        $this->assertTrue($result->success(), (string)$result->error());

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
        $id = ManticoreDb::table($this->table)->insert($this->row())->result();

        $row = ManticoreDb::table($this->table)->find($id);

        $this->assertIsArray($row);
        $this->assertSame('Galaxy S23 Ultra', $row['title']);
        // find()/first() keep the explicit "SELECT *", so the row carries "id",
        // while get()/search() without arguments rewrite it to "id as _id"
        $this->assertSame($id, $row['id']);
        $this->assertArrayNotHasKey('_id', $row);
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
        $id = ManticoreDb::table($this->table)->insert($this->row())->result();

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

    public function testPluckKeepsRowKeys(): void
    {
        ManticoreDb::table($this->table)->insert([
            $this->row(['title' => 'first']),
            $this->row(['title' => 'second']),
        ]);

        $titles = ManticoreDb::table($this->table)->pluck('title');

        $this->assertSame(['first', 'second'], array_values($titles));
        $this->assertContainsOnly('int', array_keys($titles));
    }

    public function testUpdateByCondition(): void
    {
        ManticoreDb::table($this->table)->insert([
            $this->row(['title' => 'cheap', 'price' => 10.0]),
            $this->row(['title' => 'expensive', 'price' => 100.0]),
        ]);

        $result = ManticoreDb::table($this->table)->where('price', '<', 50)->update(['qty' => 0]);

        $this->assertTrue($result->success(), (string)$result->error());
        $this->assertSame('updated', $result->status());
        $this->assertSame(1, $result->result(), 'update() returns the number of affected rows');

        $rows = ManticoreDb::table($this->table)->where('qty', 0)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('cheap', reset($rows)['title']);
    }

    public function testUpdateById(): void
    {
        $id = ManticoreDb::table($this->table)->insert($this->row())->result();

        $result = ManticoreDb::table($this->table)->update(['price' => 9.99], $id);

        $this->assertTrue($result->success(), (string)$result->error());
        $this->assertSame(9.99, ManticoreDb::table($this->table)->find($id)['price']);
    }

    public function testUpdateEscapesStringValue(): void
    {
        $id = ManticoreDb::table($this->table)->insert($this->row())->result();

        ManticoreDb::table($this->table)->update(['manufacturer' => "O'Neill"], $id);

        $this->assertSame("O'Neill", ManticoreDb::table($this->table)->find($id)['manufacturer']);
    }

    public function testUpdateOfMissingRowAffectsNothing(): void
    {
        $result = ManticoreDb::table($this->table)->where('id', 999999)->update(['qty' => 1]);

        $this->assertTrue($result->success(), (string)$result->error());
        $this->assertSame(0, $result->result());
    }

    public function testReplaceOverwritesWholeRow(): void
    {
        $id = ManticoreDb::table($this->table)->insert($this->row())->result();

        $result = ManticoreDb::table($this->table)->replace([
            'title' => 'replaced',
            'price' => 1.0,
        ], $id);

        $this->assertTrue($result->success(), (string)$result->error());
        $this->assertSame('replaced', $result->status());

        $row = ManticoreDb::table($this->table)->find($id);
        $this->assertSame('replaced', $row['title']);
        // columns missing from the replacement are reset, not kept
        $this->assertSame(0, $row['qty']);
    }

    public function testReplaceInsertsWhenIdIsUnknown(): void
    {
        $result = ManticoreDb::table($this->table)->replace(['title' => 'new one'], 777);

        $this->assertTrue($result->success(), (string)$result->error());
        $this->assertSame('new one', ManticoreDb::table($this->table)->find(777)['title']);
    }

    public function testDeleteByCondition(): void
    {
        ManticoreDb::table($this->table)->insert([
            $this->row(['price' => 10.0]),
            $this->row(['price' => 100.0]),
        ]);

        $result = ManticoreDb::table($this->table)->where('price', '<', 50)->delete();

        $this->assertTrue($result->success(), (string)$result->error());
        $this->assertSame('deleted', $result->status());
        $this->assertSame(1, ManticoreDb::table($this->table)->count());
    }

    public function testDeleteById(): void
    {
        $id = ManticoreDb::table($this->table)->insert($this->row())->result();

        ManticoreDb::table($this->table)->where('id', $id)->delete();

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
        $id = ManticoreDb::table($this->table)->insert($this->row(['manufacturer' => $value]))->result();

        $this->assertSame($value, ManticoreDb::table($this->table)->find($id)['manufacturer']);
    }

    public function testValueThatLooksLikeSqlIsStoredLiterally(): void
    {
        $value = "x'; DROP TABLE " . $this->table . "; --";
        $id = ManticoreDb::table($this->table)->insert($this->row(['manufacturer' => $value]))->result();

        $this->assertSame($value, ManticoreDb::table($this->table)->find($id)['manufacturer']);
        $this->assertTrue(ManticoreDb::hasTable($this->table), 'the table must still be there');
    }

    public function testUnicodeValueSurvivesRoundTrip(): void
    {
        $id = ManticoreDb::table($this->table)->insert($this->row(['manufacturer' => 'Ёлка «ёж»']))->result();

        $this->assertSame('Ёлка «ёж»', ManticoreDb::table($this->table)->find($id)['manufacturer']);
    }
}
