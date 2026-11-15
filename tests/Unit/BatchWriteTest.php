<?php

namespace avadim\Manticore\Tests\Unit;

use avadim\Manticore\QueryBuilder\BatchResult;
use avadim\Manticore\QueryBuilder\Query;
use avadim\Manticore\Tests\Support\FakeClient;
use avadim\Manticore\Tests\Support\TestLogger;
use avadim\Manticore\Tests\Support\UnitTestCase;

final class BatchWriteTest extends UnitTestCase
{
    private function client(): BatchTestClient
    {
        return new BatchTestClient($this->productColumnTypes());
    }

    public function testOneShotGeneratorIsWrittenInBoundedBatchesWithoutReadingIds(): void
    {
        $client = $this->client();
        $yielded = 0;
        $rows = (static function () use (&$yielded, $client) {
            for ($i = 1; $i <= 5; ++$i) {
                if ($i === 3) {
                    self::assertCount(1, $client->writes, 'First batch must be sent before the whole source is read');
                }
                ++$yielded;
                yield 'key-' . $i => ['id' => $i, 'title' => 'row ' . $i];
            }
        })();
        $result = $this->queryFor($client)->insertBatch($rows, ['max_rows' => 2]);

        $this->assertInstanceOf(BatchResult::class, $result);
        $this->assertTrue($result->success());
        $this->assertSame(5, $result->confirmedRows());
        $this->assertSame(3, $result->completedBatches());
        $this->assertSame(5, $yielded);
        $this->assertSame(0, $client->idReads);
        $this->assertSame([], $result->ids());
        $this->assertSame(0, $client->legacyInserts);
    }

    public function testEmptyInputIssuesNoCommandsAndKeepsLastResult(): void
    {
        $client = $this->client();
        $query = $this->queryFor($client);
        $query->insert(['title' => 'previous']);
        $last = $query->lastResultSet();
        $count = count($client->queries);

        $result = $query->insertBatch([]);

        $this->assertTrue($result->success());
        $this->assertSame(0, $result->confirmedRows());
        $this->assertSame(0, $result->completedBatches());
        $this->assertSame($count, count($client->queries));
        $this->assertSame($last, $query->lastResultSet());
    }

    public function testByteBudgetIncludesUtf8EscapingHeaderAndSeparators(): void
    {
        $client = $this->client();
        $one = "INSERT INTO products(title) VALUES ('" . addslashes("Ж'\\") . "')";
        $budget = strlen($one);
        $result = $this->queryFor($client)->insertBatch([
            ['title' => "Ж'\\"], ['title' => "Ж'\\"],
        ], ['max_rows' => 50, 'max_bytes' => $budget]);

        $this->assertTrue($result->success());
        $this->assertSame(2, $result->completedBatches());
        foreach ($client->writes as $sql) {
            $this->assertSame($one, $sql);
            $this->assertSame($budget, strlen($sql));
        }
    }

    public function testOversizedDocumentIsNotSentAndPreviousSuccessRemainsVisible(): void
    {
        $client = $this->client();
        $query = $this->queryFor($client);
        $result = $query->insertBatch([
            ['title' => 'small'], ['title' => str_repeat('Ж', 100)], ['title' => 'never'],
        ], ['max_rows' => 1, 'max_bytes' => 60]);

        $this->assertFalse($result->success());
        $this->assertInstanceOf(\LengthException::class, $result->error());
        $this->assertSame(1, $result->confirmedRows());
        $this->assertSame(1, $result->failedPosition());
        $this->assertNull($result->failedBatch());
        $this->assertFalse($result->outcomeUnknown());
        $this->assertCount(1, $client->writes);
        $this->assertTrue($query->lastResultSet()->success());
    }

    public function testSourceExceptionDoesNotFlushBufferedRows(): void
    {
        $client = $this->client();
        $failure = new \RuntimeException('source failed');
        $rows = (static function () use ($failure) {
            yield ['title' => 'acknowledged'];
            yield ['title' => 'acknowledged too'];
            yield ['title' => 'buffered'];
            throw $failure;
        })();
        $result = $this->queryFor($client)->insertBatch($rows, ['max_rows' => 2]);

        $this->assertSame($failure, $result->error());
        $this->assertSame(2, $result->confirmedRows());
        $this->assertSame(3, $result->failedPosition());
        $this->assertSame(1, $result->completedBatches());
        $this->assertCount(1, $client->writes);
        $this->assertFalse($result->outcomeUnknown());
    }

