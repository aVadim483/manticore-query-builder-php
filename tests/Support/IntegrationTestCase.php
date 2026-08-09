<?php

namespace avadim\Manticore\Tests\Support;

use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use avadim\Manticore\QueryBuilder\Connection;
use avadim\Manticore\QueryBuilder\Parser;
use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that need a live Manticore server.
 *
 * Guarantees, in contrast to ad-hoc test scripts:
 *   - every test starts from a known config (init() in setUp), so tests do not inherit
 *     the static state of whoever ran before them and behave the same under --filter;
 *   - every table created through the helpers is dropped in tearDown even if the test failed,
 *     so a red run does not leave garbage behind;
 *   - the whole suite is skipped, not errored, when no server is listening.
 */
abstract class IntegrationTestCase extends TestCase
{
    public const CONNECTION_1 = 'test1';
    public const CONNECTION_2 = 'test2';

    public const PREFIX_1 = 'test_';
    public const PREFIX_2 = 'second_';

    /** @var array<string, string> real table names to drop afterwards, keyed by themselves */
    private array $createdTables = [];

    /** @var bool|null cached reachability of the server */
    private static ?bool $serverAvailable = null;

    /**
     * @return string
     */
    protected static function host(): string
    {
        return getenv('MANTICORE_HOST') ?: '127.0.0.1';
    }

    /**
     * @return int
     */
    protected static function port(): int
    {
        return (int)(getenv('MANTICORE_PORT') ?: 9306);
    }

    /**
     * @return bool
     */
    protected static function serverAvailable(): bool
    {
        if (self::$serverAvailable === null) {
            $socket = @fsockopen(self::host(), self::port(), $errNo, $errStr, 2);
            self::$serverAvailable = (bool)$socket;
            if ($socket) {
                fclose($socket);
            }
        }

        return self::$serverAvailable;
    }

    /**
     * Two connections to the same server with different prefixes
     *
     * @return array
     */
    protected function config(): array
    {
        return [
            'defaultConnection' => self::CONNECTION_1,
            'connections' => [
                self::CONNECTION_1 => [
                    'host' => self::host(),
                    'port' => self::port(),
                    'username' => null,
                    'password' => null,
                    'timeout' => 5,
                    'prefix' => self::PREFIX_1,
                    'force_prefix' => false,
                ],
                self::CONNECTION_2 => [
                    'host' => self::host(),
                    'port' => self::port(),
                    'prefix' => self::PREFIX_2,
                ],
            ],
        ];
    }

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        if (!self::serverAvailable()) {
            $this->markTestSkipped(sprintf('Manticore is not available on %s:%d', self::host(), self::port()));
        }
        ManticoreDb::init($this->config());
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        if ($this->createdTables) {
            try {
                // a prefix-less connection of its own: a test may have re-inited the builder
                // with a different prefix, and the names collected here are already resolved
                $cleanup = new Connection(['host' => self::host(), 'port' => self::port(), 'timeout' => 5]);
                foreach ($this->createdTables as $table) {
                    $cleanup->drop($table, true);
                }
            }
            catch (\Throwable $e) {
                // cleanup must never mask the result of the test itself
            }
        }
        $this->createdTables = [];
        ManticoreDb::setLogger(false);

        parent::tearDown();
    }

    /**
     * Unique table name, registered for automatic cleanup.
     *
     * @param string|null $suffix
     * @param string|null $connection
     *
     * @return string
     */
    protected function tableName(?string $suffix = null, ?string $connection = self::CONNECTION_1): string
    {
        $name = 'qb_' . str_replace('.', '', uniqid('', true)) . ($suffix ? '_' . $suffix : '');
        $this->registerTable($name, $connection);

        return $name;
    }

    /**
     * Register a name (possibly a "?placeholder" one) for automatic cleanup.
     *
     * The name is resolved against the config that is active right now, so that a test which
     * re-inits the builder with another prefix still gets its earlier tables dropped.
     *
     * @param string $table
     * @param string|null $connection
     *
     * @return string
     */
    protected function registerTable(string $table, ?string $connection = self::CONNECTION_1): string
    {
        $config = ManticoreDb::currentConfig();
        $connectionConfig = $config['connections'][$connection ?: self::CONNECTION_1] ?? [];
        $real = Parser::resolveTableName(
            $table,
            $connectionConfig['prefix'] ?? '',
            !empty($connectionConfig['force_prefix'])
        );
        $this->createdTables[$real] = $real;

        return $table;
    }

    /**
     * Create a table and register it for cleanup.
     *
     * @param array|callable $schema
     * @param string|null $suffix
     * @param array|null $options
     * @param string|null $connection
     *
     * @return string created table name
     */
    protected function createTable($schema, ?string $suffix = null, ?array $options = [], ?string $connection = self::CONNECTION_1): string
    {
        $table = $this->tableName($suffix, $connection);
        $query = ManticoreDb::connection($connection)->table($table);
        if ($options) {
            $query->options($options);
        }
        $result = $query->create($schema);
        $this->assertTrue($result->success(), 'Cannot create table ' . $table . ': ' . $result->error());

        return $table;
    }

    /**
     * Standard products-like table used by several tests.
     *
     * @param string|null $suffix
     *
     * @return string
     */
    protected function createProductsTable(?string $suffix = 'products'): string
    {
        return $this->createTable([
            'created_at' => 'timestamp',
            'manufacturer' => 'string',
            'title' => 'text',
            'info' => 'json',
            'price' => ['type' => 'float'],
            'categories' => 'multi',
            'on_sale' => 'bool',
        ], $suffix);
    }
}
