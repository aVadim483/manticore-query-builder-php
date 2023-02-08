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
            $this->dsn = 'mysql:host=' . ($this->config['host'] ?? 'localhost') . ';port=' . ($this->config['port'] ?? '9306');
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
     *
     * @return array
     */
    public function query(string $query, ?array $params = []): array
    {
        $result = [];
        if ($stm = $this->dbh->prepare($query)) {
            if ($stm->execute($params)) {
                $result['data'] = $stm->fetchAll(\PDO::FETCH_ASSOC);
                $result['count'] = $stm->rowCount();
            }
            else {
                $this->error($query, $stm->errorInfo());
            }
        }

        return $result;
    }

    /**
     * @param $rows
     * @param $colMeta
     *
     * @return mixed
     */
    protected function castValues($rows, $colMeta)
    {
        foreach ($rows as $numRow => $row) {
            foreach ($colMeta as $meta) {
                switch ($meta['native_type']) {
                    case 'TINY':
                    case 'SHORT':
                    case 'LONG':
                    case 'LONGLONG':
                    case 'INT24':
                    case 'TIMESTAMP':
                        $rows[$numRow][$meta['name']] =  (int)$row[$meta['name']];
                        break;
                    case 'NULL':
                        $rows[$numRow][$meta['name']] =  null;
                        break;
                }
            }
        }

        return $rows;
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
                $result['data'] = [];
                do {
                    $rows = $stm->fetchAll(\PDO::FETCH_ASSOC);
                    if ($rows) {
                        $n = 0;
                        $colMeta = [];
                        foreach ($rows[0] as $col) {
                            $colMeta[] = $stm->getColumnMeta($n++);
                        }
                        $result['data'][] = $this->castValues($rows, $colMeta);
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
     * @return array
     */
    public function insert(string $query, ?array $params = []): array
    {
        $result = [];
        if ($stm = $this->dbh->prepare($query)) {
            if ($stm->execute($params)) {
                $id = $this->dbh->lastInsertId();
                if ($id) {
                    $result['data'] = (int)$id;
                }
            }
            else {
                $this->error($query, $stm->errorInfo());
            }
        }

        return $result;
    }

}
