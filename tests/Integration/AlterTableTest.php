<?php

namespace avadim\Manticore\Tests\Integration;

use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use avadim\Manticore\Tests\Support\IntegrationTestCase;

/**
 * The imperative ALTER statements against a live server.
 */
final class AlterTableTest extends IntegrationTestCase
{
    public function testAddColumnAppearsInDescribe(): void
    {
        $table = $this->createTable(['title' => 'text', 'price' => 'float']);

        $result = ManticoreDb::table($table)->addColumn('group_id', 'integer');
        $this->assertTrue($result->success(), (string)$result->error());

        $columns = ManticoreDb::tableDescribe($table);
        $this->assertArrayHasKey('group_id', $columns);
        $this->assertSame('uint', $columns['group_id']['Type']);
    }

    public function testAddColumnOfAFullTextField(): void
    {
        $table = $this->createTable(['price' => 'float']);

        $result = ManticoreDb::table($table)->addColumn('title', 'text indexed stored');
        $this->assertTrue($result->success(), (string)$result->error());

        ManticoreDb::table($table)->insert(['title' => 'brown fox', 'price' => 1.5]);
        $found = ManticoreDb::table($table)->match('brown')->get();
        $this->assertCount(1, $found);
    }

    /**
     * The cached DESCRIBE of the table must not survive an ALTER: the new column is cast by its
     * real type only if the schema was asked for again.
     */
    public function testAddColumnForgetsTheCachedSchema(): void
    {
        $table = $this->createTable(['title' => 'text']);
        // fill the schema cache of the connection before the table changes
        ManticoreDb::table($table)->insert(['title' => 'first']);

        $result = ManticoreDb::table($table)->addColumn('qty', 'integer');
        $this->assertTrue($result->success(), (string)$result->error());

        ManticoreDb::table($table)->insert(['title' => 'second', 'qty' => 42]);
        $row = ManticoreDb::table($table)->select('id, qty')->where('qty', 42)->first();
        $this->assertSame(42, $row['qty']);
    }

    public function testDropColumn(): void
    {
        $table = $this->createTable(['title' => 'text', 'price' => 'float', 'qty' => 'integer']);

        $result = ManticoreDb::table($table)->dropColumn(['price', 'qty']);
        $this->assertTrue($result->success(), (string)$result->error());

        $columns = ManticoreDb::tableDescribe($table);
        $this->assertArrayNotHasKey('price', $columns);
        $this->assertArrayNotHasKey('qty', $columns);
        $this->assertArrayHasKey('title', $columns);
    }

    public function testModifyColumnWidensIntToBigint(): void
    {
        $table = $this->createTable(['title' => 'text', 'group_id' => 'integer']);

        $result = ManticoreDb::table($table)->modifyColumn('group_id', 'bigint');
        $this->assertTrue($result->success(), (string)$result->error());

        $columns = ManticoreDb::tableDescribe($table);
        $this->assertSame('bigint', $columns['group_id']['Type']);
    }

    /**
     * A statement the server rejects lands in error(), it is not thrown
     */
    public function testFailedAlterIsReportedInTheResult(): void
    {
        $table = $this->createTable(['title' => 'text']);

        $result = ManticoreDb::table($table)->dropColumn('nonexistent');

        $this->assertFalse($result->success());
        $this->assertNotEmpty($result->error());
        $this->assertSame('error', $result->status());
    }

    /**
     * The chain stops at the first failing statement, and reports the queries that ran
     */
    public function testChainStopsAtTheFirstError(): void
    {
        $table = $this->createTable(['title' => 'text', 'price' => 'float']);

        $result = ManticoreDb::table($table)->dropColumn(['nonexistent', 'price']);

        $this->assertFalse($result->success());
        $this->assertStringNotContainsString('DROP COLUMN price', (string)$result->sqlQuery());
        // the column of the step that was never reached is still there
        $this->assertArrayHasKey('price', ManticoreDb::tableDescribe($table));
    }

    public function testAlterSettings(): void
    {
        $table = $this->createTable(['title' => 'text']);

        $result = ManticoreDb::table($table)->alterSettings(['html_strip' => 1]);
        $this->assertTrue($result->success(), (string)$result->error());

        $settings = ManticoreDb::tableSettings($table);
        $this->assertSame(1, $settings['html_strip'] ?? null);
    }

    public function testRename(): void
    {
        $table = $this->createTable(['title' => 'text']);
        $newName = $this->tableName('renamed');

        $result = ManticoreDb::table($table)->rename($newName);
        if (!$result->success()) {
            // renaming is served by Manticore Buddy: without it the daemon reads the statement
            // as "ALTER TABLE t setting='value'" and answers "unexpected tablename, expecting '='"
            $this->markTestSkipped('RENAME needs Manticore Buddy, the server answered: ' . $result->error());
        }

        $this->assertTrue(ManticoreDb::hasTable($newName));
        $this->assertFalse(ManticoreDb::hasTable($table));
    }
}
