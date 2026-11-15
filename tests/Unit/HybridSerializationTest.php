<?php

namespace avadim\Manticore\Tests\Unit;

use avadim\Manticore\QueryBuilder\HybridResult;
use avadim\Manticore\QueryBuilder\Query;
use avadim\Manticore\QueryBuilder\ResultSet;
use avadim\Manticore\Tests\Support\FakeClient;
use PHPUnit\Framework\TestCase;

final class HybridSerializationTest extends TestCase
{
    public function testDetailedExportPreservesRanksScoresMetadataAndExactIds(): void
    {
        $id = '18446744073709551614';
        $result = HybridResult::fuse(
            ResultSet::of([['id' => $id, 'title' => 'phone', '_score' => 20]], ['time' => 0.125]),
            ResultSet::of([['id' => $id, 'title' => 'vector', '_knn_dist' => 0.5]]),
            ['rank_constant' => 0]
        );
        $before = $result->rows();
        $export = $result->toArray();

        $this->assertSame(['hits', 'window_total', 'total_found', 'text_meta', 'vector_meta'], array_keys($export));
        $this->assertSame(1, $export['window_total']);
        $this->assertNull($export['total_found']);
        $this->assertSame(['time' => 0.125, 'total' => 1, 'total_found' => 1], $export['text_meta']);
        $this->assertSame(['total' => 1, 'total_found' => 1], $export['vector_meta']);
        $hit = $export['hits'][0];
        $this->assertSame($id, $hit['id']);
        $this->assertSame($id, $hit['document']['id']);
        $this->assertSame('phone', $hit['document']['title']);
        $this->assertSame(2.0, $hit['score']);
        $this->assertSame(1, $hit['textRank']);
        $this->assertSame(1, $hit['vectorRank']);
        $this->assertSame(20.0, $hit['textScore']);
        $this->assertSame(0.5, $hit['distance']);

        $json = json_encode($result, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        $this->assertSame($export, json_decode($json, true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame($hit, json_decode(json_encode($result->firstHit(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true));
        $this->assertSame($before, $result->rows());
        $export['hits'][0]['document']['title'] = 'changed export';
        $this->assertSame('phone', $result->first()['title']);
    }

    public function testEmptyWindowAndAbsentMetadataStayExplicit(): void
    {
        $result = HybridResult::fuse(null, null);
        $expected = ['hits' => [], 'window_total' => 0, 'total_found' => null, 'text_meta' => null, 'vector_meta' => null];
        $this->assertSame($expected, $result->toArray());
        $this->assertSame($expected, json_decode(json_encode($result, JSON_THROW_ON_ERROR), true));
    }

    public function testLoadedEmptyMetadataDiffersFromMissingMetadata(): void
    {
        $empty = ['command' => 'SELECT', 'result' => ['type' => 'array', 'data' => []]];
        $result = HybridResult::fuse(new ResultSet($empty + ['meta' => []]), new ResultSet($empty));
        $this->assertSame([], $result->toArray()['text_meta']);
        $this->assertNull($result->toArray()['vector_meta']);
    }

    public function testOneBranchExportsNullEvidenceForTheOther(): void
    {
        $result = HybridResult::fuse(null, ResultSet::of([['id' => 7, '_knn_dist' => 0.25]]));
        $hit = $result->toArray()['hits'][0];
        $this->assertNull($hit['textRank']);
        $this->assertNull($hit['textScore']);
        $this->assertSame(1, $hit['vectorRank']);
        $this->assertSame(0.25, $hit['distance']);
        $this->assertNull($result->toArray()['text_meta']);
    }

    public function testExportDoesNotRunQueriesOrChangeTheLastResult(): void
    {
        $client = new FakeClient(['id' => 'bigint', 'vec' => 'float_vector']);
        $client->selectData = [[['id' => 1, 'title' => 'phone']]];
        $query = new Query(['client' => $client], 'products');
        $result = $query->hybridSearch('phone', 'vec', [1, 0]);
        $commands = $client->queries;
        $last = $query->lastResultSet();

        $result->toArray();
        json_encode($result, JSON_THROW_ON_ERROR);
        json_encode($result->firstHit(), JSON_THROW_ON_ERROR);

        $this->assertSame($commands, $client->queries);
        $this->assertSame($last, $query->lastResultSet());
    }

    public function testInvalidUtf8UsesTheCallersJsonErrorPolicy(): void
    {
        $result = HybridResult::fuse(ResultSet::of([['id' => 1, 'title' => "\xB1"]]), null);
        $this->assertFalse(json_encode($result));
        $this->expectException(\JsonException::class);
        json_encode($result, JSON_THROW_ON_ERROR);
    }
}
