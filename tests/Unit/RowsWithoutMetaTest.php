<?php

namespace avadim\Manticore\Tests\Unit;

use avadim\Manticore\QueryBuilder\QueryErrorException;
use avadim\Manticore\Tests\Support\FakeClient;
use avadim\Manticore\Tests\Support\UnitTestCase;

final class RowsWithoutMetaTest extends UnitTestCase
{
    public function testRowsPreloadSchemaAndLeaveSearchAsLastCommand(): void
    {
        $client = new FakeClient(['id' => 'bigint', 'title' => 'text']);
        $client->selectData = [[['id' => '3', 'title' => 'Телефон']]];
        $query = $this->queryFor($client);
        $this->assertSame([3 => ['id' => 3, 'title' => 'Телефон']], $query->getRows());
        $this->assertSame('DESCRIBE products', $client->queries[0]);
        $this->assertCount(2, $client->queries);
        $this->assertStringStartsWith('SELECT ', $client->lastQuery());
        $this->assertFalse($query->lastResultSet()->hasMeta());
        $this->assertSame([], $query->lastResultSet()->meta());
        $this->assertNull($query->lastResultSet()->metaObject()->totalFound());
        $this->assertCount(2, $client->queries);

        $query->get();
        $this->assertSame('SHOW META', $client->lastQuery());
        $this->assertTrue($query->lastResultSet()->hasMeta());
    }

    public function testEmptySchemaIsNotRequestedAfterSearch(): void
    {
        $client = new FakeClient();
        $client->selectData = [[['answer' => 42]]];
        $this->assertSame([['answer' => 42]], $this->queryFor($client)->getRows('42 AS answer'));
        $this->assertCount(2, $client->queries);
        $this->assertStringStartsWith('SELECT ', $client->lastQuery());
    }

    public function testEmptyRowsAndColumnsMatchGet(): void
    {
        $client = new FakeClient(['title' => 'text']);
        $query = $this->queryFor($client);
        $this->assertSame([], $query->limit(5)->getRows('title'));
        $this->assertStringContainsString('title', $client->lastQuery());
        $this->assertStringContainsString('LIMIT 5', $client->lastQuery());
        $this->assertNotContains('SHOW META', $client->queries);
    }

    public function testExplicitMetadataUsesSameClientAndUpdatesServiceResult(): void
    {
        $client = new class extends FakeClient {
            public function query(string $query, ?array $params = []): array
            {
                if ($query === 'SHOW META') {
                    $this->queries[] = $query;
                    return ['data' => [
                        ['Variable_name' => 'total_found', 'Value' => '30'],
                        ['Variable_name' => 'time', 'Value' => '0.002'],
                    ]];
                }
                return parent::query($query, $params);
            }
        };
        $query = $this->queryFor($client);
        $query->getRows();
        $rowsResult = $query->lastResultSet();
        $meta = $query->getMeta();
        $this->assertSame(30, $meta->totalFound());
        $this->assertSame(0.002, $meta->time());
        $this->assertSame('SHOW META', $query->lastResultSet()->command());
        $this->assertFalse($rowsResult->hasMeta());
        $this->assertSame([], $rowsResult->meta());
    }

    public function testErrorsThrowAndDoNotLeakRowsMode(): void
    {
        $client = new class extends FakeClient {
            public bool $fail = true;
            public function select(string $query, ?array $params = []): array
            {
                if ($this->fail) {
                    throw new \RuntimeException('Rejected SELECT');
                }
                return parent::select($query, $params);
            }
        };
        $query = $this->queryFor($client);
        try {
            $query->getRows();
            $this->fail('Expected a read error');
        }
        catch (QueryErrorException $e) {
            $this->assertStringContainsString('Rejected SELECT', $e->getMessage());
        }
        $client->fail = false;
        $query->get();
        $this->assertSame('SHOW META', $client->lastQuery());
    }

    public function testMetadataErrorsThrowReadException(): void
    {
        $client = new class extends FakeClient {
            public function query(string $query, ?array $params = []): array
            {
                throw new \RuntimeException('Metadata denied');
            }
        };
        $this->expectException(QueryErrorException::class);
        $this->expectExceptionMessage('Metadata denied');
        $this->queryFor($client)->getMeta();
    }
}
