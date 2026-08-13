<?php

namespace avadim\Manticore\Tests\Unit;

use avadim\Manticore\QueryBuilder\Query;
use avadim\Manticore\Tests\Support\FakeClient;
use avadim\Manticore\Tests\Support\UnitTestCase;

/**
 * The DESCRIBE cache shared by every Query of one connection.
 *
 * Column types are asked for before every write and after every read that returns rows, while
 * Connection::query() builds a new Query each time - without a shared pool that means one extra
 * round trip per statement.
 */
final class SchemaCacheTest extends UnitTestCase
{
    /**
     * @param FakeClient $client
     *
     * @return int
     */
    private function describeCount(FakeClient $client): int
    {
        return count(array_filter($client->queries, static function ($query) {
            return stripos($query, 'DESCRIBE') === 0;
        }));
    }

    public function testSchemaIsDescribedOnceForSeveralQueries(): void
    {
        $client = new FakeClient($this->productColumnTypes());
        $pool = [];

        for ($i = 0; $i < 3; $i++) {
            $query = $this->queryFor($client);
            $query->setSchemaPool($pool);
            $query->insert(['title' => 'test', 'price' => $i]);
        }

        $this->assertSame(1, $this->describeCount($client));
        $this->assertSame(['products'], array_keys($pool));
    }

    public function testSchemaIsDescribedPerTable(): void
    {
        $client = new FakeClient($this->productColumnTypes());
        $pool = [];

        foreach (['products', 'orders'] as $table) {
            $query = $this->queryFor($client, $table);
            $query->setSchemaPool($pool);
            $query->insert(['title' => 'test']);
        }

        $this->assertSame(2, $this->describeCount($client));
        $this->assertSame(['products', 'orders'], array_keys($pool));
    }

    public function testDropForgetsCachedSchema(): void
    {
        $client = new FakeClient($this->productColumnTypes());
        $pool = [];

        $query = $this->queryFor($client);
        $query->setSchemaPool($pool);
        $query->insert(['title' => 'test']);
        $this->assertNotEmpty($pool);

        $dropQuery = $this->queryFor($client);
        $dropQuery->setSchemaPool($pool);
        $dropQuery->drop(true);

        $this->assertSame([], $pool, 'a dropped table must not keep its columns cached');
    }

    public function testCreateForgetsCachedSchema(): void
    {
        // the same name may have existed before with another set of columns
        $client = new FakeClient($this->productColumnTypes());
        $pool = [];

        $query = $this->queryFor($client);
        $query->setSchemaPool($pool);
        $query->insert(['title' => 'test']);
        $this->assertNotEmpty($pool);

        $createQuery = $this->queryFor($client);
        $createQuery->setSchemaPool($pool);
        $createQuery->create(['title' => 'text']);

        $this->assertSame([], $pool);
    }

    public function testQueryWithoutSharedPoolKeepsItsOwnCache(): void
    {
        // a bare Query still caches within itself, it just cannot share
        $client = new FakeClient($this->productColumnTypes());
        $query = $this->queryFor($client);

        $query->columnTypes();
        $query->columnTypes();

        $this->assertSame(1, $this->describeCount($client));
    }
}
