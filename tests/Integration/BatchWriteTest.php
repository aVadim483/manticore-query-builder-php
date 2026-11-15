<?php

namespace avadim\Manticore\Tests\Integration;

use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use avadim\Manticore\Tests\Support\IntegrationTestCase;

final class BatchWriteTest extends IntegrationTestCase
{
    public function testGeneratorInsertionAndReplacementAcrossSeveralBatches(): void
    {
        $table = $this->createProductsTable('batch');
        $rows = (static function () {
            for ($i = 1; $i <= 5; ++$i) {
                yield ['id' => $i, 'title' => "Книга '" . $i, 'info' => ['n' => $i], 'categories' => [$i, 10]];
            }
        })();
        $result = ManticoreDb::table($table)->insertBatch($rows, ['max_rows' => 2, 'collect_ids' => true]);

        $this->assertTrue($result->success(), $result->error() ? $result->error()->getMessage() : (string)$result->idError());
        $this->assertSame(5, $result->confirmedRows());
        $this->assertSame(3, $result->completedBatches());
        $this->assertSame([1, 2, 3, 4, 5], $result->ids());
        $this->assertSame(5, ManticoreDb::table($table)->count());
        $row = ManticoreDb::table($table)->find(3);
        $this->assertSame("Книга '3", $row['title']);
        $this->assertSame(['n' => 3], $row['info']);
        $this->assertSame([3, 10], $row['categories']);

        $query = ManticoreDb::table($table);
        $replace = $query->replaceBatch([
            ['id' => 1, 'title' => 'новое'], ['id' => 2, 'title' => 'другое'],
        ], ['max_rows' => 1]);
        $this->assertTrue($replace->success());
        $this->assertSame(2, $replace->confirmedRows());
        $this->assertSame('REPLACE', ManticoreDb::lastResultSet()->command());
        $this->assertTrue($query->lastResultSet()->result());
        $this->assertSame('новое', ManticoreDb::table($table)->find(1)['title']);
    }

    public function testGeneratedIdsAreReadBeforeCallbackWritesOnSameConnection(): void
    {
        $table = $this->createTable(['title' => 'text'], 'batch_ids');
        $received = [];
        $result = ManticoreDb::table($table)->insertBatch([
            ['title' => 'first'], ['title' => 'second'],
        ], [
            'max_rows' => 1,
            'on_ids' => static function (array $ids, int $batch) use ($table, &$received) {
                $received[$batch] = $ids[0];
                ManticoreDb::table($table)->insert(['title' => 'callback']);
            },
        ]);

        $this->assertTrue($result->success());
        $this->assertSame([], $result->ids());
        $this->assertCount(2, $received);
        $this->assertSame('first', ManticoreDb::table($table)->find($received[0])['title']);
        $this->assertSame('second', ManticoreDb::table($table)->find($received[1])['title']);
        $this->assertSame(4, ManticoreDb::table($table)->count());
    }

    public function testInputFailureAfterCommittedBatchReportsPartialSuccess(): void
    {
        $table = $this->createTable(['title' => 'text'], 'batch_partial');
        $result = ManticoreDb::table($table)->insertBatch([
            ['id' => 1, 'title' => 'saved'], ['id' => 2, 'title' => str_repeat('Ж', 500)],
        ], ['max_rows' => 1, 'max_bytes' => 300]);

        $this->assertFalse($result->success());
        $this->assertSame(1, $result->confirmedRows());
        $this->assertSame(1, $result->failedPosition());
        $this->assertFalse($result->outcomeUnknown());
        $this->assertSame(1, ManticoreDb::table($table)->count());
    }
}
