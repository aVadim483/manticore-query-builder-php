<?php

namespace avadim\Manticore\Tests\Unit;

use avadim\Manticore\Tests\Support\FakeClient;
use avadim\Manticore\Tests\Support\UnitTestCase;

/**
 * How the rows of a join are handed over.
 *
 * A plain SELECT answers with a dictionary keyed by the document id. A join cannot: it gives
 * one row per pair, so the id of the left table repeats itself as soon as the relation is one
 * to many, and keying by it would keep the last row of every document and drop the rest.
 */
final class JoinResultTest extends UnitTestCase
{
    /**
     * @return FakeClient
     */
    private function clientWithJoinedRows(): FakeClient
    {
        $client = new FakeClient(['id' => 'bigint', 'title' => 'text', 'gid' => 'uint']);
        // one product joined with three groups: the same id three times over
        $client->selectData = [
            [
                ['id' => 1, 'title' => 'laptop', 'groups.title' => 'computers'],
                ['id' => 1, 'title' => 'laptop', 'groups.title' => 'sale'],
                ['id' => 1, 'title' => 'laptop', 'groups.title' => 'new'],
            ],
        ];

        return $client;
    }

    public function testEveryRowOfAOneToManyJoinIsKept(): void
    {
        $client = $this->clientWithJoinedRows();
        $rows = $this->queryFor($client)->join('groups', 'gid', 'id')->get();

        $this->assertCount(3, $rows);
        $this->assertSame(
            ['computers', 'sale', 'new'],
            array_column($rows, 'groups.title')
        );
    }

    /**
     * ... while a query without a join keeps answering with a dictionary keyed by the id
     */
    public function testAQueryWithoutAJoinIsStillKeyedById(): void
    {
        $client = new FakeClient(['id' => 'bigint', 'title' => 'text']);
        $client->selectData = [
            [
                ['id' => 17, 'title' => 'laptop'],
                ['id' => 42, 'title' => 'mouse'],
            ],
        ];

        $rows = $this->queryFor($client)->get();

        $this->assertSame([17, 42], array_keys($rows));
    }
}
