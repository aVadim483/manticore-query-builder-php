<?php

declare(strict_types=1);

namespace avadim\Manticore\QueryBuilder;

use avadim\Manticore\QueryBuilder\Schema\SchemaIndex;
use avadim\Manticore\QueryBuilder\ResultSet;

class Builder
{
    private static array $config;
    private static array $connections = [];
    private static $logger = null;

    /**
     * @param array $config
     *
     * @return void
     */
    public static function init(array $config, $logger = null)
    {
        self::$config = $config;
        self::$logger = $logger;
    }

    /**
     * @return array
     */
    public static function defaultConfig(): array
    {
        return [
            'defaultConnection' => 'default',
            // default connection params
            'connections' => [
                'default' => [
                    'host' => '127.0.0.1',
                    'port' => 9306,
                    'username' => null,
                    'password' => null,
                    'timeout' => 5,
                    'connection_timeout' => 1,
                    'proxy' => null,
                    'persistent' => true,
                    'retries' => 2,

                    'prefix' => '',
                    'force_prefix' => false,
                ],
            ],
        ];
    }

    /**
     * @param string|null $connectionName
     *
     * @return Connection
     */
    public static function connection(?string $connectionName = null): Connection
    {
        if ($connectionName === null) {
            $connectionName = 'default';
        }
        if (empty(self::$connections[$connectionName])) {
            if (empty(self::$config)) {
                self::$config = self::defaultConfig();
            }
            self::$connections[$connectionName] = new Connection(self::$config['connections'][$connectionName]);
        }

        return self::$connections[$connectionName];
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
     * @return ResultSet
     */
    public static function create(string $name, $schema): ResultSet
    {
        return self::connection()->table($name)->create($schema);
    }

    /**
     * @param string|null $pattern
     *
     * @return ResultSet
     */
    public static function showTables(?string $pattern = null): ResultSet
    {
        return self::connection()->showTables($pattern);
    }

    /**
     * @param string|null $pattern
     *
     * @return ResultSet
     */
    public static  function showVariables(?string $pattern = null): ResultSet
    {
        return self::connection()->showVariables($pattern);
    }
}
