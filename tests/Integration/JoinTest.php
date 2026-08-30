<?php

namespace avadim\Manticore\Tests\Integration;

use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use avadim\Manticore\Tests\Support\IntegrationTestCase;

/**
 * JOIN against a live server.
 *
 * Manticore joins two tables without aliases and without subqueries, and hands the columns of
 * the joined table back under "<table>.<column>" - which is what keeps two columns of the same
 * name apart, and what the type casting has to follow.
 */
final class JoinTest extends IntegrationTestCase
{
    /** @var string */
    private string $products;

    /** @var string */
    private string $groups;

    protected function setUp(): void
    {
        parent::setUp();

        $this->products = $this->createTable([
            'title' => 'text',
            'gid' => 'integer',
            'price' => 'float',
        ], 'products');
        $this->groups = $this->createTable([
            'title' => 'text',
            'tags' => 'multi',
            'active' => 'bool',
        ], 'groups');

        ManticoreDb::table($this->products)->insert([
            ['id' => 1, 'title' => 'laptop', 'gid' => 1, 'price' => 999.0],
            ['id' => 2, 'title' => 'mouse', 'gid' => 2, 'price' => 19.0],
            ['id' => 3, 'title' => 'orphan', 'gid' => 99, 'price' => 1.0],
        ]);
        ManticoreDb::table($this->groups)->insert([
            ['id' => 1, 'title' => 'computers', 'tags' => [5, 6], 'active' => true],
            ['id' => 2, 'title' => 'accessories', 'tags' => [7], 'active' => false],
        ]);
    }

    /**
     * @return \avadim\Manticore\QueryBuilder\Query
     */
    private function joined()
    {
        return ManticoreDb::table($this->products)->join($this->groups, 'gid', 'id');
    }

    public function testInnerJoinKeepsOnlyMatchingRows(): void
    {
        $rows = $this->joined()->get();

        // the third product points at a group that is not there
        $this->assertCount(2, $rows);
    }

    public function testLeftJoinKeepsTheRowsOfTheLeftTable(): void
    {
        $rows = ManticoreDb::table($this->products)->leftJoin($this->groups, 'gid', 'id')->get();

        $this->assertCount(3, $rows);
    }

    public function testColumnsOfTheJoinedTableComeBackPrefixed(): void
    {
        $row = $this->joined()->orderBy('id')->first();

        $this->assertSame('laptop', $row['title']);
        $this->assertSame('computers', $row[$this->groups . '.title']);
    }

    /**
     * The types of the joined table are its own, so the schema of both tables has to be asked
     * for - otherwise an MVA of the joined table comes back as the string "5,6"
     */
    public function testValuesOfTheJoinedTableAreCastByItsOwnSchema(): void
    {
        $row = $this->joined()->orderBy('id')->first();

        $this->assertSame([5, 6], $row[$this->groups . '.tags']);
        $this->assertTrue($row[$this->groups . '.active']);
        $this->assertIsInt($row[$this->groups . '.id']);
    }

    public function testConditionsAndOrderingWorkOverAJoin(): void
    {
        $this->assertCount(1, $this->joined()->where('price', '>', 100)->get());
        $this->assertSame(2, $this->joined()->count());
        $this->assertSame('mouse', $this->joined()->orderBy('price')->first()['title']);
    }

    /**
     * An alias is how a joined column gets a name of its own, as in the Laravel query builder
     */
    public function testJoinedColumnsCanBeAliased(): void
    {
        $row = $this->joined()
            ->select(['title', $this->groups . '.title as group_title'])
            ->orderBy('id')
            ->first();

        $this->assertSame(['title' => 'laptop', 'group_title' => 'computers'], $row);
    }

    public function testTheEqualityOperatorMayBeWrittenOut(): void
    {
        $rows = ManticoreDb::table($this->products)->join($this->groups, 'gid', '=', 'id')->get();

        $this->assertCount(2, $rows);
    }

    /**
     * Manticore joins on equality only - saying so beats a syntax error from the server
     */
    public function testAnotherOperatorIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('can only compare with "="');

        ManticoreDb::table($this->products)->join($this->groups, 'gid', '>=', 'id');
    }

    /**
     * The table of a joined column may be written with the placeholder, with the real name or
     * with the bare one - all three mean the same table
     */
    public function testTheTableOfAJoinedColumnCanBeWrittenInAnyForm(): void
    {
        $expected = $this->joined()->toSql();

        $this->assertSame(
            $expected,
            ManticoreDb::table($this->products)->join($this->groups, $this->products . '.gid', $this->groups . '.id')->toSql()
        );
    }

    public function testFullTextSearchWorksOverAJoin(): void
    {
        $rows = $this->joined()->match('laptop')->get();

        $this->assertCount(1, $rows);
    }

    /**
     * A join gives one row per pair, so the id of the left table repeats itself as soon as the
     * relation is one to many - and the rows must not be keyed by it, or all but the last one
     * of every document would be dropped
     */
    public function testEveryRowOfAOneToManyJoinIsKept(): void
    {
        $tags = $this->createTable([
            'gid' => 'integer',
            'label' => 'text',
        ], 'tags');
        ManticoreDb::table($tags)->insert([
            ['id' => 11, 'gid' => 1, 'label' => 'portable'],
            ['id' => 12, 'gid' => 1, 'label' => 'sale'],
            ['id' => 13, 'gid' => 1, 'label' => 'new'],
        ]);

        $rows = ManticoreDb::table($this->products)
            ->join($tags, 'gid', 'gid')
            ->where('id', 1)
            ->get();

        $this->assertCount(3, $rows);
        $labels = array_column($rows, $tags . '.label');
        sort($labels);
        $this->assertSame(['new', 'portable', 'sale'], $labels);
    }
}
