<?php

declare(strict_types=1);

namespace avadim\Manticore\QueryBuilder;

use avadim\Manticore\QueryBuilder\Client\PDOClient;
use avadim\Manticore\QueryBuilder\Schema\SchemaTable;
use Psr\Log\LoggerInterface;

class Connection
{
    private array $config;
    private PDOClient $client;
    private ResultSet $lastResultSet;
    private ?LoggerInterface $logger = null;
    private array $logEnabled = [];

    /** @var array schema cache shared by every Query of this connection, see Query::setSchemaPool() */
    private array $schemaPool = [];


    /**
     * @param array $config
     * @param LoggerInterface|null $logger
     */
    public function __construct(array $config, ?LoggerInterface $logger = null)
    {
        $this->config = $config;
        $this->client = new PDOClient($config);
        $this->logger = $logger;
    }

    /**
     * @param LoggerInterface|false|null $logger
     *
     * @return $this
     */
    public function setLogger($logger): Connection
    {
        if ($logger) {
            $this->logger = $logger;
        }
        elseif ($logger === false) {
            $this->logger = null;
        }

        return $this;
    }

    /**
     * Create new query object
     *
     * @return Query
     */
    public function query(): Query
    {
        $config = $this->config;
        $config['client'] = $this->client;

        $query = new Query($config, null, $this->logger);
        // one DESCRIBE per table for the whole connection, not per built query
        $query->setSchemaPool($this->schemaPool);

        return $query;
    }

    /**
     * Forget the cached schemas, e.g. after the tables were changed from the outside
     *
     * @return $this
     */
    public function forgetSchema(): Connection
    {
        $this->schemaPool = [];

        return $this;
    }

    /**
     * @param string $sql
     *
     * @return Query
     */
    public function sql(string $sql): Query
    {
        $query = $this->query();

        return $query->sql($sql);
    }

    /**
     * Alias for table()
     *
     * @param string $name
     *
     * @return Query
     */
    public function index(string $name): Query
    {
        return $this->table($name);
    }

    /**
     * @param string $name
     *
     * @return Query
     */
    public function table(string $name): Query
    {
        return $this->query()->table($name);
    }

    /**
     * @param string $name
     *
     * @return bool
     */
    public function hasTable(string $name): bool
    {
        return $this->query()->hasTable($name);
    }
    /**
     * @param string $name
     * @param array|SchemaTable|callable $schema
     * @param array|null $options
     * @param bool|null $ifNotExists
     *
     * @return ResultSet
     */
    public function create(string $name, $schema, ?array $options = [], ?bool $ifNotExists = false): ResultSet
    {
        $query = $this->query()->table($name);
        if ($options) {
            $query->options($options);
        }

        if ($ifNotExists) {
            $query->ifNotExists();
        }
        $this->lastResultSet = $query->create($schema);

        return $this->lastResultSet;
    }

    /**
     * @param string $name
     * @param bool|null $ifExists
     *
     * @return ResultSet
     */
    public function drop(string $name, ?bool $ifExists = false): ResultSet
    {
        return $this->query()->table($name)->drop($ifExists);
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
    public function addColumn(string $tableName, string $columnName, $type, $options = null): ResultSet
    {
        $this->lastResultSet = $this->query()->table($tableName)->addColumn($columnName, $type, $options);

        return $this->lastResultSet;
    }

    /**
     * ALTER TABLE ... DROP COLUMN
     *
     * @param string $tableName
     * @param string|array $columnName
     *
     * @return ResultSet
     */
    public function dropColumn(string $tableName, $columnName): ResultSet
    {
        $this->lastResultSet = $this->query()->table($tableName)->dropColumn($columnName);

        return $this->lastResultSet;
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
    public function modifyColumn(string $tableName, string $columnName, string $type): ResultSet
    {
        $this->lastResultSet = $this->query()->table($tableName)->modifyColumn($columnName, $type);

        return $this->lastResultSet;
    }

    /**
     * ALTER TABLE ... RENAME
     *
     * @param string $tableName
     * @param string $newName
     *
     * @return ResultSet
     */
    public function rename(string $tableName, string $newName): ResultSet
    {
        $this->lastResultSet = $this->query()->table($tableName)->rename($newName);

        return $this->lastResultSet;
    }

    /**
     * ALTER TABLE ... <setting>='<value>'
     *
     * @param string $tableName
     * @param array $settings
     *
     * @return ResultSet
     */
    public function alterSettings(string $tableName, array $settings): ResultSet
    {
        $this->lastResultSet = $this->query()->table($tableName)->alterSettings($settings);

        return $this->lastResultSet;
    }

    /**
     * SHOW TABLES
     *
     * @param string|null $pattern
     *
     * @return array
     */
    public function showTables(?string $pattern = null): array
    {
        $this->lastResultSet = $this->query()->showTables($pattern);

        return $this->lastResultSet->result();
    }

    /**
     * SHOW TABLE $tableName STATUS
     *
     * @param string $tableName
     *
     * @return array
     */
    public function tableStatus(string $tableName): array
    {
        $this->lastResultSet = $this->query()->table($tableName)->status($tableName);

        return $this->lastResultSet->variables();
    }

    /**
     * SHOW TABLE $tableName SETTINGS
     *
     * @param string $tableName
     *
     * @return array
     */
    public function tableSettings(string $tableName): array
    {
        $this->lastResultSet = $this->query()->table($tableName)->settings($tableName);

        return $this->lastResultSet->variables();
    }

    /**
     * @param string $tableName
     *
     * @return array
     */
    public function tableDescribe(string $tableName): array
    {
        $this->lastResultSet = $this->query()->table($tableName)->describe();
        $result = [];
        foreach ($this->lastResultSet->result() as $col) {
            $result[$col['Field']] = $col;
        }

        return $result;
    }

    /**
     * @param string|null $pattern
     *
     * @return array
     */
    public function showVariables(?string $pattern = null): array
    {
        $this->lastResultSet = $this->query()->showVariables($pattern);

        return $this->lastResultSet->result();
    }

    /**
     * @param string $tableName
     *
     * @return string
     */
    public function showCreate(string $tableName): string
    {
        $this->lastResultSet = $this->query()->table($tableName)->showCreate();
        $result = $this->lastResultSet->result();

        return $result['Create Table'] ?? '';
    }

    /**
     * @param string $tableName
     *
     * @return string
     */
    public function showCreateTable(string $tableName): string
    {
        return $this->showCreate($tableName);
    }

    /**
     * @return ResultSet|null
     */
    public function lastResultSet(): ?ResultSet
    {
        return $this->lastResultSet ?? null;
    }
}
