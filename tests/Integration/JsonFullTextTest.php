<?php

namespace avadim\Manticore\Tests\Integration;

use avadim\Manticore\QueryBuilder\Builder as Db;
use avadim\Manticore\Tests\Support\IntegrationTestCase;

final class JsonFullTextTest extends IntegrationTestCase
{
    private string $table;

    protected function setUp(): void
    {
        parent::setUp();
        $this->table = $this->createTable(['title' => 'text', 'body' => 'text', 'stock' => 'integer', 'info' => 'json'], 'jsontext');
        $this->assertTrue(Db::table($this->table)->insert([
            ['id' => 1, 'title' => 'galaxy phone', 'body' => 'new phone', 'stock' => 1,
                'info' => ['tags' => ['sale', 'new'], 'codes' => [2, 3], 'nullable' => null]],
            ['id' => 2, 'title' => 'tablet', 'body' => 'new phone', 'stock' => 1,
                'info' => ['tags' => ['sale'], 'codes' => [2], 'nullable' => false]],
            ['id' => 3, 'title' => 'phone', 'body' => 'old device', 'stock' => 0,
                'info' => ['tags' => [], 'codes' => [], 'nullable' => '']],
            ['id' => 4, 'title' => 'tablet', 'body' => 'old device', 'stock' => 1,
                'info' => ['tags' => ['old'], 'codes' => [4], 'nullable' => 0]],
            ['id' => 5, 'title' => 'empty', 'body' => 'other', 'stock' => 1, 'info' => []],
            ['id' => 6, 'title' => 'кириллица', 'body' => 'quote', 'stock' => 1,
                'info' => ['tags' => ["quo'te\\Рус"], 'prices' => [1.5], 'flags' => [true, false], 'x.y' => ['key' => 1],
                    'a->b' => ['sale'], 'a"->b' => [2], 'a\\->b' => [3]]],
        ]));
    }

    private function query()
    {
        return Db::table($this->table);
    }

    public function testMembershipNegationAndEmptyCandidates(): void
    {
        $this->assertSame([1, 2], $this->query()->whereJsonContains('info->tags', 'sale')->orderBy('id')->pluck('id'));
        $this->assertSame([1], $this->query()->whereJsonContains('info.tags', ['sale', 'new'])->pluck('id'));
        $this->assertSame([1, 4], $this->query()->whereJsonOverlaps('info.tags', ['new', 'old'])->orderBy('id')->pluck('id'));
        $this->assertSame([3, 4, 5, 6], $this->query()->whereJsonDoesntContain('info.tags', 'sale')->orderBy('id')->pluck('id'));
        $this->assertSame([], $this->query()->whereJsonOverlaps('info.tags', [])->pluck('id'));
        $this->assertSame(6, $this->query()->whereJsonDoesntOverlap('info.tags', [])->count());
    }

    public function testJsonNullKeyChecksAndLength(): void
    {
        $this->assertSame([2, 3, 4], $this->query()->whereJsonContainsKey('info->nullable')->orderBy('id')->pluck('id'));
        $this->assertSame([1, 5, 6], $this->query()->whereJsonDoesntContainKey('info.nullable')->orderBy('id')->pluck('id'));
        $this->assertSame([3], $this->query()->whereJsonLength('info.tags', 0)->pluck('id'));
        $this->assertSame([1], $this->query()->whereJsonLength('info.tags', '>', 1)->pluck('id'));
        $this->assertSame([1], $this->query()->whereJsonContainsKey('info->tags[1]')->pluck('id'));
        $this->assertSame([6], $this->query()->whereJsonContainsKey('info->"x.y"->key')->pluck('id'));
        $this->assertSame([6], $this->query()->whereJsonContains('info->"a->b"', 'sale')->pluck('id'));
        $this->assertSame([6], $this->query()->whereJsonContains('info->"a\"->b"', 2)->pluck('id'));
        $this->assertSame([6], $this->query()->whereJsonContains('info->"a\\\\->b"', 3)->pluck('id'));
    }

    public function testLiteralsAndNestedConditionsHaveNoHiddenColumns(): void
    {
        $rows = $this->query()->whereJsonContains('info.tags', "quo'te\\Рус")
            ->whereJsonContains('info.prices', 1.5)->whereJsonContains('info.flags', true)
            ->select('id', 'title')->get();
        $this->assertSame([6], array_keys($rows));
        $this->assertSame(['id', 'title'], array_keys($rows[6]));
        $this->assertSame([1, 3], $this->query()->where(function ($where) {
            $where->whereJsonLength('info.tags', 0)->orWhereJsonContains('info.tags', 'new');
        })->orderBy('id')->pluck('id'));
    }

    public function testComposedTextUsesOneMatchAndPreservesAndOrPrecedence(): void
    {
        $this->assertSame([1, 3], $this->query()->whereFullText('title', 'phone')->orderBy('id')->pluck('id'));
        $this->assertSame([1, 2, 3], $this->query()->whereFullText(['title', 'body'], 'phone')->orderBy('id')->pluck('id'));
        $this->assertSame([1], $this->query()->whereFullText('title', 'galaxy')->whereFullText('body', 'phone')->pluck('id'));
        $this->assertSame([1, 2, 4], $this->query()->whereFullText('title', 'tablet')
            ->orWhereFullText('title', 'phone')->whereFullText('body', 'new')->orderBy('id')->pluck('id'));
    }

    public function testTextAndJsonGroupsWorkTogetherWithMetadataAndScore(): void
    {
        $query = $this->query()->where('stock', 1)->where(function ($where) {
            $where->whereFullText('title', 'phone')->orWhereFullText('body', 'phone');
        })->where(function ($where) {
            $where->whereJsonContains('info.tags', 'sale')->orWhereJsonLength('info.tags', 0);
        });
        $result = $query->orderBy('id')->search();
        $this->assertTrue($result->success(), $result->error() ?? '');
        $this->assertSame([1, 2], array_keys($result->result()));
        $this->assertTrue($result->hasMeta());
        $this->assertArrayHasKey('_score', $result->first());
        foreach ($result->result() as $row) {
            foreach (array_keys($row) as $key) {
                $this->assertFalse(strpos($key, '__mqb_json_') === 0);
            }
        }
    }

    public function testKeyChecksCanFilterWritesAndComputedFiltersRejectWrites(): void
    {
        $this->assertSame(3, $this->query()->whereJsonContainsKey('info.nullable')->update(['stock' => 9]));
        $this->assertSame([2, 3, 4], $this->query()->where('stock', 9)->orderBy('id')->pluck('id'));
        $this->expectException(\LogicException::class);
        $this->query()->whereJsonLength('info.tags', 1)->delete();
    }

    public function testExpressionRankerAndLegacyMatchRemainAvailable(): void
    {
        $this->assertSame([1], $this->query()->whereFullText('title', 'galaxy')->rankerExpression('sum(lcs)')->pluck('id'));
        $this->assertSame([1, 2, 3], $this->query()->match('tablet')->match('phone')->orderBy('id')->pluck('id'));
    }
}