    public function testExecutionFailureStopsSourceAndMarksUnknownOutcome(): void
    {
        $client = $this->client();
        $client->failWrite = 2;
        $yielded = 0;
        $rows = (static function () use (&$yielded) {
            for ($i = 0; $i < 10; ++$i) {
                ++$yielded;
                yield ['title' => 'row'];
            }
        })();
        $query = $this->queryFor($client);
        $result = $query->insertBatch($rows, ['max_rows' => 2]);

        $this->assertFalse($result->success());
        $this->assertSame(2, $result->confirmedRows());
        $this->assertSame(1, $result->completedBatches());
        $this->assertSame(2, $result->failedPosition());
        $this->assertSame(['start' => 2, 'end' => 3], $result->failedBatch());
        $this->assertTrue($result->outcomeUnknown());
        $this->assertSame(4, $yielded);
        $this->assertFalse($query->lastResultSet()->success());
    }

    public function testExplicitSqlRejectionIsKnown(): void
    {
        $client = $this->client();
        $client->failWrite = 1;
        $client->writeError = new \PDOException('Unknown column');
        $client->writeError->errorInfo = ['42000', 1064, 'Unknown column'];
        $result = $this->queryFor($client)->insertBatch([['title' => 'row']]);

        $this->assertFalse($result->success());
        $this->assertFalse($result->outcomeUnknown());
        $this->assertSame(0, $result->confirmedRows());
    }

    public function testSqlTextCannotForgeAKnownRejectionAfterConnectionLoss(): void
    {
        $client = $this->client();
        $client->failWrite = 1;
        $client->writeError = new \RuntimeException("SQL: INSERT INTO products VALUES ('Error [42000]')\nError [HY000] Lost connection after send");
        $result = $this->queryFor($client)->insertBatch([['title' => 'Error [42000]']]);

        $this->assertFalse($result->success());
        $this->assertTrue($result->outcomeUnknown());
        $this->assertSame(0, $result->confirmedRows());
    }

    public function testColumnOrderIsNormalizedButMissingColumnsAreRejected(): void
    {
        $client = $this->client();
        $result = $this->queryFor($client)->replaceBatch([
            ['id' => 1, 'title' => 'a'], ['title' => 'b', 'id' => 2], ['id' => 3],
        ], ['max_rows' => 2]);

        $this->assertFalse($result->success());
        $this->assertSame(2, $result->confirmedRows());
        $this->assertSame(2, $result->failedPosition());
        $this->assertSqlSame("REPLACE INTO products(id,title) VALUES (1,'a'),(2,'b')", $client->writes[0]);
    }

    public function testIdCollectionAndCallbackReadIdsBeforeConsumerCanWrite(): void
    {
        $client = $this->client();
        $client->idResponses = ['17,18', '18446744073709551615'];
        $seen = [];
        $query = $this->queryFor($client);
        $result = $query->insertBatch([
            ['title' => 'a'], ['title' => 'b'], ['title' => 'c'],
        ], [
            'max_rows' => 2, 'collect_ids' => true,
            'on_ids' => static function (array $ids, int $batch) use (&$seen) { $seen[$batch] = $ids; },
        ]);

        $this->assertTrue($result->success());
        $this->assertSame([17, 18, '18446744073709551615'], $result->ids());
        $this->assertSame([[17, 18], ['18446744073709551615']], $seen);
        $this->assertSame(2, $client->idReads);
        $this->assertSame('18446744073709551615', $query->lastResultSet()->result());
        $this->assertStringStartsWith('INSERT INTO', $query->lastResultSet()->sqlQuery());
    }

