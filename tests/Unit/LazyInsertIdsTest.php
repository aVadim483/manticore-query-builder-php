<?php

namespace avadim\Manticore\Tests\Unit;

use avadim\Manticore\QueryBuilder\Client\PDOClient;
use avadim\Manticore\QueryBuilder\Query;
use avadim\Manticore\QueryBuilder\ResultSet;
use avadim\Manticore\Tests\Support\FakeClient;
use PHPUnit\Framework\TestCase;

final class LazyInsertIdsTest extends TestCase
{
    private array $commands = [];
    private string $ids = '7,8';
    private bool $failId = false;
    private bool $failWrite = false;

    /** Real client code over a mocked PDO: counts actual SQL, including ID retrieval. */
    private function client(): PDOClient
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function ($sql) {
            $this->commands[] = $sql;
            $statement = $this->createMock(\PDOStatement::class);
            $statement->method('execute')->willReturnCallback(function () {
                if ($this->failWrite) {
                    throw new \RuntimeException('Write rejected');
                }
                return true;
            });
            $statement->method('fetchAll')->willReturn(strpos($sql, 'DESCRIBE') === 0
                ? [['Field' => 'title', 'Type' => 'text'], ['Field' => 'id', 'Type' => 'bigint']]
                : []);
            return $statement;
        });
        $pdo->method('query')->willReturnCallback(function ($sql) {
            $this->commands[] = $sql;
            if ($this->failId) {
                throw new \RuntimeException('Cannot read IDs');
            }
            $statement = $this->createMock(\PDOStatement::class);
            $statement->method('fetch')->willReturn([$this->ids]);
            return $statement;
        });
        $reflection = new \ReflectionClass(PDOClient::class);
        $client = $reflection->newInstanceWithoutConstructor();
        $property = $reflection->getProperty('dbh');
        $property->setAccessible(true);
        $property->setValue($client, $pdo);
        return $client;
    }

    private function query(PDOClient $client, bool $lazy = true): Query
    {
        return new Query(['client' => $client, 'lazy_insert_ids' => $lazy], 'products');
    }

    private function idReads(): int
    {
        return count(array_keys($this->commands, 'SELECT LAST_INSERT_ID()', true));
    }

    public function testDeferredIdsAreLoadedOnceAndWriteChecksDoNotLoad(): void
    {
        $query = $this->query($this->client());
        $this->assertTrue($query->insert([['title' => 'one'], ['title' => 'two']]));
        $result = $query->lastResultSet();
        $this->assertTrue($result->success());
        $this->assertNull($result->error());
        $this->assertSame(0, $this->idReads());
        $this->assertSame([7, 8], $result->result());
        $this->assertSame([7, 8], $result->result());
        $this->assertSame(1, $this->idReads());
    }

    public function testOnlyLastWriteCanLoadAndLoadedValuesRemainAvailable(): void
    {
        $client = $this->client();
        $query = $this->query($client);
        $query->insert(['title' => 'one']);
        $old = $query->lastResultSet();
        $second = $this->query($client);
        $this->ids = '99';
        $second->replace(['title' => 'two'], 99);
        $this->assertSame(0, $this->idReads());
        $this->assertSame(99, $second->lastResultSet()->result());
        $query->insert(['title' => 'three']);
        $this->assertSame(99, $second->lastResultSet()->result());
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('expired');
        $old->result();
    }

    public function testRawCommandsExpirePendingIds(): void
    {
        $client = $this->client();
        $query = $this->query($client);
        $query->insert(['title' => 'one']);
        $result = $query->lastResultSet();
        $client->query("REPLACE INTO products (id, title) VALUES (5, 'raw')");
        $this->expectException(\LogicException::class);
        $result->result();
    }

    public function testOtherConnectionsDoNotExpireIds(): void
    {
        $query = $this->query($this->client());
        $query->insert(['title' => 'one']);
        $this->query($this->client())->insert(['title' => 'other']);
        $this->assertSame([7, 8], $query->lastResultSet()->result());
    }

    public function testImmediateMethodsAndDefaultModeStillReadIds(): void
    {
        $client = $this->client();
        $this->ids = '7';
        $query = $this->query($client);
        $this->assertSame(7, $query->insertGetId(['title' => 'one']));
        $this->assertSame(7, $query->replaceGetId(['title' => 'one'], 7));
        $this->assertSame(7, $query->insertResultSet(['title' => 'one'])->result());
        $this->assertSame(7, $query->replaceResultSet(['title' => 'one'], 7)->result());
        $default = $this->query($client, false);
        $default->insert(['title' => 'one']);
        $default->replace(['title' => 'one'], 7);
        $this->assertSame(6, $this->idReads());
    }

    public function testIdFailureIsCachedWithoutChangingSuccessfulWrite(): void
    {
        $query = $this->query($this->client());
        $query->insert(['title' => 'one']);
        $result = $query->lastResultSet();
        $this->failId = true;
        for ($i = 0; $i < 2; ++$i) {
            try {
                $result->result();
                $this->fail('Expected an ID retrieval error');
            }
            catch (\RuntimeException $e) {
                $this->assertSame('Cannot read IDs', $e->getMessage());
            }
        }
        $this->assertSame(1, $this->idReads());
        $this->assertTrue($result->success());
        $this->assertNull($result->error());
    }

    public function testRejectedWriteDoesNotCreateAnIdLoader(): void
    {
        $query = $this->query($this->client());
        $query->columnTypes();
        $this->failWrite = true;
        $this->assertFalse($query->insert(['title' => 'one']));
        $this->assertNull($query->lastResultSet()->result());
        $this->assertSame(0, $this->idReads());
    }

    public function testCustomClientsKeepImmediatePath(): void
    {
        $client = new FakeClient(['title' => 'text']);
        $query = new Query(['client' => $client, 'lazy_insert_ids' => true], 'products');
        $query->insert(['title' => 'one']);
        $this->assertSame(1, $query->lastResultSet()->result());
        $subclass = new class extends PDOClient {
            public function __construct() {}
            public function insert(string $query, ?array $params = []): array
            {
                return ['data' => 42];
            }
        };
        $this->assertFalse($subclass->supportsDeferredInsert());
    }

    public function testSetResultReplacesPendingLoader(): void
    {
        $result = new ResultSet(['id_loader' => function () { throw new \RuntimeException(); }]);
        $result->setResult('id', 42);
        $this->assertSame(42, $result->result());
    }
}
