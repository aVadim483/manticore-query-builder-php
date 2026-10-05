<?php

namespace avadim\Manticore\Tests\Integration;

use avadim\Manticore\QueryBuilder\Builder as Db;
use avadim\Manticore\Tests\Support\IntegrationTestCase;

final class MvaHelpersTest extends IntegrationTestCase
{
    /** @dataProvider attributeTypes */
    public function testQuantifiersAndNestedConditions(string $type, int $base): void
    {
        $table = $this->createTable(['title' => 'text', 'tags' => $type]);
        $this->assertTrue(Db::table($table)->insert([
            ['id' => 1, 'title' => 'one', 'tags' => [$base + 1, $base + 2]],
            ['id' => 2, 'title' => 'two', 'tags' => [$base + 2, $base + 3]],
            ['id' => 3, 'title' => 'three', 'tags' => [$base + 1]],
            ['id' => 4, 'title' => 'empty', 'tags' => []],
        ]));
        $this->assertSame([1, 3], Db::table($table)->whereMvaAny('tags', $base + 1)->orderBy('id')->pluck('id'));
        // ALL is subset membership: document 3 does not contain the entire input set.
        $ids = Db::table($table)->whereMvaAll('tags', [$base + 1, $base + 2])->where('id', '<', 4)->orderBy('id')->pluck('id');
        $this->assertSame([1, 3], $ids);
        $this->assertSame([2], Db::table($table)->whereMvaAny('tags', '>', $base + 2)->pluck('id'));
        $this->assertSame([3], Db::table($table)->whereMvaAll('tags', 'NOT IN', [$base + 2])->where('id', '<', 4)->pluck('id'));
        $this->assertSame([1, 2], Db::table($table)->where(function ($where) use ($base) {
            $where->whereMvaAny('tags', 'BETWEEN', [$base + 2, $base + 3]);
        })->orderBy('id')->pluck('id'));
        $this->assertSame([2, 3], Db::table($table)->whereMvaAll('tags', $base + 1)
            ->orWhereMvaAny('tags', $base + 3)->orderBy('id')->pluck('id'));
    }

    public function attributeTypes(): array
    {
        return [['multi', 0], ['multi64', 5000000000]];
    }
}