    public function testCallbackOnlyDoesNotRetainAllIds(): void
    {
        $client = $this->client();
        $client->idResponses = ['1', '2'];
        $seen = [];
        $result = $this->queryFor($client)->insertBatch([['title' => 'a'], ['title' => 'b']], [
            'max_rows' => 1,
            'on_ids' => static function (array $ids) use (&$seen) { $seen[] = $ids; },
        ]);

        $this->assertTrue($result->success());
        $this->assertSame([], $result->ids());
        $this->assertSame([[1], [2]], $seen);
    }

    public function testIdFailureDoesNotUndoAcknowledgedWrite(): void
    {
        $client = $this->client();
        $client->failIds = true;
        $query = $this->queryFor($client);
        $result = $query->insertBatch([['title' => 'a'], ['title' => 'b']], [
            'max_rows' => 1, 'collect_ids' => true,
        ]);

        $this->assertFalse($result->success());
        $this->assertNull($result->error());
        $this->assertInstanceOf(\RuntimeException::class, $result->idError());
        $this->assertSame(1, $result->confirmedRows());
        $this->assertSame(1, $result->completedBatches());
        $this->assertFalse($result->outcomeUnknown());
        $this->assertCount(1, $client->writes);
        $this->assertTrue($query->lastResultSet()->success());
        $this->assertTrue($query->lastResultSet()->result());
    }

    public function testWrongIdCountIsReportedSeparatelyFromWriteSuccess(): void
    {
        $client = $this->client();
        $client->idResponses = ['1'];
        $result = $this->queryFor($client)->insertBatch([['title' => 'a'], ['title' => 'b']], ['collect_ids' => true]);

        $this->assertFalse($result->success());
        $this->assertSame(2, $result->confirmedRows());
        $this->assertInstanceOf(\UnexpectedValueException::class, $result->idError());
        $this->assertNull($result->error());
    }

    public function testLoggingRunsAfterIdsAndBeforeCallback(): void
    {
        $client = $this->client();
        $logger = new class($client) extends TestLogger {
            private BatchTestClient $client;

            public function __construct(BatchTestClient $client)
            {
                $this->client = $client;
            }

            public function log($level, $message, array $context = []): void
            {
                BatchWriteTest::assertSame(1, $this->client->idReads);
                parent::log($level, $message, $context);
            }
        };
        $result = $this->queryFor($client)->setLogger($logger)->insertBatch([['title' => 'a']], [
            'on_ids' => static function () use ($logger) {
                self::assertSame(['info', 'debug'], $logger->levels());
            },
        ]);

        $this->assertTrue($result->success());
    }

    public function testLoggerFailureDoesNotMakeAcknowledgedWriteUnknown(): void
    {
        $client = $this->client();
        $logger = new class extends TestLogger {
            public function log($level, $message, array $context = []): void
            {
                throw new \RuntimeException('logger failed');
            }
        };
        $query = $this->queryFor($client)->setLogger($logger);
        $result = $query->insertBatch([['title' => 'a'], ['title' => 'b']], ['max_rows' => 1]);

        $this->assertFalse($result->success());
        $this->assertSame('logger failed', $result->error()->getMessage());
        $this->assertSame(1, $result->confirmedRows());
        $this->assertFalse($result->outcomeUnknown());
        $this->assertTrue($query->lastResultSet()->success());
        $this->assertCount(1, $client->writes);
    }

    public function testConsumerFailureStopsWithAlreadyConfirmedRowsAndIds(): void
    {
        $client = $this->client();
        $failure = new \RuntimeException('consumer failed');
        $result = $this->queryFor($client)->insertBatch([['title' => 'a'], ['title' => 'b']], [
            'max_rows' => 1, 'collect_ids' => true,
            'on_ids' => static function () use ($failure) { throw $failure; },
        ]);

        $this->assertSame($failure, $result->error());
        $this->assertSame(1, $result->confirmedRows());
        $this->assertSame([1], $result->ids());
        $this->assertFalse($result->outcomeUnknown());
    }

