<?php

declare(strict_types=1);

namespace avadim\Manticore\QueryBuilder;

use avadim\Manticore\QueryBuilder\Client\PDOClient;
use avadim\Manticore\QueryBuilder\Schema\SchemaIndex;
use Manticoresearch\Client;

class Connection
{
    private array $config;
    private PDOClient $client;


    public function __construct($config)
    {
        $this->config = $config;
        $this->client = new PDOClient($config['client']);
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
     * @param string $name
     *
     * @return Query
     */
    public function table(string $name): Query
    {
        return $this->index($name);
    }

    /**
     * @param string $name
     *
     * @return Query
     */
    public function index(string $name): Query
    {
        return $this->query()->index($name);
    }

    /**
     * @param string $name
     * @param array|SchemaIndex|callable $schema
     *
     * @return Result
     */
    public function create(string $name, $schema): Result
    {
        return $this->query()->create($name, $schema);
    }

    /**
     * @param string|null $pattern
     *
     * @return Result
     */
    public function showTables(?string $pattern = null): Result
    {
        return $this->query()->showTables($pattern);
    }

    /**
     * @param string|null $pattern
     *
     * @return Result
     */
    public function showVariables(?string $pattern = null): Result
    {
        return $this->query()->showVariables($pattern);
    }

}