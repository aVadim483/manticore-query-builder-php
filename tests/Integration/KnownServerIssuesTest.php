<?php

namespace avadim\Manticore\Tests\Integration;

use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use avadim\Manticore\Tests\Support\IntegrationTestCase;

/**
 * Defects that only show up against a real server.
 *
 * Like KnownIssuesTest, every test states the promised behaviour and is marked incomplete,
 * so that the suite stays green while the bug is still there. Remove the markTestIncomplete()
 * line once it is fixed.
 */
final class KnownServerIssuesTest extends IntegrationTestCase
{
    /**
     * DESCRIBE reports "mva64" for multi64 columns, but Query::_castResult() only knows
     * "multi", "multi64" and "mva", so multi64 values arrive as a comma-separated string.
     */
    public function testMulti64IsCastToIntArray(): void
    {
        $this->markTestIncomplete('multi64 columns are read as a string: "mva64" is missing from _castResult()');

        $table = $this->createTable(['title' => 'text', 'values' => 'multi64'], 'mva64');
        $id = ManticoreDb::table($table)->insert(['title' => 'x', 'values' => [1, 2, 3]])->result();

        $rows = ManticoreDb::table($table)->where('id', $id)->get();

        $this->assertSame([1, 2, 3], reset($rows)['values']);
    }

    /**
     * _castResult() explodes the raw value by comma, and explode(',', '') returns [''],
     * so an empty multi-value attribute becomes [0] instead of an empty array.
     */
    public function testEmptyMultiIsReadAsEmptyArray(): void
    {
        $this->markTestIncomplete('an empty multi column is read as [0] instead of []');

        $table = $this->createTable(['title' => 'text', 'categories' => 'multi'], 'emptymva');
        $id = ManticoreDb::table($table)->insert(['title' => 'x', 'categories' => []])->result();

        $rows = ManticoreDb::table($table)->where('id', $id)->get();

        $this->assertSame([], reset($rows)['categories']);
    }

    /**
     * Connection::showVariables() is typed ": array" but returns ResultSet::result(), which is
     * boolean true when the server answers with an empty set - a TypeError instead of an
     * empty array.
     */
    public function testShowVariablesWithPatternReturnsArray(): void
    {
        $this->markTestIncomplete('showVariables() with a pattern that matches nothing throws a TypeError');

        $this->assertIsArray(ManticoreDb::showVariables('%no_such_variable%'));
    }
}
