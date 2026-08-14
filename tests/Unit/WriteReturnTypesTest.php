<?php

namespace avadim\Manticore\Tests\Unit;

use avadim\Manticore\QueryBuilder\ResultSet;
use avadim\Manticore\Tests\Support\FakeClient;
use avadim\Manticore\Tests\Support\UnitTestCase;

/**
 * What the write commands answer with.
 *
 * insert()/update()/delete() speak the language of the Laravel query builder - a flag and a
 * number - while the *ResultSet() twins keep the whole answer of the server. Both run the very
 * same statement, so the pairs are checked side by side here.
 */
final class WriteReturnTypesTest extends UnitTestCase
{
    /**
     * @return array<string, string>
     */
    private function types(): array
    {
        return ['id' => 'bigint', 'title' => 'text', 'price' => 'float'];
    }

    public function testInsertResultSetReturnsResultSet(): void
    {
        $client = new FakeClient($this->types());
        $client->insertedId = 42;

        $result = $this->queryFor($client)->insertResultSet(['title' => 'x']);

        $this->assertInstanceOf(ResultSet::class, $result);
        $this->assertSame('INSERT', $result->command());
        $this->assertSame('inserted', $result->status());
        $this->assertSame(42, $result->result());
    }

    public function testInsertReturnsBoolean(): void
    {
        $client = new FakeClient($this->types());

        $this->assertTrue($this->queryFor($client)->insert(['title' => 'x']));
    }

    public function testInsertGetIdReturnsInsertedId(): void
    {
        $client = new FakeClient($this->types());
        $client->insertedId = 42;

        $this->assertSame(42, $this->queryFor($client)->insertGetId(['title' => 'x']));
    }

    public function testInsertGetIdOfMultipleRowsReturnsTheFirstId(): void
    {
        $client = new FakeClient($this->types());
        $client->insertedId = [7, 8, 9];

        $id = $this->queryFor($client)->insertGetId([
            ['title' => 'a'],
            ['title' => 'b'],
            ['title' => 'c'],
        ]);

        $this->assertSame(7, $id);
    }

    public function testReplaceResultSetReturnsResultSet(): void
    {
        $client = new FakeClient($this->types());
        $client->insertedId = 777;

        $result = $this->queryFor($client)->replaceResultSet(['title' => 'x'], 777);

        $this->assertSame('REPLACE', $result->command());
        $this->assertSame('replaced', $result->status());
        // REPLACE is run through the INSERT path of the client, so it reports an id as well
        $this->assertSame(777, $result->result());
    }

    public function testReplaceReturnsBoolean(): void
    {
        $client = new FakeClient($this->types());

        $this->assertTrue($this->queryFor($client)->replace(['title' => 'x'], 777));
    }

    public function testReplaceGetIdReturnsWrittenId(): void
    {
        $client = new FakeClient($this->types());
        $client->insertedId = 777;

        $this->assertSame(777, $this->queryFor($client)->replaceGetId(['title' => 'x'], 777));
    }

    public function testUpdateReturnsNumberOfAffectedRows(): void
    {
        $client = new FakeClient($this->types());
        $client->affectedRows = 3;

        $this->assertSame(3, $this->queryFor($client)->where('price', '<', 50)->update(['title' => 'x']));
    }

    public function testUpdateResultSetCarriesTheSameNumber(): void
    {
        $client = new FakeClient($this->types());
        $client->affectedRows = 3;

        $result = $this->queryFor($client)->where('price', '<', 50)->updateResultSet(['title' => 'x']);

        $this->assertSame('UPDATE', $result->command());
        $this->assertSame('updated', $result->status());
        $this->assertSame(3, $result->result());
    }

    public function testDeleteReturnsNumberOfAffectedRows(): void
    {
        $client = new FakeClient($this->types());
        $client->affectedRows = 2;

        $this->assertSame(2, $this->queryFor($client)->where('price', '<', 50)->delete());
    }

    public function testDeleteResultSetCarriesTheSameNumber(): void
    {
        $client = new FakeClient($this->types());
        $client->affectedRows = 2;

        $result = $this->queryFor($client)->where('price', '<', 50)->deleteResultSet();

        $this->assertSame('DELETE', $result->command());
        $this->assertSame('deleted', $result->status());
        $this->assertSame(2, $result->result());
    }

    public function testDeleteTakesAnIdAsArgument(): void
    {
        $client = new FakeClient($this->types());
        $this->queryFor($client)->delete(17);

        $this->assertSqlSame('DELETE FROM products WHERE (id=17)', $client->lastQuery());
    }

    public function testScalarWritesLeaveTheResultSetBehind(): void
    {
        $client = new FakeClient($this->types());
        $client->insertedId = 42;
        $query = $this->queryFor($client);

        $this->assertTrue($query->insert(['title' => 'x']));

        // the flag says it worked, everything else about the statement is still reachable
        $result = $query->lastResultSet();
        $this->assertInstanceOf(ResultSet::class, $result);
        $this->assertSame('INSERT', $result->command());
        $this->assertSame(42, $result->result());
        $this->assertStringContainsString('INSERT INTO products', (string)$result->sqlQuery());
    }

    public function testLastResultSetIsEmptyBeforeAnyQuery(): void
    {
        $this->assertNull($this->query()->lastResultSet());
    }
}
