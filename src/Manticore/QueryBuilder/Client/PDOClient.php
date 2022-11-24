<?php

declare(strict_types=1);

namespace avadim\Manticore\QueryBuilder\Client;

use Psr\Log\LoggerInterface;

class PDOClient
{
    private array $config;
    private string $dsn;
    private \PDO $dbh;

    /**
     * @param array|null $config
     * @param LoggerInterface|null $logger
     */
    public function __construct(?array $config = [], LoggerInterface $logger = null)
    {
        $this->config = $config;
        if (isset($this->config['dsn'])) {
            $this->dsn = $this->config['dsn'];
        }
        else {
            $this->dsn = 'mysql:host=' . ($this->config['host'] ?: 'localhost') . ';port=' . ($this->config['port'] ?: '9306');
        }
        $this->dbh = new \PDO($this->dsn, $this->config['username'] ?? null, $this->config['password'] ?? null);
    }

    /**
     * @param string $query
     * @param array $errorInfo
     *
     * @return mixed
     */
    public function error(string $query, array $errorInfo)
    {
        $text = 'SQL: ' . $query . "\n" . 'Error [' . $errorInfo[0] . '] ' . $errorInfo[2];
        throw new \RuntimeException($text);
    }

    /**
     * @param string $query
     * @param array|null $params
     * @param array|null $columnTypes
     *
     * @return array|false
     */
    public function query(string $query, ?array $params = [], ?array $columnTypes = [])
    {
        $result = [];
        if ($stm = $this->dbh->prepare($query)) {
            if ($stm->execute($params)) {
                $result = $stm->fetchAll(\PDO::FETCH_ASSOC);
            }
            else {
                $this->error($query, $stm->errorInfo());
            }
        }

        return $result;
    }

    /**
     * @param string $query
     * @param array|null $params
     *
     * @return array
     */
    public function select(string $query, ?array $params = [])
    {
        $result = [];
        if ($stm = $this->dbh->prepare($query)) {
            if ($stm->execute($params)) {
                do {
                    $rows = $stm->fetchAll(\PDO::FETCH_ASSOC);
                    if ($rows) {
                        $result[] = $rows;
                    }
                } while ($stm->nextRowset());
            }
            else {
                $this->error($query, $stm->errorInfo());
            }
        }

        return $result;
    }

    /**
     * @param string $query
     * @param array|null $params
     *
     * @return string|false
     */
    public function insert(string $query, ?array $params = [])
    {
        $result = null;
        if ($stm = $this->dbh->prepare($query)) {
            if ($stm->execute($params)) {
                $result = $this->dbh->lastInsertId();
            }
            else {
                $this->error($query, $stm->errorInfo());
            }
        }

        return $result;
    }

}