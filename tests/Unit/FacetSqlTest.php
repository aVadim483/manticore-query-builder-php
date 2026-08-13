<?php

namespace avadim\Manticore\Tests\Unit;

use avadim\Manticore\QueryBuilder\Facet;
use PHPUnit\Framework\TestCase;

/**
 * FACET clause rendering.
 */
final class FacetSqlTest extends TestCase
{
    public function testPlainFacet(): void
    {
        $this->assertSame('FACET manufacturer', (string)(new Facet('manufacturer')));
    }

    public function testFacetWithAlias(): void
    {
        $facet = new Facet('price');

        $this->assertSame('FACET price AS p', (string)$facet->alias('p'));
    }

    public function testFacetByExpression(): void
    {
        $facet = new Facet('price');

        $this->assertSame('FACET price BY INTERVAL(price,200,400)', (string)$facet->byExpr('INTERVAL(price,200,400)'));
    }

    public function testFacetDistinct(): void
    {
        $facet = new Facet('brand');

        $this->assertSame('FACET brand DISTINCT model', (string)$facet->distinct('model'));
    }

    public function testFacetOrderByAsc(): void
    {
        $facet = new Facet('brand');

        $this->assertSame('FACET brand ORDER BY brand ASC', (string)$facet->orderBy('brand'));
    }

    public function testFacetOrderByDesc(): void
    {
        $facet = new Facet('brand');

        $this->assertSame('FACET brand ORDER BY count(*) DESC', (string)$facet->orderByDesc('count(*)'));
    }

    public function testFacetSeveralOrders(): void
    {
        $facet = new Facet('brand');
        $facet->orderByDesc('count(*)')->orderBy('brand');

        $this->assertSame('FACET brand ORDER BY count(*) DESC,brand ASC', (string)$facet);
    }

    public function testFacetOrderByWithDirectionArgument(): void
    {
        $facet = new Facet('brand');

        $this->assertSame('FACET brand ORDER BY count(*) DESC', (string)$facet->orderBy('count(*)', 'desc'));
    }

    public function testFacetOrderByRejectsUnknownDirection(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Facet('brand'))->orderBy('brand', 'up');
    }

    public function testFacetLimit(): void
    {
        $facet = new Facet('brand');

        $this->assertSame('FACET brand LIMIT 5', (string)$facet->limit(5));
    }

    public function testFacetLimitWithOffsetArgument(): void
    {
        $facet = new Facet('brand');

        $this->assertSame('FACET brand LIMIT 10,5', (string)$facet->limit(10, 5));
    }

    public function testFacetLimitFromArray(): void
    {
        $facet = new Facet('brand');
        $this->assertSame('FACET brand LIMIT 3', (string)$facet->limit([3]));

        $other = new Facet('brand');
        $this->assertSame('FACET brand LIMIT 5,3', (string)$other->limit([5, 3]));
    }

    public function testFacetOffsetKeepsLimit(): void
    {
        $facet = new Facet('brand');
        $facet->limit(5)->offset(10);

        $this->assertSame('FACET brand LIMIT 10,5', (string)$facet);
    }

    public function testFacetOffsetWithoutLimitRendersNothing(): void
    {
        $facet = new Facet('brand');

        $this->assertSame('FACET brand', (string)$facet->offset(10));
    }

    public function testFullFacetClause(): void
    {
        $facet = new Facet('price');
        $facet->alias('p')->byExpr('INTERVAL(price,200,400)')->orderByDesc('count(*)')->limit(3);

        $this->assertSame(
            'FACET price AS p BY INTERVAL(price,200,400) ORDER BY count(*) DESC LIMIT 3',
            (string)$facet
        );
    }
}
