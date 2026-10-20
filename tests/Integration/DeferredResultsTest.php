<?php

namespace avadim\Manticore\Tests\Integration;

use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use avadim\Manticore\QueryBuilder\Client\PDOClient;
use avadim\Manticore\QueryBuilder\Connection;
use avadim\Manticore\QueryBuilder\Query;
use avadim\Manticore\Tests\Support\IntegrationTestCase;

final class DeferredResultsTest extends IntegrationTestCase
{
    private string $table;

    protected function setUp(): void
    {
        parent::setUp();
        $this->table = $this->createTable(['title' => 'text', 'price' => 'float'], 'deferred');
    }

    private function client(): PDOClient
    {
        return new PDOClient(['host' => self::host(), 'port' => self::port()]);
    }

    private function query(PDOClient $client): Query
    {
        return new Query(['client' => $client, 'lazy_insert_ids' => true], $this->table);
    }

    public function testColdRowsPreserveMetadataAndNormalSearchStillLoadsIt(): void
    {
        ManticoreDb::table($this->table)->insert([
            ['id' => 1, 'title' => 'Телефон', 'price' => 10],
            ['id' => 2, 'title' => 'Телефон', 'price' => 20],
        ]);
        $query = $this->query($this->client())->orderBy('id')->limit(1);
        $rows = $query->getRows();
        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[1]['id']);
        $result = $query->lastResultSet();
        $this->assertFalse($result->hasMeta());
        $this->assertSame([], $result->meta());
        $meta = $query->getMeta();
        $this->assertSame(1, $meta->total());
        $this->assertSame(2, $meta->totalFound());
        $this->assertSame(2, $meta['total_found']);
        $this->assertIsFloat($meta->time());
        $this->assertSame('SHOW META', $query->lastResultSet()->command());
        $this->assertFalse($result->hasMeta());

        $normal = $query->search();
        $this->assertTrue($normal->hasMeta());
        $this->assertSame(2, $normal->total());
        $this->assertSame($normal->meta(), $normal->metaObject()->toArray());
        $this->assertSame($rows, $query->get());
    }

    public function testMetadataFollowsLatestSearchAndIsIsolatedByConnection(): void
    {
        ManticoreDb::table($this->table)->insert([['id' => 1, 'title' => 'One'], ['id' => 2, 'title' => 'Two']]);
        $client = $this->client();
        $first = $this->query($client);
        $first->getRows();
        $this->query($this->client())->where('id', 999)->getRows();
        $this->assertSame(2, $first->getMeta()->totalFound());
        $this->query($client)->where('id', 1)->getRows();
        $this->assertSame(1, $first->getMeta()->totalFound());
    }

    public function testRowsKeepJoinMultiplicityAndFacetsWithoutMetadata(): void
    {
        $tags = $this->createTable(['label' => 'string', 'product_id' => 'integer'], 'deferred_tags');
        ManticoreDb::table($this->table)->insert(['id' => 1, 'title' => 'Phone', 'price' => 10]);
        ManticoreDb::table($tags)->insert([
            ['id' => 1, 'label' => 'new', 'product_id' => 1],
            ['id' => 2, 'label' => 'sale', 'product_id' => 1],
        ]);
        $query = $this->query($this->client())->join($tags, 'id', 'product_id');
        $this->assertCount(2, $query->getRows());
        $this->assertFalse($query->lastResultSet()->hasMeta());
        $this->assertSame(2, $query->getMeta()->totalFound());

        $query = $this->query($this->client())->facet('price');
        $this->assertCount(1, $query->getRows());
        $this->assertCount(1, $query->lastResultSet()->facets(0));
        $this->assertFalse($query->lastResultSet()->hasMeta());
        $this->assertSame(1, $query->getMeta()->totalFound());
    }

    public function testGeneratedAndExplicitIdsOfInsertAndReplace(): void
    {
        $query = $this->query($this->client());
        $this->assertTrue($query->insert(['title' => 'Generated']));
        $id = $query->lastResultSet()->result();
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
        $this->assertSame($id, $query->lastResultSet()->result());
        $this->assertTrue($query->replace(['title' => 'Replaced'], $id));
        $this->assertSame($id, $query->lastResultSet()->result());
        $this->assertTrue($query->insert([['id' => 10, 'title' => 'Ten'], ['id' => 11, 'title' => 'Eleven']]));
        $this->assertSame([10, 11], $query->lastResultSet()->result());
        $this->assertSame(20, $query->insertGetId(['title' => 'Immediate'], 20));
        $stored = $query->replaceResultSet(['title' => 'Immediate replacement'], 20);
        $query->insert(['title' => 'Next']);
        $this->assertSame(20, $stored->result());
    }

    public function testStaleIdDoesNotReadNextInsertId(): void
    {
        $client = $this->client();
        $query = $this->query($client);
        $query->insert(['title' => 'First'], 1);
        $first = $query->lastResultSet();
        $this->query($client)->insert(['title' => 'Second'], 2);
        $this->expectException(\LogicException::class);
        $first->result();
    }

    public function testConnectionLastResultBelongsToLatestWrite(): void
    {
        $connection = new Connection(['host' => self::host(), 'port' => self::port(), 'lazy_insert_ids' => true]);
        $connection->table($this->table)->insert(['title' => 'First'], 1);
        $connection->table($this->table)->replace(['title' => 'Second'], 2);
        $this->assertSame(2, $connection->lastResultSet()->result());
    }

    public function testSqlReplaceResetsOmittedFieldsAndCanCreateNewDocument(): void
    {
        $query = ManticoreDb::table($this->table);
        $this->assertTrue($query->insert(['id' => 1, 'title' => "Кавычка ' и слеш \\", 'price' => 10]));
        $this->assertTrue($query->replace(['id' => 1, 'title' => 'Replacement']));
        $row = ManticoreDb::table($this->table)->find(1);
        $this->assertSame('Replacement', $row['title']);
        $this->assertSame(0.0, $row['price']);
        $this->assertTrue($query->replace(['id' => 2, 'title' => 'New']));
        $this->assertNotNull(ManticoreDb::table($this->table)->find(2));
    }
}
