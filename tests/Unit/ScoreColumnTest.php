<?php

namespace avadim\Manticore\Tests\Unit;

use avadim\Manticore\Tests\Support\FakeClient;
use avadim\Manticore\Tests\Support\UnitTestCase;

/**
 * The generated "_score" column.
 *
 * weight() is 1 for every row of a query without a full-text match, so the column carries no
 * information there while still landing in the answer - where it does not match the table any
 * more. It is selected when a match is there, or when it was asked for explicitly.
 */
final class ScoreColumnTest extends UnitTestCase
{
    /**
     * The SELECT that actually went to the server, as opposed to the one toSql() shows:
     * the generated columns are added on the way out
     *
     * @param FakeClient $client
     *
     * @return string
     */
    private function sentSelect(FakeClient $client): string
    {
        foreach ($client->queries as $query) {
            if (stripos($query, 'SELECT') === 0) {
                return $query;
            }
        }

        return '';
    }

    public function testAFullTextQuerySelectsTheScore(): void
    {
        $client = new FakeClient($this->productColumnTypes());
        $this->queryFor($client)->match('galaxy')->get();

        $this->assertStringContainsString('weight() as _score', $this->sentSelect($client));
    }

    public function testAQueryWithoutAMatchDoesNot(): void
    {
        $client = new FakeClient($this->productColumnTypes());
        $this->queryFor($client)->where('price', '>', 100)->get();

        $this->assertStringNotContainsString('weight()', $this->sentSelect($client));
    }

    public function testWithScoreAsksForItWithoutAMatch(): void
    {
        $client = new FakeClient($this->productColumnTypes());
        $this->queryFor($client)->where('price', '>', 100)->withScore()->get();

        $this->assertStringContainsString('weight() as _score', $this->sentSelect($client));
    }

    public function testWithoutScoreDropsItFromAFullTextQuery(): void
    {
        $client = new FakeClient($this->productColumnTypes());
        $this->queryFor($client)->match('galaxy')->withoutScore()->get();

        $this->assertStringNotContainsString('weight()', $this->sentSelect($client));
    }

    /**
     * An explicit select() means the columns are listed by the caller, and the id is not added -
     * but the weight still can be asked for
     */
    public function testWithScoreWorksAlongsideAnExplicitSelect(): void
    {
        $client = new FakeClient($this->productColumnTypes());
        $this->queryFor($client)->select('title')->withScore()->get();

        $sql = $this->sentSelect($client);
        $this->assertStringContainsString('weight() as _score', $sql);
        $this->assertStringContainsString('title', $sql);
    }
}
