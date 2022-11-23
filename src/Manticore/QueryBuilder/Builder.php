<?php

declare(strict_types=1);

namespace avadim\Manticore\QueryBuilder;

use avadim\Manticore\QueryBuilder\Schema\SchemaIndex;
use Manticoresearch\Client;
use Manticoresearch\Index;
use Manticoresearch\ResultHit;
use Manticoresearch\ResultSet;
use Manticoresearch\Search;

class Builder
{
    private static array $config;
    private static Connection $connection;

    /**
     * @param array $config
     *
     * @return void
     */
    public static function init(array $config)
    {
        self::$config = $config;
    }

    /**
     * @return array
     */
    public static function defaultConfig(): array
    {
        return [
            'client' => [
                'defaultConnection' => 'default',
                // default connection params
                'connections' => [
                    'default' => [
                        'host' => 'localhost',
                        'port' => 9308,
                        'transport' => 'Http',
                        'username' => null,
                        'password' => null,
                        'timeout' => 5,
                        'connection_timeout' => 1,
                        'proxy' => null,
                        'persistent' => true,
                        'retries' => 2,
                    ],
                ],
            ],
        ];
    }

    /**
     * @return Connection
     */
    public static function connection(): Connection
    {
        if (empty(self::$connection)) {
            if (empty(self::$config)) {
                self::$config = self::defaultConfig();
            }
            self::$connection = new Connection(self::$config);
        }

        return self::$connection;
    }

    /**
     * @param string $sql
     *
     * @return Query
     */
    public static function sql(string $sql): Query
    {
        return self::connection()->sql($sql);
    }

    /**
     * @param string $name
     *
     * @return Query
     */
    public static function index(string $name): Query
    {
        return self::connection()->index($name);
    }

    /**
     * @param string $name
     *
     * @return Query
     */
    public static function table(string $name): Query
    {
        return self::connection()->table($name);
    }

    /**
     * @param string $name
     * @param array|SchemaIndex|callable $schema
     *
     * @return Result
     */
    public static function create(string $name, $schema): Result
    {
        return self::connection()->create($name, $schema);
    }

    /**
     * @param string|null $pattern
     *
     * @return Result
     */
    public static function showTables(?string $pattern = null): Result
    {
        return self::connection()->showTables($pattern);
    }

    /**
     * @param string|null $pattern
     *
     * @return Result
     */
    public static  function showVariables(?string $pattern = null): Result
    {
        return self::connection()->showVariables($pattern);
    }
}