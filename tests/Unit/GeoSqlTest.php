<?php

namespace avadim\Manticore\Tests\Unit;

use avadim\Manticore\QueryBuilder\Query;
use avadim\Manticore\Tests\Support\UnitTestCase;

final class GeoSqlTest extends UnitTestCase
{
    public function testDistanceIsSharedBySelectionFilterAndOrdering(): void
    {
        $sql = $this->query()->withDistance('lat', 'lon', 55.75, 37.6, 'km')
            ->whereGeoDistance('lat', 'lon', 55.75, 37.6, 10, 'km')
            ->orderByDistance('lat', 'lon', 55.75, 37.6, 'km')
            ->select('id')->addSelect('title')->toSql();
        $this->assertSame(1, substr_count($sql, 'GEODIST('));
        $this->assertStringContainsString('SELECT id, title, GEODIST(lat, lon, 55.75, 37.6, {in=deg, out=km}) as _distance', $sql);
        $this->assertStringContainsString('WHERE (_distance<=10)', $sql);
        $this->assertStringContainsString('ORDER BY _distance ASC', $sql);
    }

    public function testHiddenDistanceSurvivesSelectAndClone(): void
    {
        $query = $this->query()->whereGeoDistance('lat', 'lon', 0, 0, 0)->select('id');
        $copy = clone $query;
        $sql = $copy->orderByDistance('lat', 'lon', 0, 0, 'm', 'desc')->toSql();
        $this->assertSame(1, substr_count($sql, 'GEODIST('));
        $this->assertStringContainsString('ORDER BY _expr1 DESC', $sql);
        $this->assertStringNotContainsString('ORDER BY', $query->toSql());
    }

    public function testJsonPathsAndExplicitExpressions(): void
    {
        $sql = $this->query()->withDistance('location.lat', Query::raw('DOUBLE(location.lon)'), 0, 0, 'mi', 'miles')->toSql();
        $this->assertStringContainsString('GEODIST(DOUBLE(location.lat), DOUBLE(location.lon), 0, 0, {in=deg, out=mi}) as miles', $sql);
    }

    /** @dataProvider invalidOrigins */
    public function testInvalidOriginsAreRejected(array $args): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->query()->withDistance(...$args);
    }

    public function invalidOrigins(): array
    {
        return [
            [['lat', 'lon', 91, 0]], [['lat', 'lon', 0, 181]],
            [['lat', 'lon', NAN, 0]], [['lat', 'lon', 0, INF]],
            [['lat', 'lon', 0, 0, 'bad']], [['lat)', 'lon', 0, 0]],
            [['lat', 'lon', 0, 0, 'm', 'id']], [['lat', 'lon', 0, 0, 'm', 'bad alias']],
        ];
    }

    /** @dataProvider invalidRadii */
    public function testInvalidRadiusIsRejected(float $radius): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->query()->whereGeoDistance('lat', 'lon', 0, 0, $radius);
    }

    public function invalidRadii(): array
    {
        return [[-1], [INF], [NAN]];
    }

    public function testAliasCannotSilentlyChangeAnExistingFilter(): void
    {
        $query = $this->query()->withDistance('lat', 'lon', 0, 0)->whereGeoDistance('lat', 'lon', 0, 0, 10);
        $this->expectException(\InvalidArgumentException::class);
        $query->withDistance('lat', 'lon', 1, 1);
    }

    public function testInvalidOrderingDoesNotMutateQuery(): void
    {
        $query = $this->query();
        try {
            $query->orderByDistance('lat', 'lon', 0, 0, 'm', 'bad');
            $this->fail('Bad direction accepted');
        } catch (\InvalidArgumentException $error) {
            $this->assertSame('SELECT * FROM products', $query->toSql());
        }
    }
}
