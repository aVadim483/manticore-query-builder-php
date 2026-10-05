<?php

namespace avadim\Manticore\Tests\Integration;

use avadim\Manticore\QueryBuilder\Builder as Db;
use avadim\Manticore\Tests\Support\IntegrationTestCase;

/** @group buddy */
final class AutocompleteTest extends IntegrationTestCase
{
    public function testPrefixCompletionUsesTheIndexedDictionary(): void
    {
        $table = $this->createTable(['title' => 'text'], 'autocomplete', ['min_infix_len' => 2]);
        $this->assertTrue(Db::table($table)->insert([
            ['id' => 1, 'title' => 'manticore search'],
            ['id' => 2, 'title' => 'mandarin orange'],
        ]));
        $rows = Db::table($table)->callAutocomplete('manti', ['fuzziness' => 0, 'append' => true]);
        $this->assertContains('manticore', array_column($rows, 'query'));
        foreach ($rows as $row) {
            $this->assertIsString($row['query']);
        }
    }

    public function testUnknownPrefixHasNoCompletions(): void
    {
        $table = $this->createTable(['title' => 'text'], 'autocomplete', ['min_infix_len' => 2]);
        $this->assertTrue(Db::table($table)->insert(['id' => 1, 'title' => 'manticore']));
        $this->assertSame([], Db::table($table)->callAutocomplete('zzzzqqqq', [
            'fuzziness' => 0, 'append' => true, 'preserve' => false,
        ]));
    }
}
