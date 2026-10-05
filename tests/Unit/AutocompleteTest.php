<?php

namespace avadim\Manticore\Tests\Unit;

use avadim\Manticore\QueryBuilder\QueryErrorException;
use avadim\Manticore\Tests\Support\FakeClient;
use avadim\Manticore\Tests\Support\UnitTestCase;

final class AutocompleteTest extends UnitTestCase
{
    public function testSqlAndPrefix(): void
    {
        $client = new FakeClient();
        $this->assertSame([], $this->queryFor($client, '?products', ['prefix' => 'pre_'])
            ->callAutocomplete('manti', ['fuzziness' => 0, 'append' => true, 'layouts' => 'us,ru']));
        $this->assertSame("CALL AUTOCOMPLETE('manti', 'pre_products', 0 AS fuzziness, 1 AS append, 'us,ru' AS layouts)", $client->lastQuery());
    }

    public function testEscaping(): void
    {
        $client = new FakeClient();
        $this->queryFor($client)->callAutocomplete("it's\\", null);
        $this->assertSame("CALL AUTOCOMPLETE('it\\'s\\\\', 'products')", $client->lastQuery());
    }

    public function testNumericSuggestionStaysText(): void
    {
        $client = new class extends FakeClient {
            public function query(string $query, ?array $params = []): array
            {
                return ['data' => [['query' => '12345']], 'count' => 1];
            }
        };
        $this->assertSame([['query' => '12345']], $this->queryFor($client)->callAutocomplete('123'));
    }

    public function testServerFailureIsRaised(): void
    {
        $client = new class extends FakeClient {
            public function query(string $query, ?array $params = []): array
            {
                throw new \RuntimeException('Buddy unavailable');
            }
        };
        $this->expectException(QueryErrorException::class);
        $this->expectExceptionMessage('Buddy unavailable');
        $this->queryFor($client)->callAutocomplete('manti');
    }

    public function testInvalidOptionIsRejectedBeforeExecution(): void
    {
        $client = new FakeClient();
        try {
            $this->queryFor($client)->callAutocomplete('manti', ['bad) option' => 1]);
            $this->fail('Invalid option accepted');
        } catch (\InvalidArgumentException $error) {
            $this->assertSame([], $client->queries);
        }
    }
}
