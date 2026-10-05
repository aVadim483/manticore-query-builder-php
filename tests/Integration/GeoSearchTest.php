<?php

namespace avadim\Manticore\Tests\Integration;

use avadim\Manticore\QueryBuilder\Builder as Db;
use avadim\Manticore\Tests\Support\IntegrationTestCase;

final class GeoSearchTest extends IntegrationTestCase
{
    private string $table;

    protected function setUp(): void
    {
        parent::setUp();
        $this->table = $this->createTable(['title' => 'text', 'lat' => 'float', 'lon' => 'float', 'location' => 'json']);
        $this->assertTrue(Db::table($this->table)->insert([
            ['id' => 1, 'title' => 'origin', 'lat' => 0, 'lon' => 0, 'location' => ['lat' => 0, 'lon' => 0]],
            ['id' => 2, 'title' => 'near', 'lat' => 0, 'lon' => 0.01, 'location' => ['lat' => 0, 'lon' => 0.01]],
            ['id' => 3, 'title' => 'far', 'lat' => 0, 'lon' => 1, 'location' => ['lat' => 0, 'lon' => 1]],
        ]));
    }

    public function testRadiusOrderingAndVisibleDistance(): void
    {
        $rows = Db::table($this->table)->withDistance('lat', 'lon', 0, 0, 'km')
            ->whereGeoDistance('lat', 'lon', 0, 0, 2, 'km')->orderByDistance('lat', 'lon', 0, 0, 'km', 'desc')->get(['id']);
        $this->assertSame([2, 1], array_column($rows, 'id'));
        $this->assertEqualsWithDelta(1.113, $rows[2]['_distance'], 0.03);
        $this->assertIsFloat($rows[2]['_distance']);
        $this->assertEqualsWithDelta(0, $rows[1]['_distance'], 0.001);
    }

    public function testZeroRadiusAndInternalColumnRemoval(): void
    {
        $rows = Db::table($this->table)->whereGeoDistance('lat', 'lon', 0, 0, 0)
            ->orderByDistance('lat', 'lon', 0, 0)->get(['id']);
        $this->assertSame([1 => ['id' => 1]], $rows);
    }

    public function testUnitsAndJsonCoordinates(): void
    {
        $row = Db::table($this->table)->withDistance('lat', 'lon', 0, 0, 'm', 'meters')
            ->withDistance('location.lat', 'location.lon', 0, 0, 'km', 'kilometers')
            ->withDistance('lat', 'lon', 0, 0, 'mi', 'miles')->find(2);
        $this->assertEqualsWithDelta($row['meters'] / 1000, $row['kilometers'], 0.001);
        $this->assertEqualsWithDelta($row['meters'] / 1609.344, $row['miles'], 0.001);
        $this->assertSame([1, 2], Db::table($this->table)->whereGeoDistance('location.lat', 'location.lon', 0, 0, 2, 'km')->orderBy('id')->pluck('id'));
    }
}
