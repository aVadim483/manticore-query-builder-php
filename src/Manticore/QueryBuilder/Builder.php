<?php

declare(strict_types=1);

namespace avadim\Manticore\QueryBuilder;

use avadim\Manticore\QueryBuilder\Schema\SchemaTable;
use avadim\Manticore\QueryBuilder\ResultSet;
use Psr\Log\LoggerInterface;

class Builder
{
    private static array $config = [];
    private static array $connections = [];
    private static ?LoggerInterface $logger = null;

    /** @var string the class connection() makes its objects of, see setConnectionClass() */
    private static string $connectionClass = Connection::class;


    /**
     * @param array|null $config
     * @param $logger
     *
     * @return void
     */
    public static function init(?array $config = [], $logger = null)
    {
        // the signature allows null, and an empty config makes connection() fall back
        // to defaultConfig()
        self::$config = $config ?: [];
        self::$logger = $logger;
        self::$connections = [];
        // the connection class is deliberately kept: it is set once by the framework wrapper,
        // usually before the config arrives, and init() is called again on every reconfiguration
    }

    /**
     * Build the connections of a subclass instead of Connection itself.
     *
     * This is what a wrapper for a framework needs to make its own Query answer the queries:
     * Connection::$queryClass alone is not enough, because the connection this builder makes
     * would still be the plain one.
     *
     * @param string $connectionClass a class extending Connection
     *
     * @return void
     */
    public static function setConnectionClass(string $connectionClass): void
    {
        if (!is_a($connectionClass, Connection::class, true)) {
            throw new \InvalidArgumentException('The class "' . $connectionClass . '" does not extend ' . Connection::class);
        }
        if ($connectionClass !== self::$connectionClass) {
            self::$connectionClass = $connectionClass;
            // the connections made so far are of the previous class
            self::$connections = [];
        }
    }

    /**
     * @return string
     */
    public static function connectionClass(): string
    {
        return self::$connectionClass;
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
     * @return array
     */
    public static function currentConfig(): array
    {
        return self::$config;
    }

    /**
     * @param LoggerInterface|false|null $logger
     *
     * @return void
     */
    public static function setLogger($logger)
    {
        self::$logger = $logger ?: null;
        foreach (self::$connections as $connection) {
            $connection->setLogger($logger ?: false);
        }
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
            $connectionClass = self::$connectionClass;
            self::$connections[$connectionName] = new $connectionClass(self::$config['connections'][$connectionName]);
            if (self::$logger) {
                self::$connections[$connectionName]->setLogger(self::$logger);
            }
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
     *
     * @return bool
     */
    public static function hasTable(string $name): bool
    {
        return self::connection()->hasTable($name);
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
        return self::connection()->create($name, $schema, $options);
    }

    /**
     * @param string $name
     * @param array|SchemaTable|callable $schema
     * @param array|null $options
     *
     * @return ResultSet
     */
    public static function createIfNotExists(string $name, $schema, ?array $options = []): ResultSet
    {
        return self::connection()->create($name, $schema, $options, true);
    }

    /**
     * @param string $name
     * @param bool|null $ifExists
     *
     * @return ResultSet
     */
    public static function drop(string $name, ?bool $ifExists = false): ResultSet
    {
        return self::connection()->drop($name, $ifExists);
    }

    /**
     * @param string $name
     *
     * @return ResultSet
     */
    public static function dropIfExists(string $name): ResultSet
    {
        return self::connection()->drop($name, true);
    }

    /**
     * ALTER TABLE ... ADD COLUMN
     *
     * @param string $tableName
     * @param string $columnName
     * @param string|array $type
     * @param string|array|null $options
     *
     * @return ResultSet
     */
    public static function addColumn(string $tableName, string $columnName, $type, $options = null): ResultSet
    {
        return self::connection()->addColumn($tableName, $columnName, $type, $options);
    }

    /**
     * ALTER TABLE ... DROP COLUMN
     *
     * @param string $tableName
     * @param string|array $columnName
     *
     * @return ResultSet
     */
    public static function dropColumn(string $tableName, $columnName): ResultSet
    {
        return self::connection()->dropColumn($tableName, $columnName);
    }

    /**
     * ALTER TABLE ... MODIFY COLUMN
     *
     * @param string $tableName
     * @param string $columnName
     * @param string $type
     *
     * @return ResultSet
     */
    public static function modifyColumn(string $tableName, string $columnName, string $type): ResultSet
    {
        return self::connection()->modifyColumn($tableName, $columnName, $type);
    }

    /**
     * ALTER TABLE ... RENAME
     *
     * @param string $tableName
     * @param string $newName
     *
     * @return ResultSet
     */
    public static function rename(string $tableName, string $newName): ResultSet
    {
        return self::connection()->rename($tableName, $newName);
    }

    /**
     * ALTER TABLE ... <setting>='<value>'
     *
     * @param string $tableName
     * @param array $settings
     *
     * @return ResultSet
     */
    public static function alterSettings(string $tableName, array $settings): ResultSet
    {
        return self::connection()->alterSettings($tableName, $settings);
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

    /**
     * The ResultSet of the last statement of the given connection.
     *
     * The way to get at the error text after a scalar-answering write, i.e.
     *      if (!ManticoreDb::table('?products')->insert($row)) {
     *          $error = ManticoreDb::lastResultSet()->error();
     *      }
     *
     * @param string|null $connectionName
     *
     * @return ResultSet|null
     */
    public static function lastResultSet(?string $connectionName = null): ?ResultSet
    {
        return self::connection($connectionName)->lastResultSet();
    }
}
