<?php

namespace avadim\Manticore\Tests\Unit;

use avadim\Manticore\Tests\Support\UnitTestCase;

final class FullTextConditionsTest extends UnitTestCase
{
    public function testAndOrPrecedenceAndAttributeGroups(): void
    {
        $query = $this->query()->where('stock', '>', 0)->where(function ($where) {
            $where->whereFullText('title', 'phone')->orWhereFullText(['title', 'body'], 'tablet');
        })->where(function ($where) {
            $where->where('price', '<', 20)->orWhereJsonContainsKey('info.discount');
        });
        $sql = $query->toSql();
        $this->assertSame(1, substr_count($sql, 'MATCH('));
        $this->assertStringContainsString('@title (phone)', $sql);
        $this->assertStringContainsString('@(title,body) (tablet)', $sql);
        $this->assertStringContainsString(' | ', $sql);
        $this->assertStringContainsString('stock>0', $sql);
        $this->assertStringContainsString('OR(info.discount IS NOT NULL)', $sql);
    }

    public function testLiteralsAreEscapedAndNativeSyntaxRequiresAnExplicitOption(): void
    {
        $sql = $this->query()->whereFullText('title', 'phone | tablet -old @body (new)')->toSql();
        $this->assertStringContainsString('phone \\\\| tablet \\\\-old \\\\@body \\\\(new\\\\)', $sql);
        $raw = $this->query()->whereFullText('title', 'phone | tablet', ['escape' => false])->toSql();
        $this->assertStringContainsString('phone | tablet', $raw);
    }

    public function testDeepCloneDoesNotShareNestedTextGroups(): void
    {
        $group = null;
        $query = $this->query()->where(function ($where) use (&$group) {
            $group = $where;
            $where->whereFullText('title', 'one');
        });
        $clone = clone $query;
        $group->orWhereFullText('title', 'two');
        $this->assertStringNotContainsString('two', $clone->toSql());
        $this->assertStringContainsString('two', $query->toSql());
    }

    public function testBatchWriteRejectsComposedFullTextInsteadOfIgnoringIt(): void
    {
        $query = $this->query()->whereFullText('title', 'phone');
        $this->expectException(\LogicException::class);
        $query->insertBatch([['title' => 'new phone']]);
    }

    /** @dataProvider unsupportedTrees */
    public function testMixedOrAndNegationAreRejected(callable $build): void
    {
        $this->expectException(\LogicException::class);
        $build($this->query())->toSql();
    }

    public function unsupportedTrees(): array
    {
        return [
            [static function ($q) { return $q->whereFullText('title', 'phone')->orWhere('stock', 1); }],
            [static function ($q) { return $q->where('stock', 1)->orWhereFullText('title', 'phone'); }],
            [static function ($q) { return $q->where(function ($g) { $g->whereFullText('title', 'phone')->where('stock', 1); })->orWhereFullText('body', 'tablet'); }],
            [static function ($q) { return $q->whereNot(function ($g) { $g->whereFullText('title', 'phone'); }); }],
            [static function ($q) { return $q->match('phone')->whereFullText('title', 'phone'); }],
            [static function ($q) { return $q->whereFullText('title', 'phone')->match('phone'); }],
            [static function ($q) { return $q->match('phone')->where(function ($g) { $g->whereFullText('title', 'phone'); }); }],
        ];
    }

    /** @dataProvider invalidConditions */
    public function testInvalidConditionsAreRejected(array $arguments): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->query()->whereFullText(...$arguments);
    }

    public function invalidConditions(): array
    {
        return array_map(static function ($arguments) { return [$arguments]; }, [
            [[], 'phone'], [['title' => 'body'], 'phone'], ['title)', 'phone'],
            ['*', 'phone'], ['title', '  '], ['title', 'phone', ['escape' => null]],
            ['title', 'phone', ['escape' => 1]], ['title', 'phone', ['mode' => 'natural']],
        ]);
    }
}
