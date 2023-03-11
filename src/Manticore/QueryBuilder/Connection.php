<?php

declare(strict_types=1);

namespace avadim\Manticore\QueryBuilder;

use avadim\Manticore\QueryBuilder\Client\PDOClient;
use avadim\Manticore\QueryBuilder\Schema\SchemaTable;

class Connection
{
    private array $config;
    private PDOClient $client;
    private ResultSet $lastResultSet;

    /**
     * @param array $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->client = new PDOClient($config);
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

        return new Query($config);
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
     * @param array|SchemaTable|callable $schema
     *
     * @return ResultSet
     */
    public function create(string $name, $schema): ResultSet
    {
        $this->lastResultSet = $this->query()->create($name, $schema);;

        return $this->lastResultSet;
    }

    /**
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
     * @return array
     */
    public function describe(string $tableName): array
    {
        $this->lastResultSet = $this->query()->table($tableName)->describe();

        return $this->lastResultSet->result();
    }

    /**
     * @param string $tableName
     *
     * @return array
     */
    public function describeTable(string $tableName): array
    {
        return $this->describe($tableName);
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
