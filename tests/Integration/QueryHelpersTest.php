<?php

namespace avadim\Manticore\Tests\Integration;

use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use avadim\Manticore\QueryBuilder\QueryErrorException;
use avadim\Manticore\Tests\Support\IntegrationTestCase;

/**
 * The helpers borrowed from the Laravel query builder, against a live server: aggregates,
 * single values, the walks over a result and the writes built on top of them.
 */
final class QueryHelpersTest extends IntegrationTestCase
{
    /** @var string */
    private string $table;

    protected function setUp(): void
    {
        parent::setUp();
        $this->table = $this->createTable([
            'title' => 'text',
            'manufacturer' => 'string',
            'price' => 'float',
            'qty' => 'integer',
        ], 'helpers');
    }

    /**
     * @param int $count
     *
     * @return void
     */
    private function fill(int $count = 3): void
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'id' => $i,
                'title' => 'row number ' . $i,
                'manufacturer' => $i % 2 ? 'acme' : 'other',
                'price' => $i * 10.0,
                'qty' => $i,
            ];
        }
        ManticoreDb::table($this->table)->insert($rows);
    }

    public function testAggregates(): void
    {
        $this->fill();

        $query = ManticoreDb::table($this->table);
        $this->assertSame(30.0, $query->clone()->max('price'));
        $this->assertSame(10.0, $query->clone()->min('price'));
        $this->assertSame(6, $query->clone()->sum('qty'));
        $this->assertSame(20.0, $query->clone()->avg('price'));
        $this->assertSame(20.0, $query->clone()->average('price'));
        $this->assertSame(3, $query->clone()->count());
    }

    public function testAggregatesRespectTheConditions(): void
    {
        $this->fill();

        // prices are 10, 20, 30 - the condition leaves 20 and 30
        $this->assertSame(20.0, ManticoreDb::table($this->table)->where('price', '>', 15)->min('price'));
        $this->assertSame(50.0, ManticoreDb::table($this->table)->where('price', '>', 15)->sum('price'));
    }

    public function testAggregateOfAnEmptyResultIsNull(): void
    {
        $this->assertNull(ManticoreDb::table($this->table)->max('price'));
    }

    public function testValueReturnsOneColumnOfTheFirstRow(): void
    {
        $this->fill();

        $this->assertSame('other', ManticoreDb::table($this->table)->where('qty', 2)->value('manufacturer'));
        $this->assertNull(ManticoreDb::table($this->table)->where('qty', 99)->value('manufacturer'));
    }

    public function testExistsAndDoesntExist(): void
    {
        $this->fill();

        $this->assertTrue(ManticoreDb::table($this->table)->where('qty', 2)->exists());
        $this->assertFalse(ManticoreDb::table($this->table)->where('qty', 99)->exists());
        $this->assertTrue(ManticoreDb::table($this->table)->where('qty', 99)->doesntExist());
    }

    public function testSoleReturnsTheOnlyMatchingRow(): void
    {
        $this->fill();

        $row = ManticoreDb::table($this->table)->where('qty', 2)->sole();

        $this->assertSame('row number 2', $row['title']);
    }

    public function testSoleThrowsWhenNothingMatched(): void
    {
        $this->fill();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No records found');

        ManticoreDb::table($this->table)->where('qty', 99)->sole();
    }

    public function testSoleThrowsWhenSeveralRowsMatched(): void
    {
        $this->fill();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('More than one record');

        ManticoreDb::table($this->table)->where('price', '>', 5)->sole();
    }

    public function testChunkWalksThePagesOfTheResult(): void
    {
        $this->fill(7);
        $pages = [];

        $finished = ManticoreDb::table($this->table)->orderBy('id')->chunk(3, static function (array $rows, int $page) use (&$pages) {
            $pages[$page] = count($rows);
        });

        $this->assertTrue($finished);
        $this->assertSame([1 => 3, 2 => 3, 3 => 1], $pages);
    }

    public function testChunkStopsWhenTheCallbackReturnsFalse(): void
    {
        $this->fill(7);
        $pages = 0;

        $finished = ManticoreDb::table($this->table)->chunk(3, static function () use (&$pages) {
            $pages++;

            return false;
        });

        $this->assertFalse($finished);
        $this->assertSame(1, $pages);
    }

    public function testChunkByIdWalksByTheIdColumn(): void
    {
        $this->fill(7);
        $seen = [];

        ManticoreDb::table($this->table)->chunkById(3, static function (array $rows) use (&$seen) {
            foreach ($rows as $row) {
                $seen[] = $row['id'];
            }
        });

        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $seen);
    }

    /**
     * The condition of every next page must not pile up on the query it was called on
     */
    public function testChunkByIdLeavesTheQueryAlone(): void
    {
        $this->fill(5);
        $query = ManticoreDb::table($this->table)->where('price', '>', 5);

        $query->chunkById(2, static function () {
        });

        $this->assertSame(5, $query->count());
    }

    public function testEachWalksTheRows(): void
    {
        $this->fill(5);
        $titles = [];

        $finished = ManticoreDb::table($this->table)->orderBy('id')->each(static function (array $row) use (&$titles) {
            $titles[] = $row['title'];
        }, 2);

        $this->assertTrue($finished);
        $this->assertCount(5, $titles);
        $this->assertSame('row number 1', $titles[0]);
    }

    public function testEachStopsWhenTheCallbackReturnsFalse(): void
    {
        $this->fill(5);
        $seen = 0;

        $finished = ManticoreDb::table($this->table)->each(static function () use (&$seen) {
            $seen++;

            return false;
        }, 2);

        $this->assertFalse($finished);
        $this->assertSame(1, $seen);
    }

    public function testLazyAndCursorYieldEveryRow(): void
    {
        $this->fill(7);

        $this->assertSame(7, iterator_count(ManticoreDb::table($this->table)->lazy(2)));
        $this->assertSame(7, iterator_count(ManticoreDb::table($this->table)->cursor(3)));
    }

    public function testIncrementAndDecrement(): void
    {
        $this->fill();

        $affected = ManticoreDb::table($this->table)->where('qty', '<', 3)->increment('qty', 10);

        $this->assertSame(2, $affected);
        $this->assertSame([11, 12, 3], array_values(ManticoreDb::table($this->table)->orderBy('id')->pluck('qty')));

        ManticoreDb::table($this->table)->where('id', 3)->decrement('qty');

        $this->assertSame(2, ManticoreDb::table($this->table)->find(3)['qty']);
    }

    public function testIncrementWritesTheExtraColumnsToo(): void
    {
        $this->fill(1);

        ManticoreDb::table($this->table)->where('id', 1)->increment('qty', 5, ['price' => 99.0]);

        $row = ManticoreDb::table($this->table)->find(1);
        $this->assertSame(6, $row['qty']);
        $this->assertSame(99.0, $row['price']);
    }

    public function testUpdateOrInsertUpdatesAnExistingRow(): void
    {
        $this->fill();

        $this->assertTrue(ManticoreDb::table($this->table)->updateOrInsert(['manufacturer' => 'other'], ['qty' => 42]));
        $this->assertSame(42, ManticoreDb::table($this->table)->find(2)['qty']);
        $this->assertSame(3, ManticoreDb::table($this->table)->count());
    }

    public function testUpdateOrInsertInsertsWhenNothingMatched(): void
    {
        $this->fill();

        $this->assertTrue(ManticoreDb::table($this->table)->updateOrInsert(['manufacturer' => 'third'], ['qty' => 7]));

        $this->assertSame(4, ManticoreDb::table($this->table)->count());
        $this->assertSame(7, ManticoreDb::table($this->table)->where('manufacturer', 'third')->value('qty'));
    }

    public function testUpsertWritesBothNewAndExistingRows(): void
    {
        $this->fill(2);

        $affected = ManticoreDb::table($this->table)->upsert([
            ['id' => 1, 'title' => 'rewritten', 'qty' => 99],
            ['id' => 50, 'title' => 'brand new', 'manufacturer' => 'new', 'qty' => 5],
        ], 'id');

        $this->assertSame(2, $affected);
        $this->assertSame('rewritten', ManticoreDb::table($this->table)->find(1)['title']);
        $this->assertSame(99, ManticoreDb::table($this->table)->find(1)['qty']);
        // a column left out of the row keeps its value
        $this->assertSame('acme', ManticoreDb::table($this->table)->find(1)['manufacturer']);
        $this->assertSame('brand new', ManticoreDb::table($this->table)->find(50)['title']);
    }

    public function testUpsertWritesOnlyTheListedColumnsOfAnExistingRow(): void
    {
        $this->fill(2);

        ManticoreDb::table($this->table)->upsert([
            ['id' => 2, 'title' => 'not written', 'qty' => 7],
        ], 'id', ['qty']);

        $row = ManticoreDb::table($this->table)->find(2);
        $this->assertSame('row number 2', $row['title']);
        $this->assertSame(7, $row['qty']);
    }

    public function testUpsertThrowsWhenTheKeyColumnIsMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is missing in a row');

        ManticoreDb::table($this->table)->upsert([['title' => 'no id here']], 'id');
    }

    public function testInRandomOrderReturnsEveryRow(): void
    {
        $this->fill(5);

        $this->assertCount(5, ManticoreDb::table($this->table)->inRandomOrder()->get());
    }

    /**
     * A column of a computed expression comes back named "1", i.e. an int key - which the
     * type casting used to choke on
     */
    public function testAQueryWithoutColumnsOfATable(): void
    {
        $result = ManticoreDb::connection()->sql('SELECT 1')->exec();

        $this->assertTrue($result->success(), (string)$result->error());
    }

    public function testWhereRegexMatchesAStringAttribute(): void
    {
        $this->fill();

        $table = ManticoreDb::table($this->table);
        $this->assertSame(2, $table->clone()->whereRegex('manufacturer', '^acme$')->count());
        $this->assertSame(1, $table->clone()->whereNotRegex('manufacturer', '^acme$')->count());
    }

    /**
     * Unlike the LIKE of MySQL, REGEX is case sensitive - the inline flag is how the other
     * behaviour is asked for
     */
    public function testWhereRegexIsCaseSensitiveUnlessTold(): void
    {
        ManticoreDb::table($this->table)->insert(['id' => 10, 'manufacturer' => 'ACME', 'title' => 'shouty']);

        $table = ManticoreDb::table($this->table);
        $this->assertSame(0, $table->clone()->whereRegex('manufacturer', '^acme$')->count());
        $this->assertSame(1, $table->clone()->whereRegex('manufacturer', '(?i)^acme$')->count());
    }

    /**
     * REGEX reaches attributes only, and a rejected read throws rather than answering with
     * nothing - which is what tells the caller to use match() instead
     */
    public function testWhereRegexOverAFullTextFieldThrows(): void
    {
        $this->fill(1);

        $this->expectException(QueryErrorException::class);

        ManticoreDb::table($this->table)->whereRegex('title', 'row')->get();
    }

    public function testWhereMatchIsTheFullTextSearch(): void
    {
        $this->fill();

        $rows = ManticoreDb::table($this->table)->whereMatch('number')->get();

        $this->assertCount(3, $rows);
    }

    public function testMatchLimitedToAField(): void
    {
        $this->fill();
        ManticoreDb::table($this->table)->insert(['id' => 20, 'title' => 'plain row', 'manufacturer' => 'number']);

        // "number" is in the title of the filled rows and in the attribute of this one
        $this->assertCount(3, ManticoreDb::table($this->table)->match('number', 'title')->get());
    }

    /**
     * Text typed into a search box is not an expression: escapeMatch() makes every operator
     * of the query language a literal, so the search neither means something else nor fails
     */
    public function testEscapeMatchMakesUserInputSearchable(): void
    {
        $this->fill();

        foreach (['row -number', 'row | number', 'say "hi"', '@title row'] as $input) {
            $escaped = ManticoreDb::escapeMatch($input);
            // the point is that none of these throws or turns into another query
            $this->assertIsArray(ManticoreDb::table($this->table)->match($escaped)->get() ?: []);
        }
    }

    public function testUnescapedUserInputChangesTheQuery(): void
    {
        $this->fill();

        // "-" excludes, so the unescaped input finds nothing while the escaped one is a phrase
        $this->assertCount(0, ManticoreDb::table($this->table)->match('number -row')->get());
        $this->assertCount(3, ManticoreDb::table($this->table)->match(ManticoreDb::escapeMatch('number row'))->get());
    }

    /**
     * @return string a table of four rows with known timestamps
     */
    private function datesTable(): string
    {
        $table = $this->createTable(['title' => 'text', 'created_at' => 'timestamp'], 'dates');
        $rows = [];
        foreach (['2023-11-14 22:13:20', '2023-11-03 10:00:00', '2022-12-02 15:30:00', '2024-01-31 09:05:00'] as $i => $date) {
            $rows[] = ['id' => $i + 1, 'title' => 'row ' . ($i + 1), 'created_at' => strtotime($date . ' UTC')];
        }
        ManticoreDb::table($table)->insert($rows);

        return $table;
    }

    public function testWhereDateMatchesACalendarDay(): void
    {
        $table = $this->datesTable();

        $this->assertSame(['row 1'], array_values(ManticoreDb::table($table)->whereDate('created_at', '2023-11-14')->pluck('title')));
        $this->assertSame(2, ManticoreDb::table($table)->whereDate('created_at', '>=', '2023-11-14')->count());
        $this->assertSame(2, ManticoreDb::table($table)->whereDate('created_at', '<', '2023-11-14')->count());
    }

    public function testWhereYearMonthAndDay(): void
    {
        $table = $this->datesTable();

        $this->assertSame(2, ManticoreDb::table($table)->whereYear('created_at', 2023)->count());
        $this->assertSame(2, ManticoreDb::table($table)->whereMonth('created_at', 11)->count());
        $this->assertSame(['row 4'], array_values(ManticoreDb::table($table)->whereDay('created_at', 31)->pluck('title')));
    }

    public function testWhereTimeComparesTheTimeOfDay(): void
    {
        $table = $this->datesTable();

        $this->assertSame(2, ManticoreDb::table($table)->whereTime('created_at', '>=', '15:00')->count());
        $this->assertSame(['row 4'], array_values(ManticoreDb::table($table)->whereTime('created_at', '<', '10:00')->pluck('title')));
    }

    public function testDateConditionsCombine(): void
    {
        $table = $this->datesTable();

        $titles = ManticoreDb::table($table)
            ->whereYear('created_at', 2023)
            ->whereMonth('created_at', 11)
            ->whereDay('created_at', 3)
            ->pluck('title');

        $this->assertSame(['row 2'], array_values($titles));
    }

    /**
     * A function call is not allowed in WHERE, so the expression is selected under a name of
     * its own - which must not show up among the columns of the answer
     */
    public function testTheComputedColumnIsHiddenFromTheRows(): void
    {
        $table = $this->datesTable();

        $row = ManticoreDb::table($table)->whereMonth('created_at', 11)->orderBy('id')->first();

        $this->assertSame(['id', 'title', 'created_at'], array_keys($row));
    }

    public function testStatementAndSelectRunRawSql(): void
    {
        $this->fill();
        $connection = ManticoreDb::connection();

        $this->assertTrue($connection->statement('SELECT 1'));
        $this->assertFalse($connection->statement('SELECT * FROM no_such_table_here'));
        $this->assertSame(3, ManticoreDb::select('SELECT COUNT(*) as c FROM ' . $this->table)[0]['c']);
    }

    public function testTransactionCommitsAndRollsBack(): void
    {
        ManticoreDb::transaction(function ($connection) {
            $connection->table($this->table)->insert(['id' => 1, 'title' => 'kept']);
        });
        $this->assertSame(1, ManticoreDb::table($this->table)->count());

        try {
            ManticoreDb::transaction(function ($connection) {
                $connection->table($this->table)->insert(['id' => 2, 'title' => 'gone']);

                throw new \RuntimeException('nope');
            });
        }
        catch (\RuntimeException $e) {
            // the exception of the callback is rethrown
        }

        $this->assertSame(1, ManticoreDb::table($this->table)->count());
        $this->assertSame(0, ManticoreDb::connection()->transactionLevel());
    }

    public function testRawExpressionIsNotQuoted(): void
    {
        $this->fill();

        $this->assertSame(2, ManticoreDb::table($this->table)->where('qty', 'IN', ManticoreDb::raw('(1,2)'))->count());
    }

    /**
     * Manticore takes no expression as a written value, and casting one to a number would
     * write something else entirely
     */
    public function testRawExpressionCannotBeWritten(): void
    {
        $this->fill(1);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be written as a value');

        ManticoreDb::table($this->table)->where('id', 1)->update(['qty' => ManticoreDb::raw('qty + 1')]);
    }

    public function testWhereHelpersFilterTheRows(): void
    {
        $this->fill();

        $table = ManticoreDb::table($this->table);
        $this->assertSame(2, $table->clone()->whereNot('qty', 1)->count());
        $this->assertSame(2, $table->clone()->whereAny(['manufacturer'], 'acme')->count());
        // both columns above one: prices are 10, 20, 30 and quantities 1, 2, 3
        $this->assertSame(2, $table->clone()->whereAll(['price', 'qty'], '>', 1)->count());
        $this->assertSame(2, $table->clone()->whereNone(['qty'], 1)->count());
        $this->assertSame(2, $table->clone()->whereRaw('qty > 1')->count());
    }
}
