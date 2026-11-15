<?php

namespace avadim\Manticore\Tests\Unit;

use avadim\Manticore\QueryBuilder\JsonPath;
use avadim\Manticore\Tests\Support\FakeClient;
use avadim\Manticore\Tests\Support\UnitTestCase;

final class JsonConditionsTest extends UnitTestCase
{
    public function testNestedMembershipAndLengthPreserveTheirBooleanStructure(): void
    {
        $query = $this->query()->select('id')->where(function ($where) {
            $where->whereJsonContains('info->tags', ['sale', 'new'])->orWhereJsonLength('info.tags', 0);
        })->whereJsonContainsKey('info->active');
        $sql = $query->toSql();
        $this->assertCount(2, $query->hiddenSearchExpressions());
        $this->assertStringContainsString("ANY(__mqb_value='sale' FOR __mqb_value IN info['tags'])", $sql);
        $this->assertStringContainsString("ANY(__mqb_value='new' FOR __mqb_value IN info['tags'])", $sql);
        $this->assertStringContainsString('LENGTH(info.tags)', $sql);
        $this->assertStringContainsString("info['active'] IS NOT NULL", $sql);
        $this->assertStringContainsString('OR', $sql);
    }

    public function testHiddenAliasesAreRemovedAndUserProjectionSurvives(): void
    {
        $client = new FakeClient(['id' => 'bigint', 'title' => 'text']);
        $query = $this->queryFor($client)->whereJsonOverlaps('info.tags', ['sale', "quo'te\\Рус"]);
        $alias = array_key_first($query->hiddenSearchExpressions());
        $client->selectData = [[['id' => '1', 'title' => 'kept', $alias => '1']]];
        $rows = $query->select('id', 'title')->get();
        $this->assertSame([1 => ['id' => 1, 'title' => 'kept']], $rows);
    }

    public function testRepeatedExpressionsShareOneAliasAndClonesHaveIndependentGroups(): void
    {
        $group = null;
        $query = $this->query()->where(function ($where) use (&$group) {
            $group = $where;
            $where->whereJsonContains('info.tags', 'sale');
        })->whereJsonContains('info.tags', 'sale');
        $clone = clone $query;
        $before = $clone->toSql();
        $group->whereJsonLength('info.tags', 2);
        $this->assertSame($before, $clone->toSql());
        $this->assertCount(1, $clone->hiddenSearchExpressions());
        $this->assertCount(2, $query->hiddenSearchExpressions());
    }

    public function testEmptyOverlapsAndNegationAreExplicitConstants(): void
    {
        $positive = $this->query()->whereJsonOverlaps('info.tags', []);
        $negative = $this->query()->whereJsonDoesntOverlap('info.tags', []);
        $this->assertSame(['0'], array_values($positive->hiddenSearchExpressions()));
        $this->assertStringContainsString('=1)', $positive->toSql());
        $this->assertStringContainsString('=0)', $negative->toSql());
    }

    public function testKeyChecksDoNotRequireHiddenExpressions(): void
    {
        $query = $this->query()->whereJsonContainsKey('info->tags[1]')->orWhereJsonDoesntContainKey('info->missing');
        $this->assertSame([], $query->hiddenSearchExpressions());
        $this->assertStringContainsString("info['tags'][1] IS NOT NULL", $query->toSql());
        $this->assertStringContainsString("info['missing'] IS NULL", $query->toSql());
    }

    public function testQuotedArrowKeysAndNumericIndexes(): void
    {
        $this->assertSame("info['x.y']['a\\'b'][0]", JsonPath::compile('info->"x.y"->"a\'b"->00'));
    }

    public function testQuotedKeysMayContainTheArrowDelimiterAndEscapedQuotes(): void
    {
        $this->assertSame("info['a->b'][0]", JsonPath::compile('info->"a->b"->0'));
        $this->assertSame("info['a\\\"->b']", JsonPath::compile('info->"a\"->b"'));
        $this->assertSame("info['a\\\\->b']", JsonPath::compile('info->"a\\\\->b"'));
    }

    public function testUserAliasCollisionIsRejectedBeforeExecutingTheSelect(): void
    {
        $query = $this->query()->whereJsonContains('info.tags', 1);
        $alias = array_key_first($query->hiddenSearchExpressions());
        $query->select('1 AS ' . $alias);
        $this->expectException(\LogicException::class);
        $query->toSql();
    }

    public function testSchemaAliasCollisionIsRejected(): void
    {
        $query = $this->query()->whereJsonContains('info.tags', 1);
        $alias = array_key_first($query->hiddenSearchExpressions());
        $query = $this->query('products', [$alias => 'integer'])->whereJsonContains('info.tags', 1);
        $this->expectException(\LogicException::class);
        $query->toSql();
    }

    public function testComputedFiltersCannotBeUsedForDelete(): void
    {
        $this->expectException(\LogicException::class);
        $this->query()->whereJsonContains('info.tags', 'sale')->delete();
    }

    /** @dataProvider invalidConditions */
    public function testInvalidConditionsAreRejected(string $method, array $arguments): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->query()->{$method}(...$arguments);
    }

    public function invalidConditions(): array
    {
        return [
            ['whereJsonContains', ['info.tags', []]],
            ['whereJsonContains', ['info.tags', null]],
            ['whereJsonContains', ['info.tags', ['object' => 1]]],
            ['whereJsonContains', ['info.tags', [[1]]]],
            ['whereJsonContains', ['info.tags', INF]],
            ['whereJsonOverlaps', ['info.tags', NAN]],
            ['whereJsonLength', ['info.tags', -1]],
            ['whereJsonLength', ['info.tags', '1']],
            ['whereJsonLength', ['info.tags', 'LIKE', 1]],
            ['whereJsonContainsKey', ['info->']],
            ['whereJsonContainsKey', ['info->tags[-1]']],
            ['whereJsonContainsKey', ['info->tags[*]']],
            ['whereJsonContainsKey', ['info->tags[2147483648]']],
            ['whereJsonContainsKey', ['info->"unterminated->key']],
            ['whereJsonContainsKey', ['info->"broken\\q"']],
            ['whereJsonContainsKey', ['info;DROP TABLE products']],
            ['whereJsonContainsKey', ["info->bad\0key"]],
        ];
    }
}
