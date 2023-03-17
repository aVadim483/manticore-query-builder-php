<?php

declare(strict_types=1);

namespace avadim\Manticore\QueryBuilder;

use avadim\Manticore\QueryBuilder\Schema\SchemaTable;
use avadim\Manticore\QueryBuilder\ResultSet;

class Builder
{
    private static array $config;
    private static array $connections = [];
    private static $logger = null;

    /**
     * @param array|null $config
     * @param $logger
     *
     * @return void
     */
    public static function init(?array $config = [], $logger = null)
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
        if (!$connectionName) {
            $connectionName = self::$config['defaultConnection'] ?? 'default';
        }
        if (empty(self::$connections[$connectionName])) {
            if (empty(self::$config)) {
                self::$config = self::defaultConfig();
            }
            if (!isset(self::$config['connections'][$connectionName])) {
                throw new \RuntimeException('The connection named "' . $connectionName . '" was not defined in the config');
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
     * Alias for table()
     *
     * @param string $name
     *
     * @return Query
     */
    public static function index(string $name): Query
    {
        return self::connection()->table($name);
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
     * @param array|SchemaTable|callable $schema
     * @param array|null $options
     *
     * @return ResultSet
     */
    public static function create(string $name, $schema, ?array $options = []): ResultSet
    {
        $query = self::connection()->table($name);
        if ($options) {
            $query->options($options);
        }

        return $query->create($schema);
    }

    /**
     * @param string $tableName
     *
     * @return array
     */
    public static function tableStatus(string $tableName): array
    {
        return self::connection()->tableStatus($tableName);
    }

    /**
     * @param string $tableName
     *
     * @return array
     */
    public static function tableSettings(string $tableName): array
    {
        return self::connection()->tableSettings($tableName);
    }

    /**
     * @param string $tableName
     *
     * @return array
     */
    public static function tableDescribe(string $tableName): array
    {
        return self::connection()->tableDescribe($tableName);
    }

    /**
     * @param string $tableName
     *
     * @return array
     */
    public static function describe(string $tableName): array
    {
        return self::tableDescribe($tableName);
    }

    /**
     * Returns array of all currently active tables along with their types
     *
     * @param string|null $pattern
     *
     * @return array
     */
    public static function showTables(?string $pattern = null): array
    {
        return self::connection()->showTables($pattern);
    }

    /**
     * Returns the current values of a few server-wide variables
     *
     * @param string|null $pattern
     *
     * @return array
     */
    public static  function showVariables(?string $pattern = null): array
    {
        return self::connection()->showVariables($pattern);
    }

    /**
     * Returns the CREATE TABLE statement used to create the specified table
     *
     * @param string $tableName
     *
     * @return string
     */
    public static  function showCreate(string $tableName): string
    {
        return self::connection()->showCreate($tableName);
    }
}
