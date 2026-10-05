<?php

namespace avadim\Manticore\Tests\Unit;

use avadim\Manticore\Tests\Support\UnitTestCase;

final class MvaSqlTest extends UnitTestCase
{
    public function testSetsRangesAndLargeIntegers(): void
    {
        $sql = $this->query()->whereMvaAny('tags', [1, '9223372036854775807'])
            ->andWhereMvaAll('tags', 'not in', [3])->orWhereMvaAny('tags', 'BETWEEN', [5, 9])->toSql();
        $this->assertStringContainsString('ANY(tags) IN(1,9223372036854775807)', $sql);
        $this->assertStringContainsString('ALL(tags) NOT IN(3)', $sql);
        $this->assertStringContainsString('OR(ANY(tags) BETWEEN 5 AND 9)', $sql);
    }

    public function testNestedConditionsAndAliases(): void
    {
        $sql = $this->query()->where(function ($where) {
            $where->andWhereMvaAny('tags', 1)->orWhereMvaAll('tags', '>=', 5);
        })->toSql();
        $this->assertStringContainsString('ANY(tags)=1', $sql);
        $this->assertStringContainsString('OR(ALL(tags)>=5)', $sql);
    }

    /** @dataProvider invalidConditions */
    public function testInvalidConditionsAreRejected(array $arguments): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->query()->whereMvaAny(...$arguments);
    }

    public function invalidConditions(): array
    {
        return [
            [['tags', []]], [['tags', 'NOT IN', []]], [['tags', 'BETWEEN', [1]]],
            [['tags', 'LIKE', 1]], [['tags', null]], [['tags', true]],
            [['tags', 1.5]], [['tags', [1, '2) OR 1=1']]],
            [['tags)', 1]], [['tags', '=', [1, 2]]],
        ];
    }
}
