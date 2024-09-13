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

        return new Query($config, null, $this->logger);
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
