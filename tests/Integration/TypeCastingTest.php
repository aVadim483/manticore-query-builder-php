<?php

namespace avadim\Manticore\Tests\Integration;

use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use avadim\Manticore\Tests\Support\IntegrationTestCase;

/**
 * Values must come back from the server as the PHP types they were written as:
 * DESCRIBE drives both formatValue() on write and _castResult() on read.
 */
final class TypeCastingTest extends IntegrationTestCase
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
            'info' => 'json',
            'price' => 'float',
            'qty' => 'integer',
            'huge' => 'bigint',
            'categories' => 'multi',
            'values' => 'multi64',
            'on_sale' => 'bool',
        ], 'types');
    }

    /**
     * @param array $row
     *
     * @return array the row as it was read back
     */
    private function roundTrip(array $row): array
    {
        $id = ManticoreDb::table($this->table)->insert($row)->result();
        $this->assertIsInt($id, 'insert must return an id');

        $result = ManticoreDb::table($this->table)->where('id', $id)->get();

        return reset($result);
    }

    public function testScalarTypesKeepTheirPhpType(): void
    {
        $row = $this->roundTrip([
            'created_at' => 1700000000,
            'manufacturer' => 'Samsung',
            'title' => 'Galaxy',
            'price' => 1199.5,
            'qty' => 7,
            'huge' => 9223372036854775806,
            'on_sale' => true,
        ]);

        $this->assertSame(1700000000, $row['created_at']);
        $this->assertSame('Samsung', $row['manufacturer']);
        $this->assertSame(1199.5, $row['price']);
        $this->assertSame(7, $row['qty']);
        $this->assertSame(9223372036854775806, $row['huge']);
        $this->assertTrue($row['on_sale']);
    }

    public function testBoolFalseIsReadAsFalse(): void
    {
        $row = $this->roundTrip(['title' => 'x', 'on_sale' => false]);

        $this->assertIsBool($row['on_sale']);
        $this->assertFalse($row['on_sale']);
    }

    public function testJsonObjectIsDecodedIntoArray(): void
    {
        $info = ['color' => 'Red', 'storage' => 512, 'tags' => ['a', 'b']];

        $row = $this->roundTrip(['title' => 'x', 'info' => $info]);

        $this->assertSame($info, $row['info']);
    }

    public function testJsonKeepsUnicode(): void
    {
        $row = $this->roundTrip(['title' => 'x', 'info' => ['name' => 'Ёлка']]);

        $this->assertSame(['name' => 'Ёлка'], $row['info']);
    }

    public function testJsonWithQuotesSurvivesRoundTrip(): void
    {
        $info = ['note' => "it's \"quoted\""];

        $row = $this->roundTrip(['title' => 'x', 'info' => $info]);

        $this->assertSame($info, $row['info']);
    }

    public function testMultiIsReadAsIntArray(): void
    {
        $row = $this->roundTrip(['title' => 'x', 'categories' => [5, 7, 11]]);

        $this->assertSame([5, 7, 11], $row['categories']);
    }

    public function testMulti64IsWrittenCorrectly(): void
    {
        // reading multi64 back as an array is broken, see KnownServerIssuesTest
        $row = $this->roundTrip(['title' => 'x', 'values' => [PHP_INT_MIN, 0, PHP_INT_MAX]]);

        $this->assertSame(
            [PHP_INT_MIN, 0, PHP_INT_MAX],
            array_map('intval', explode(',', (string)$row['values']))
        );
    }

    public function testMultiIsQueryable(): void
    {
        ManticoreDb::table($this->table)->insert([
            ['title' => 'a', 'categories' => [1, 3, 5]],
            ['title' => 'b', 'categories' => [2, 4, 6]],
        ]);

        $this->assertSame(1, ManticoreDb::table($this->table)->where('ANY(categories)', 6)->count());
        $this->assertSame(2, ManticoreDb::table($this->table)->where('ALL(categories)', '>', 0)->count());
    }

    public function testTimestampAcceptsDateString(): void
    {
        $expected = strtotime('2020-01-02 03:04:05');

        $row = $this->roundTrip(['title' => 'x', 'created_at' => '2020-01-02 03:04:05']);

        $this->assertSame($expected, $row['created_at']);
    }

    public function testNumericStringsAreCastByColumnType(): void
    {
        $row = $this->roundTrip(['title' => 'x', 'qty' => '42', 'price' => '1.5']);

        $this->assertSame(42, $row['qty']);
        $this->assertSame(1.5, $row['price']);
    }

    public function testCountFunctionResultIsCastToInt(): void
    {
        ManticoreDb::table($this->table)->insert([
            ['title' => 'a', 'qty' => 1],
            ['title' => 'b', 'qty' => 1],
        ]);

        $rows = ManticoreDb::table($this->table)->select(['qty', 'count(*) as c'])->groupBy('qty')->get();
        $row = reset($rows);

        $this->assertSame(2, $row['c']);
    }

    public function testUpdateKeepsTypes(): void
    {
        $id = ManticoreDb::table($this->table)->insert([
            'title' => 'x',
            'categories' => [1],
            'on_sale' => true,
            'price' => 1.0,
        ])->result();

        ManticoreDb::table($this->table)->update([
            'categories' => [9, 8],
            'on_sale' => false,
            'price' => 2.5,
        ], $id);

        $rows = ManticoreDb::table($this->table)->where('id', $id)->get();
        $row = reset($rows);

        // Manticore keeps MVA values sorted, so compare regardless of order
        $this->assertSame([8, 9], $row['categories']);
        $this->assertFalse($row['on_sale']);
        $this->assertSame(2.5, $row['price']);
    }

    public function testJsonFieldIsQueryable(): void
    {
        ManticoreDb::table($this->table)->insert([
            ['title' => 'a', 'info' => ['price' => 210.0, 'cpu' => ['model' => 'Kyro 345', 'cores' => 8]]],
            ['title' => 'b', 'info' => ['price' => 410.0, 'cpu' => ['model' => 'Cortex A75', 'cores' => 8]]],
            ['title' => 'c', 'info' => ['price' => 360.0, 'cpu' => ['model' => 'Cortex A53', 'cores' => 8]]],
        ]);

        $this->assertSame(2, ManticoreDb::table($this->table)->where('DOUBLE(info.price)>250')->count());
        $this->assertSame(1, ManticoreDb::table($this->table)->where('info.cpu.model', 'Kyro 345')->count());
        $this->assertSame(
            1,
            ManticoreDb::table($this->table)->where('regex(info.cpu.model, \'Kyro*\')')->count()
        );
    }

    public function testJsonFieldWithNamedParameter(): void
    {
        ManticoreDb::table($this->table)->insert([
            ['title' => 'a', 'info' => ['price' => 210.0]],
            ['title' => 'b', 'info' => ['price' => 410.0]],
        ]);

        $count = ManticoreDb::table($this->table)
            ->where('DOUBLE(info.price)>:price')
            ->bind([':price' => 250])
            ->count();

        $this->assertSame(1, $count);
    }

    public function testJsonArrayElementInGroupBy(): void
    {
        ManticoreDb::table($this->table)->insert([
            ['title' => 'a', 'info' => ['video_rec' => [1080, 720]]],
            ['title' => 'b', 'info' => ['video_rec' => [2016, 1080]]],
            ['title' => 'c', 'info' => ['video_rec' => [2016, 1080]]],
        ]);

        $rows = ManticoreDb::table($this->table)
            ->select(['info.video_rec[0] as g', 'count(*) as c'])
            ->groupBy('g')
            ->orderBy('g')
            ->get();
        $rows = array_values($rows);

        $this->assertEquals(1080, $rows[0]['g']);
        $this->assertSame(1, $rows[0]['c']);
        $this->assertEquals(2016, $rows[1]['g']);
        $this->assertSame(2, $rows[1]['c']);
    }

    public function testExpressionOverJsonArray(): void
    {
        ManticoreDb::table($this->table)->insert([
            [
                'title' => 'near',
                'info' => ['locations' => [['lat' => 23.0, 'long' => 46.5, 'stock' => 30]]],
            ],
            [
                'title' => 'far',
                'info' => ['locations' => [['lat' => 43.0, 'long' => 16.5, 'stock' => 30]]],
            ],
        ]);

        $rows = ManticoreDb::table($this->table)
            ->select([
                'title',
                'ANY(x.stock > 0 AND GEODIST(23.0, 46.5, DOUBLE(x.lat), DOUBLE(x.long), {in=deg, out=mi}) < 10'
                . ' FOR x IN info.locations) AS close_to_you',
            ])
            ->orderBy('id')
            ->get();
        $rows = array_values($rows);

        $this->assertEquals(1, $rows[0]['close_to_you']);
        $this->assertEquals(0, $rows[1]['close_to_you']);
    }

    public function testNamedParametersInSelectExpression(): void
    {
        ManticoreDb::table($this->table)->insert([
            ['title' => 'a', 'info' => ['color' => ['blue', 'black']]],
            ['title' => 'b', 'info' => ['color' => ['white', 'black']]],
        ]);

        $rows = ManticoreDb::table($this->table)
            ->select(['*', 'IN(info.color, :black, :white) as color_filter'])
            ->where('color_filter=1')
            ->bind([':black' => 'black', ':white' => 'white'])
            ->get();

        $this->assertCount(2, $rows);
    }
}
