<?php

namespace avadim\Manticore\Tests\Integration;

use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use avadim\Manticore\QueryBuilder\Connection;
use avadim\Manticore\QueryBuilder\Query;
use avadim\Manticore\Tests\Support\IntegrationTestCase;
use avadim\Manticore\Tests\Support\SubclassedConnection;
use avadim\Manticore\Tests\Support\SubclassedQuery;

/**
 * Connection::$queryClass - the second half of the hook for a framework wrapper.
 *
 * A wrapper needs its own Query to answer the reads (a collection instead of an array, say).
 * The point of the property is that it substitutes the class and nothing else: the schema pool
 * and the result slot of the connection must keep working, otherwise the wrapper silently loses
 * the DESCRIBE cache and lastResultSet().
 */
final class QueryClassTest extends IntegrationTestCase
{
    protected function tearDown(): void
    {
        ManticoreDb::setConnectionClass(Connection::class);

        parent::tearDown();
    }

    /**
     * @param Connection $connection
     *
     * @return array
     */
    private function cachedSchemas(Connection $connection): array
    {
        $property = new \ReflectionProperty(Connection::class, 'schemaPool');
        $property->setAccessible(true);

        return array_keys($property->getValue($connection));
    }

    public function testConnectionBuildsQueriesOfItsOwnClass(): void
    {
        $connection = new SubclassedConnection(['host' => self::host(), 'port' => self::port()]);

        $this->assertInstanceOf(SubclassedQuery::class, $connection->query());
        $this->assertInstanceOf(SubclassedQuery::class, $connection->table('whatever'));
    }

    public function testTheBuilderHandsOutTheSubclassedQuery(): void
    {
        ManticoreDb::setConnectionClass(SubclassedConnection::class);
        $table = $this->createProductsTable();

        $query = ManticoreDb::table($table);

        $this->assertInstanceOf(SubclassedQuery::class, $query);
        // ... and it is still a Query, so everything written against the builder keeps working
        $this->assertInstanceOf(Query::class, $query);
        $this->assertTrue($query->insert(['title' => 'first', 'price' => 1.0]));
    }

    public function testTheSubclassedQueryCanWrapTheAnswer(): void
    {
        ManticoreDb::setConnectionClass(SubclassedConnection::class);
        $table = $this->createProductsTable();
        ManticoreDb::table($table)->insert(['title' => 'first', 'price' => 1.0]);

        /** @var SubclassedQuery $query */
        $query = ManticoreDb::table($table);
        $wrapped = $query->getWrapped();

        $this->assertArrayHasKey('wrapped', $wrapped);
        $this->assertCount(1, $wrapped['wrapped']);
    }

    /**
     * The slot is handed over by reference, so a subclassed Query must still fill it
     */
    public function testTheSubclassedQueryFillsTheResultSlot(): void
    {
        ManticoreDb::setConnectionClass(SubclassedConnection::class);
        $table = $this->createProductsTable();

        ManticoreDb::table($table)->insert(['title' => 'first', 'price' => 1.0]);

        $last = ManticoreDb::lastResultSet();
        $this->assertNotNull($last, 'The result slot of the connection was not filled');
        $this->assertTrue($last->success(), (string)$last->error());
        $this->assertStringContainsString('INSERT', (string)$last->sqlQuery());
    }

    /**
     * The same for the schema pool: without it every statement asks DESCRIBE again.
     * The pool is private and DESCRIBE goes out as a service query, so reflection is the only
     * way to see it.
     */
    public function testTheSubclassedQuerySharesTheSchemaPool(): void
    {
        ManticoreDb::setConnectionClass(SubclassedConnection::class);
        $table = $this->createProductsTable();
        $connection = ManticoreDb::connection();

        $this->assertSame([], $this->cachedSchemas($connection));

        ManticoreDb::table($table)->insert(['title' => 'first', 'price' => 1.0]);

        $this->assertSame([$table], $this->cachedSchemas($connection));
    }
}
