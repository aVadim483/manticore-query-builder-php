<?php

namespace avadim\Manticore\Tests\Integration;

use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use avadim\Manticore\Tests\Support\IntegrationTestCase;

/**
 * Faceted search: facets arrive as extra rowsets, data[$n + 1] belonging to the n-th facet.
 */
final class FacetSearchTest extends IntegrationTestCase
{
    /** @var string */
    private string $table;

    protected function setUp(): void
    {
        parent::setUp();
        $this->table = $this->createTable([
            'title' => 'text',
            'brand' => 'string',
            'color' => 'string',
            'price' => 'float',
        ], 'facets');
        ManticoreDb::table($this->table)->insert([
            ['id' => 1, 'title' => 'phone one', 'brand' => 'Samsung', 'color' => 'red', 'price' => 100.0],
            ['id' => 2, 'title' => 'phone two', 'brand' => 'Samsung', 'color' => 'blue', 'price' => 200.0],
            ['id' => 3, 'title' => 'phone three', 'brand' => 'Xiaomi', 'color' => 'red', 'price' => 300.0],
            ['id' => 4, 'title' => 'tablet one', 'brand' => 'Apple', 'color' => 'white', 'price' => 900.0],
        ]);
    }

    public function testSingleFacetIsReturnedAlongsideRows(): void
    {
        $result = ManticoreDb::table($this->table)->match('phone')->facet('brand')->search();

        $this->assertTrue($result->success(), (string)$result->error());
        $this->assertCount(3, $result->result(), 'the main rowset is still there');

        $facets = $result->facets();
        $this->assertCount(1, $facets);

        $brands = array_column($facets[0], '_count', 'brand');
        $this->assertSame(['Samsung' => 2, 'Xiaomi' => 1], $brands);
    }

    public function testFacetRowsCarryCountColumn(): void
    {
        $result = ManticoreDb::table($this->table)->facet('color')->search();

        $facet = $result->facets(0);
        $row = reset($facet);

        $this->assertArrayHasKey('_count', $row);
        $this->assertArrayHasKey('count(*)', $row);
        $this->assertSame($row['count(*)'], $row['_count']);
    }

    public function testSeveralFacetsKeepTheirOrder(): void
    {
        $result = ManticoreDb::table($this->table)->facet('brand')->facet('color')->search();

        $facets = $result->facets();
        $this->assertCount(2, $facets);

        $this->assertSame(['Samsung', 'Xiaomi', 'Apple'], array_column($facets[0], 'brand'));
        $this->assertSame(
            ['red' => 2, 'blue' => 1, 'white' => 1],
            array_column($facets[1], '_count', 'color')
        );
    }

    public function testFacetsIndexAccessReturnsSingleFacet(): void
    {
        $result = ManticoreDb::table($this->table)->facet('brand')->facet('color')->search();

        $this->assertSame($result->facets()[1], $result->facets(1));
    }

    public function testFacetWithAliasAndOrder(): void
    {
        $result = ManticoreDb::table($this->table)
            ->facet('brand', function ($facet) {
                $facet->alias('b')->orderByDesc('count(*)')->limit(2);
            })
            ->search();

        $this->assertTrue($result->success(), (string)$result->error());

        $facet = $result->facets(0);
        $this->assertCount(2, $facet, 'facet limit must be applied');
        $this->assertSame('Samsung', reset($facet)['b'], 'the alias becomes the column name');
    }

    public function testFacetByExpression(): void
    {
        $result = ManticoreDb::table($this->table)
            ->facet('price', function ($facet) {
                $facet->byExpr('INTERVAL(price,150,500)')->alias('range');
            })
            ->search();

        $this->assertTrue($result->success(), (string)$result->error());
        $this->assertNotEmpty($result->facets(0));
    }

    public function testFacetIsFilteredByTheMainQuery(): void
    {
        $result = ManticoreDb::table($this->table)->where('price', '>', 250)->facet('brand')->search();

        $brands = array_column($result->facets(0), '_count', 'brand');

        $this->assertSame(['Xiaomi' => 1, 'Apple' => 1], $brands);
    }

    public function testNoFacetsWhenNoneRequested(): void
    {
        $result = ManticoreDb::table($this->table)->search();

        $this->assertSame([], $result->facets());
    }
}
