<?php

namespace avadim\Manticore\Tests\Support;

use avadim\Manticore\QueryBuilder\Query;
use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that must not touch a Manticore server.
 */
abstract class UnitTestCase extends TestCase
{
    /**
     * Build a Query backed by FakeClient, so that toSql() works without a connection.
     *
     * @param string|null $table
     * @param array<string, string>|null $columnTypes DESCRIBE answer: column => Manticore type
     * @param array|null $config extra connection config (prefix, force_prefix, ...)
     *
     * @return Query
     */
    protected function query(?string $table = 'products', ?array $columnTypes = [], ?array $config = []): Query
    {
        $config = array_merge(['client' => new FakeClient($columnTypes)], $config ?: []);

        return new Query($config, $table);
    }

    /**
     * Build a Query on top of an explicit client, so that the test can read back
     * the SQL of commands that are sent immediately (INSERT/UPDATE/REPLACE/DELETE).
     *
     * @param FakeClient $client
     * @param string|null $table
     * @param array|null $config
     *
     * @return Query
     */
    protected function queryFor(FakeClient $client, ?string $table = 'products', ?array $config = []): Query
    {
        return new Query(array_merge(['client' => $client], $config ?: []), $table);
    }

    /**
     * Column types of the products table used across the SQL tests.
     *
     * @return array<string, string>
     */
    protected function productColumnTypes(): array
    {
        return [
            'id' => 'bigint',
            'created_at' => 'timestamp',
            'manufacturer' => 'string',
            'title' => 'text',
            'info' => 'json',
            'price' => 'float',
            'categories' => 'mva',
            'on_sale' => 'bool',
        ];
    }

    /**
     * Collapse runs of whitespace so that assertions do not depend on incidental spacing.
     *
     * @param string $sql
     *
     * @return string
     */
    protected function normalize(string $sql): string
    {
        return trim(preg_replace('/\s+/', ' ', $sql));
    }

    /**
     * @param string $expected
     * @param string $actual
     * @param string $message
     *
     * @return void
     */
    protected function assertSqlSame(string $expected, string $actual, string $message = ''): void
    {
        $this->assertSame($this->normalize($expected), $this->normalize($actual), $message);
    }
}
