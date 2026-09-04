<?php

namespace avadim\Manticore\Tests\Unit;

use avadim\Manticore\QueryBuilder\ResultSet;
use avadim\Manticore\Tests\Support\UnitTestCase;

/**
 * A result set built out of rows rather than out of an answer of the server.
 *
 * What the constructor reads is the shape of the class, and a driver standing in for an answer -
 * an index that is not there yet, a query that never went out - had to write that shape out to
 * make one.
 */
final class ResultSetFactoryTest extends UnitTestCase
{
    public function testTheRowsAreTheResultAndTheTotal(): void
    {
        $result = ResultSet::of([
            ['id' => 1, 'title' => 'first'],
            ['id' => 2, 'title' => 'second'],
        ]);

        $this->assertTrue($result->success());
        $this->assertSame('SELECT', $result->command());
        $this->assertCount(2, $result->result());
        $this->assertSame(2, $result->count());
        $this->assertSame(2, $result->total());
        $this->assertSame(['id', 'title'], $result->columns());
        $this->assertSame(['id' => 1, 'title' => 'first'], $result->first());
    }

    public function testTheKeysOfTheRowsAreDropped(): void
    {
        $result = ResultSet::of([7 => ['id' => 7], 9 => ['id' => 9]]);

        $this->assertSame([['id' => 7], ['id' => 9]], $result->result());
    }

    public function testWhatTheMetaSaysWinsOverTheCountOfTheRows(): void
    {
        // a page of a larger result knows a total the rows themselves do not
        $result = ResultSet::of([['id' => 1]], ['total_found' => 120]);

        $this->assertSame(120, $result->total());
        $this->assertSame(1, $result->count());
    }

    public function testAnEmptyAnswerIsAnAnswer(): void
    {
        $result = ResultSet::empty();

        $this->assertTrue($result->success());
        $this->assertSame([], $result->result());
        $this->assertSame(0, $result->total());
        $this->assertNull($result->first());
    }
}
