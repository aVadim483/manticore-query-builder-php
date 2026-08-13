<?php

namespace avadim\Manticore\Tests\Integration;

use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use avadim\Manticore\Tests\Support\IntegrationTestCase;

/**
 * WHERE conditions executed by the server (the SQL itself is covered by WhereSqlTest).
 */
final class WhereConditionsTest extends IntegrationTestCase
{
    /** @var string */
    private string $table;

    protected function setUp(): void
    {
        parent::setUp();
        $this->table = $this->createTable([
            'time' => 'timestamp',
            'demo' => 'bool',
            'country' => 'string',
            'price' => 'float',
            'content' => 'text',
            'sizes' => 'multi',
            'values' => 'multi64',
        ], 'where');
        ManticoreDb::table($this->table)->insert($this->documents());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function documents(): array
    {
        $time = 1700000000;

        return [
            ['id' => 1, 'time' => $time, 'demo' => false, 'country' => 'US', 'price' => 100.00, 'content' => 'lorem ipsum', 'sizes' => [1, 3, 5], 'values' => [0, -1, 1]],
            ['id' => 2, 'time' => $time, 'demo' => false, 'country' => 'DE', 'price' => 200.00, 'content' => 'Lorem ipsum dolor sit amet', 'sizes' => [2, 4, 6], 'values' => [PHP_INT_MIN, 0, PHP_INT_MAX]],
            ['id' => 3, 'time' => $time, 'demo' => true, 'country' => 'US', 'price' => 300.00, 'content' => 'ipsum dolor sit amet', 'sizes' => [1, 2, 3], 'values' => [9, 36, 223]],
            ['id' => 4, 'time' => $time, 'demo' => false, 'country' => 'DE', 'price' => 180.00, 'content' => 'amet', 'sizes' => [4, 5, 6], 'values' => [0, PHP_INT_MAX]],
            ['id' => 5, 'time' => $time, 'demo' => true, 'country' => 'UK', 'price' => 230.00, 'content' => 'dolor sit', 'sizes' => [1, 2, 3, 4, 5, 6], 'values' => [PHP_INT_MIN]],
            ['id' => 6, 'time' => $time, 'demo' => false, 'country' => 'UK', 'price' => 310.00, 'content' => 'ipsum dolor sit', 'sizes' => [], 'values' => [18, 446, 744]],
            ['id' => 7, 'time' => $time, 'demo' => false, 'country' => 'DE', 'price' => 185.00, 'content' => 'ipsum', 'sizes' => [2], 'values' => [4, 294, 967]],
            ['id' => 8, 'time' => $time, 'demo' => true, 'country' => 'US', 'price' => 298.00, 'content' => 'dolor sit', 'sizes' => [4], 'values' => [0, -2147483648]],
        ];
    }

    /**
     * @param array $rows
     *
     * @return int[]
     */
    private function ids(array $rows): array
    {
        return array_values(array_column($rows, 'id'));
    }

    public function testMatchWithEqualityCondition(): void
    {
        $rows = ManticoreDb::table($this->table)->match('ipsum')->where('country', 'de')->get();

        $this->assertSame([2, 7], $this->ids($rows));
    }

    public function testOrConditionWidensResult(): void
    {
        $rows = ManticoreDb::table($this->table)
            ->match('ipsum')
            ->where('country', 'de')
            ->orWhere('price', '>', 150)
            ->get();

        $this->assertSame([2, 3, 6, 7], $this->ids($rows));
    }

    public function testNestedConditionGivesSameResultAsFlatOne(): void
    {
        $rows = ManticoreDb::table($this->table)
            ->match('ipsum')
            ->where(function ($condition) {
                $condition->where('country', 'de');
                $condition->orWhere('price', '>', 150);
            })
            ->get();

        $this->assertSame([2, 3, 6, 7], $this->ids($rows));
    }

    public function testWhereIn(): void
    {
        $rows = ManticoreDb::table($this->table)->match('ipsum')->whereIn('country', ['de', 'us'])->get();

        $this->assertSame([1, 2, 3, 7], $this->ids($rows));
    }

    public function testWhereNotIn(): void
    {
        $rows = ManticoreDb::table($this->table)->whereNotIn('country', ['de', 'uk'])->get();

        $this->assertSame([1, 3, 8], $this->ids($rows));
    }

    public function testTwoConditionsAreCombinedWithAnd(): void
    {
        $rows = ManticoreDb::table($this->table)
            ->where('country', '!=', 'de')
            ->where('price', '>', 250)
            ->get();

        $this->assertSame([3, 6, 8], $this->ids($rows));
    }

    public function testWhereBetween(): void
    {
        $rows = ManticoreDb::table($this->table)->whereBetween('price', [180, 200])->get();

        $this->assertSame([2, 4, 7], $this->ids($rows));
    }

    public function testWhereNotBetween(): void
    {
        $rows = ManticoreDb::table($this->table)->whereNotBetween('price', [180, 310])->get();

        $this->assertSame([1], $this->ids($rows));
    }

    public function testOrWhereBetween(): void
    {
        $rows = ManticoreDb::table($this->table)
            ->where('country', 'uk')
            ->orWhereBetween('price', [100, 110])
            ->get();

        $this->assertSame([1, 5, 6], $this->ids($rows));
    }

    public function testBooleanConditionAcceptsTrue(): void
    {
        $rows = ManticoreDb::table($this->table)->where('demo', true)->get();

        $this->assertSame([3, 5, 8], $this->ids($rows));
    }

    public function testBooleanConditionAcceptsZero(): void
    {
        $rows = ManticoreDb::table($this->table)->where('demo', 0)->get();

        $this->assertSame([1, 2, 4, 6, 7], $this->ids($rows));
    }

    public function testNamedParameterInCondition(): void
    {
        $rows = ManticoreDb::table($this->table)
            ->where('country=:country')
            ->bind([':country' => 'de'])
            ->get();

        $this->assertSame([2, 4, 7], $this->ids($rows));
    }

    public function testAnyOverMultiValueAttribute(): void
    {
        $rows = ManticoreDb::table($this->table)->where('ANY(sizes)', 6)->get();

        $this->assertSame([2, 4, 5], $this->ids($rows));
    }

    public function testAllOverMultiValueAttribute(): void
    {
        $rows = ManticoreDb::table($this->table)->where('ALL(values)', '>', 0)->get();

        // rows 3, 6 and 7 are the ones whose every multi64 value is positive
        $this->assertSame([3, 6, 7], $this->ids($rows));
    }

    public function testWhereNullAndNotNullOverJsonAttribute(): void
    {
        $table = $this->createTable(['title' => 'text', 'info' => 'json'], 'isnull');
        ManticoreDb::table($table)->insert([
            ['id' => 1, 'title' => 'with', 'info' => ['x' => 1]],
            ['id' => 2, 'title' => 'without', 'info' => ['y' => 2]],
        ]);

        $withNull = ManticoreDb::table($table)->whereNull('info.x')->get();
        $withValue = ManticoreDb::table($table)->whereNotNull('info.x')->get();

        $this->assertSame([2], $this->ids($withNull));
        $this->assertSame([1], $this->ids($withValue));
    }

    public function testNullValueIsSentAsIsNull(): void
    {
        // where($field, null) and where($field, '=', null) must reach the server as IS NULL
        $table = $this->createTable(['title' => 'text', 'info' => 'json'], 'nullvalue');
        ManticoreDb::table($table)->insert([
            ['id' => 1, 'title' => 'with', 'info' => ['x' => 1]],
            ['id' => 2, 'title' => 'without', 'info' => ['y' => 2]],
        ]);

        $shortForm = ManticoreDb::table($table)->where('info.x', null)->get();
        $longForm = ManticoreDb::table($table)->where('info.x', '=', null)->get();
        $notNull = ManticoreDb::table($table)->where('info.x', '!=', null)->get();

        $this->assertSame([2], $this->ids($shortForm));
        $this->assertSame([2], $this->ids($longForm));
        $this->assertSame([1], $this->ids($notNull));
    }

    public function testConditionsFromArray(): void
    {
        $rows = ManticoreDb::table($this->table)->where(['country' => 'DE', 'price' => 200.00])->get();

        $this->assertSame([2], $this->ids($rows));
    }

    public function testGroupedConditionsUseTheWholeWhereFamily(): void
    {
        $rows = ManticoreDb::table($this->table)
            ->where(function ($condition) {
                $condition->whereIn('country', ['UK']);
                $condition->orWhereBetween('price', [100.00, 100.00]);
            })
            ->orderBy('id')
            ->get();

        $this->assertSame([1, 5, 6], $this->ids($rows));
    }

    public function testOrderByDescWithLimit(): void
    {
        $rows = ManticoreDb::table($this->table)
            ->where('ANY(values)', PHP_INT_MIN)
            ->orderByDesc('id')
            ->limit(1)
            ->get();

        $this->assertSame([5], $this->ids($rows));
    }

    public function testOrderByAscending(): void
    {
        $rows = ManticoreDb::table($this->table)->orderBy('price')->limit(2)->get();

        $this->assertSame([1, 4], $this->ids($rows));
    }

    public function testGroupByWithHaving(): void
    {
        $rows = ManticoreDb::table($this->table)
            ->select(['country', 'count(*) as cnt'])
            ->groupBy('country')
            ->having('cnt>2')
            ->get();

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertGreaterThan(2, $row['cnt']);
        }
    }

    public function testConditionWithQuoteInValueFindsRow(): void
    {
        ManticoreDb::table($this->table)->insert(['id' => 100, 'country' => "O'Neill", 'price' => 1.0]);

        $rows = ManticoreDb::table($this->table)->where('country', "O'Neill")->get();

        $this->assertSame([100], $this->ids($rows));
    }
}
