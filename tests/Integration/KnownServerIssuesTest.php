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
     * _execQuery() builds the "Table" and "Name" columns of SHOW TABLES (the latter maps the
     * prefix back to "?name") inside "if ($key === 'Index')". Current Manticore versions return
     * a "Table" column instead of "Index", so neither is produced any more.
     */
    public function testShowTablesMapsPrefixBackToPlaceholder(): void
    {
        $this->markTestIncomplete('SHOW TABLES no longer returns an "Index" column, so the "Name" mapping is skipped');

        $config = $this->config();
        $prefix = 'issue_' . str_replace('.', '', uniqid('', true)) . '_';
        $config['connections'][self::CONNECTION_1]['prefix'] = $prefix;
        ManticoreDb::init($config);
        $this->registerTable('?products');

        ManticoreDb::table('?products')->create(['title' => 'text']);

        $row = ManticoreDb::showTables('?%')[0];
        $this->assertSame($prefix . 'products', $row['Table']);
        $this->assertSame('?products', $row['Name']);
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