    public function testStructuredValuesUseExistingSchemaFormattingAndSchemaCache(): void
    {
        $client = $this->client();
        $query = $this->queryFor($client, '?products', ['prefix' => 'pre_']);
        $row = ['title' => "O'Neill", 'info' => ['цвет' => 'red'], 'categories' => [1, 3], 'price' => 2.5, 'on_sale' => true];
        $this->assertTrue($query->insertBatch([$row], ['max_rows' => 1])->success());
        $this->assertTrue($query->replaceBatch([$row], ['max_rows' => 1])->success());

        $this->assertStringStartsWith('INSERT INTO pre_products', $client->writes[0]);
        $this->assertStringContainsString('(1,3),2.5,1)', $client->writes[0]);
        $this->assertStringContainsString('цвет', $client->writes[0]);
        $describes = array_filter($client->queries, static function (string $sql) { return strpos($sql, 'DESCRIBE ') === 0; });
        $this->assertCount(1, $describes);
    }

    public function testSharedLastResultSlotAndSourceQueryStateArePreserved(): void
    {
        $slot = [];
        $query = $this->queryFor($this->client());
        $query->setResultSlot($slot);
        $before = $query->toSql();
        $this->assertTrue($query->insertBatch([['title' => 'a']])->success());

        $this->assertSame($query->lastResultSet(), $slot['last']);
        $this->assertSame('INSERT', $slot['last']->command());
        $this->assertSame($before, $query->toSql());
    }

    /** @dataProvider invalidOptions */
    public function testInvalidOptionsAreRejectedBeforeIterating(array $options): void
    {
        $rows = (static function () { throw new \RuntimeException('must not iterate'); yield []; })();
        $this->expectException(\InvalidArgumentException::class);
        $this->queryFor($this->client())->insertBatch($rows, $options);
    }

    public function invalidOptions(): array
    {
        return [
            [['max_rows' => 0]], [['max_rows' => '2']], [['max_bytes' => 0]],
            [['max_rows' => null]], [['collect_ids' => 1]], [['on_ids' => 'no_such_function']],
            [['unknown' => true]],
        ];
    }

    public function testReadClausesAndBindingsCannotBeSilentlyIgnored(): void
    {
        $this->expectException(\LogicException::class);
        $this->queryFor($this->client())->where('id', 1)->insertBatch([['title' => 'a']]);
    }

    public function testRawExpressionsAreRejectedWithoutAnyWrite(): void
    {
        $client = $this->client();
        $result = $this->queryFor($client)->insertBatch([['title' => Query::param('title')]]);

        $this->assertFalse($result->success());
        $this->assertInstanceOf(\InvalidArgumentException::class, $result->error());
        $this->assertCount(0, $client->writes);
    }

    public function testNonFiniteAndInvalidJsonValuesAreRejected(): void
    {
        $client = $this->client();
        $query = $this->queryFor($client);
        $this->assertFalse($query->insertBatch([['price' => INF]])->success());
        $this->assertFalse($query->insertBatch([['info' => ['bad' => "\xFF"]]])->success());
        $this->assertCount(0, $client->writes);
    }
}

/** A legacy client with no special batch methods. */
final class BatchTestClient extends FakeClient
{
    public array $writes = [];
    public array $idResponses = [];
    public int $idReads = 0;
    public int $legacyInserts = 0;
    public ?int $failWrite = null;
    public ?\Throwable $writeError = null;
    public bool $failIds = false;

    public function query(string $query, ?array $params = []): array
    {
        $response = parent::query($query, $params);
        if (preg_match('/^(INSERT|REPLACE) INTO /', $query)) {
            $this->writes[] = $query;
            if (count($this->writes) === $this->failWrite) {
                throw $this->writeError ?? new \RuntimeException('connection lost after send');
            }

            return ['data' => [], 'count' => 777];
        }
        if ($query === 'SELECT LAST_INSERT_ID()') {
            ++$this->idReads;
            if ($this->failIds) {
                throw new \RuntimeException('ID query failed');
            }

            return ['data' => [['LAST_INSERT_ID()' => array_shift($this->idResponses) ?? '1']]];
        }

        return $response;
    }

    public function insert(string $query, ?array $params = []): array
    {
        ++$this->legacyInserts;

        return parent::insert($query, $params);
    }
}
