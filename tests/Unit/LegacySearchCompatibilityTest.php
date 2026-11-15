<?php

namespace avadim\Manticore\Tests\Unit;

use avadim\Manticore\QueryBuilder\Query;
use avadim\Manticore\Tests\Support\FakeClient;
use PHPUnit\Framework\TestCase;

/** Loading this subclass fails if legacy signatures gain even optional parameters. */
final class LegacySearchQuery extends Query
{
    public function whereKnn(string $column, int $k, array $vector): Query
    {
        return parent::whereKnn($column, $k, $vector);
    }

    public function ranker(string $value): Query
    {
        return parent::ranker($value);
    }
}

final class LegacySearchCompatibilityTest extends TestCase
{
    public function testExistingSubclassAndClientStillUseLegacyDefaults(): void
    {
        $query = new LegacySearchQuery(['client' => new FakeClient()], 'products');
        $sql = $query->whereKnn('vec', 2, [1, 2])->ranker('bm25')->toSql();

        $this->assertStringContainsString('knn(vec, 2, (1,2))', $sql);
        $this->assertStringContainsString('ranker=bm25', $sql);
        $this->assertStringNotContainsString('LIMIT', $sql);
        $this->assertInstanceOf(LegacySearchQuery::class, $query->clone());
    }
}
