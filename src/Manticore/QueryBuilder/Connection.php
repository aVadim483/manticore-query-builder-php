<?php

declare(strict_types=1);

namespace avadim\Manticore\QueryBuilder;

use avadim\Manticore\QueryBuilder\Client\PDOClient;
use avadim\Manticore\QueryBuilder\Schema\SchemaTable;

class Connection
{
    private array $config;
    private PDOClient $client;

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
        return $this->query()->create($name, $schema);
    }

    /**
     * @param string|null $pattern
     *
     * @return ResultSet
     */
    public function showTables(?string $pattern = null): ResultSet
    {
        return $this->query()->showTables($pattern);
    }

    /**
     * @param string|null $pattern
     *
     * @return ResultSet
     */
    public function showVariables(?string $pattern = null): ResultSet
    {
        return $this->query()->showVariables($pattern);
    }

}
