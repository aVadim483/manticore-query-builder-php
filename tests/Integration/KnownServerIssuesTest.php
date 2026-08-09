<?php

namespace avadim\Manticore\Tests\Integration;

use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use avadim\Manticore\Tests\Support\IntegrationTestCase;

/**
 * Defects that only show up against a real server.
 *
 * Like KnownIssuesTest, every test states the promised behaviour and is marked incomplete,
 * so that the suite stays green while the bug is still there. Remove the markTestIncomplete()
 * line once it is fixed.
 */
final class KnownServerIssuesTest extends IntegrationTestCase
{
    /**
     * Connection::showVariables() is typed ": array" but returns ResultSet::result(), which is
     * boolean true when the server answers with an empty set - a TypeError instead of an
     * empty array.
     */
    public function testShowVariablesWithPatternReturnsArray(): void
    {
        $this->markTestIncomplete('showVariables() with a pattern that matches nothing throws a TypeError');

        $this->assertIsArray(ManticoreDb::showVariables('%no_such_variable%'));
    }
}
