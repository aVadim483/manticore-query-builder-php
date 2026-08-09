<?php

namespace avadim\Manticore\Tests\Integration;

use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use avadim\Manticore\Tests\Support\IntegrationTestCase;

/**
 * Query errors do not escape as exceptions: they end up in ResultSet::error().
 * Connection-level problems, on the other hand, do throw.
 */
final class ErrorHandlingTest extends IntegrationTestCase
{
    public function testSyntaxErrorIsReportedThroughResultSet(): void
    {
        $result = ManticoreDb::sql('SELECT * FROM')->exec();

        $this->assertFalse($result->success());
        $this->assertSame('error', $result->status());
        $this->assertNotNull($result->error());
    }

    public function testSelectFromMissingTableReportsError(): void
    {
        $result = ManticoreDb::table($this->tableName('missing'))->search();

        $this->assertFalse($result->success());
        $this->assertStringContainsStringIgnoringCase('table', (string)$result->error());
    }

    public function testUnknownColumnReportsError(): void
    {
        $table = $this->createTable(['title' => 'text'], 'errors');
        // an empty table short-circuits the query before column resolution
        ManticoreDb::table($table)->insert(['title' => 'a']);

        $result = ManticoreDb::table($table)->where('nonexistent_column', 1)->search();

        $this->assertFalse($result->success());
        $this->assertStringContainsString('nonexistent_column', (string)$result->error());
    }

    public function testFailedQueryReturnsEmptyResultInsteadOfThrowing(): void
    {
        $result = ManticoreDb::table($this->tableName('missing'))->search();

        $this->assertNull($result->result());
        $this->assertSame(0, $result->count());
        $this->assertSame(0, $result->total());
        $this->assertSame([], $result->facets());
    }

    public function testGetOnFailedQueryReturnsNull(): void
    {
        $this->assertNull(ManticoreDb::table($this->tableName('missing'))->get());
    }

    public function testCountOnFailedQueryReturnsZero(): void
    {
        $this->assertSame(0, ManticoreDb::table($this->tableName('missing'))->count());
    }

    /**
     * Write commands ask for the column types (DESCRIBE) before their SQL is even built,
     * so an unknown table must not turn that into an exception.
     *
     * @dataProvider writeCommandProvider
     *
     * @param string $method
     * @param array $args
     */
    public function testWriteCommandOnMissingTableReportsErrorInsteadOfThrowing(string $method, array $args): void
    {
        $table = $this->tableName('missing');

        $result = ManticoreDb::table($table)->$method(...$args);

        $this->assertFalse($result->success());
        $this->assertStringContainsString($table, (string)$result->error());
    }

    /**
     * @return array<string, array{0: string, 1: array}>
     */
    public function writeCommandProvider(): array
    {
        return [
            'insert'  => ['insert', [['title' => 'x']]],
            'update'  => ['update', [['title' => 'x'], 1]],
            'replace' => ['replace', [['title' => 'x'], 1]],
        ];
    }

    /**
     * @dataProvider serviceCommandProvider
     *
     * @param string $method
     */
    public function testServiceCommandOnMissingTableReportsErrorInsteadOfThrowing(string $method): void
    {
        $result = ManticoreDb::table($this->tableName('missing'))->$method();

        $this->assertFalse($result->success());
        $this->assertNotNull($result->error());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function serviceCommandProvider(): array
    {
        return [
            'describe'   => ['describe'],
            'showCreate' => ['showCreate'],
            'optimize'   => ['optimize'],
        ];
    }

    public function testDescribeOfMissingTableReturnsEmptyResult(): void
    {
        $query = ManticoreDb::connection()->table($this->tableName('missing'));

        $this->assertSame([], $query->describe()->result());
        $this->assertSame([], $query->columnTypes());
    }

    public function testOptimizeOfMissingTableReturnsFalse(): void
    {
        $result = ManticoreDb::table($this->tableName('missing'))->optimize();

        $this->assertFalse($result->result());
    }

    public function testDuplicateCreateReportsError(): void
    {
        $table = $this->createTable(['title' => 'text'], 'duplicate');

        $result = ManticoreDb::table($table)->create(['title' => 'text']);

        $this->assertFalse($result->success());
        $this->assertStringContainsStringIgnoringCase('already exists', (string)$result->error());
    }

    public function testUnknownConnectionThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('was not defined in the config');

        ManticoreDb::connection('there_is_no_such_connection');
    }

    public function testConnectionToClosedPortThrows(): void
    {
        ManticoreDb::init([
            'defaultConnection' => 'broken',
            'connections' => [
                'broken' => [
                    'host' => self::host(),
                    // a port nothing is listening on
                    'port' => 9399,
                    'timeout' => 1,
                ],
            ],
        ]);

        $this->expectException(\Throwable::class);

        ManticoreDb::table('any')->search();
    }

    /**
     * lastResultSet() is filled by the service helpers of Connection (create, showTables,
     * describe, ...), not by queries built through table()->...
     */
    public function testLastResultSetKeepsTheServiceResult(): void
    {
        $connection = ManticoreDb::connection();
        $this->assertNull($connection->lastResultSet());

        $table = $this->createTable(['title' => 'text'], 'lastresult');
        $connection->showTables($table);

        $last = $connection->lastResultSet();
        $this->assertNotNull($last);
        $this->assertTrue($last->success());
    }
}
