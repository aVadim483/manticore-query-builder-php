<?php

namespace avadim\Manticore\Tests\Unit;

use avadim\Manticore\QueryBuilder\Meta;
use avadim\Manticore\QueryBuilder\ResultSet;
use PHPUnit\Framework\TestCase;

final class MetaTest extends TestCase
{
    public function testServerFieldsAndTypedAccessors(): void
    {
        $meta = Meta::fromRows([
            ['Variable_name' => 'total', 'Value' => '2'],
            ['Variable_name' => 'total_found', 'Value' => '30'],
            ['Variable_name' => 'time', 'Value' => '0.012'],
            ['Variable_name' => 'docs[0]', 'Value' => '15'],
            ['Variable_name' => 'keyword[0]', 'Value' => 'телефон'],
            ['Variable_name' => 'future_field', 'Value' => 'yes'],
        ]);
        $this->assertSame(30, $meta['total_found']);
        $this->assertSame(30, $meta->totalFound());
        $this->assertSame(2, $meta->total());
        $this->assertSame(0.012, $meta->time());
        $this->assertSame(15, $meta['docs[0]']);
        $this->assertTrue(isset($meta['future_field']));
        $this->assertSame(6, count($meta));
        $this->assertSame($meta->toArray(), iterator_to_array($meta));
        $copy = $meta->toArray();
        $copy['total'] = 99;
        $this->assertSame(2, $meta->total());
    }

    public function testMissingValuesAreUnknownRatherThanZero(): void
    {
        $meta = new Meta();
        $this->assertNull($meta->total());
        $this->assertNull($meta->totalFound());
        $this->assertNull($meta->time());
        $this->assertNull($meta['missing']);
        $this->assertFalse(isset($meta['missing']));
        $this->assertCount(0, $meta);
    }

    public function testCannotWriteFields(): void
    {
        $meta = new Meta(['total' => 1]);
        $this->expectException(\LogicException::class);
        $meta['total'] = 2;
    }

    public function testCannotRemoveFields(): void
    {
        $meta = new Meta(['total' => 1]);
        $this->expectException(\LogicException::class);
        unset($meta['total']);
    }

    public function testResultSetKeepsArrayApiAndTracksAvailableMetadata(): void
    {
        $result = ResultSet::of([['id' => 1]], ['total_found' => 10]);
        $this->assertTrue($result->hasMeta());
        $this->assertIsArray($result->meta());
        $this->assertSame($result->meta(), $result->metaObject()->toArray());
        $this->assertSame(10, $result->metaObject()->totalFound());
        $this->assertFalse((new ResultSet([]))->hasMeta());
        $this->assertTrue((new ResultSet(['meta' => []]))->hasMeta());
    }
}
