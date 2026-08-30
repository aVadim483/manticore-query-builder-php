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
    public function __construct(?array $config = [], ?LoggerInterface $logger = null)
    {
        $this->config = $config;
        if (isset($this->config['dsn'])) {
            $this->dsn = $this->config['dsn'];
        }
        else {
            $this->dsn = 'mysql:host=' . ($this->config['host'] ?? 'localhost') . ';port=' . ($this->config['port'] ?? '9306');
        }
        $options = [];
        if (!empty($config['timeout'])) {
            // the timeout has to be handed to the constructor: set afterwards it would only
            // apply once the connection is there, i.e. never to the connecting itself
            $options[\PDO::ATTR_TIMEOUT] = (int)$config['timeout'];
        }
        $this->dbh = new \PDO($this->dsn, $this->config['username'] ?? null, $this->config['password'] ?? null, $options);
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
     * @param array $rows
     * @param $colMeta
     *
     * @return array
     */
    protected function castValues(array $rows, $colMeta): array
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
                    case 'FLOAT':
                    case 'DOUBLE':
                    case 'NEWDECIMAL':
                        // PDO hands numbers over as strings on PHP 7.4 and as numbers since
                        // 8.1, and a computed column - SUM(price) as _aggregate - has no type
                        // in DESCRIBE to be cast by afterwards. Reading the type the server
                        // reports makes the answer the same on every version of PHP.
                        $rows[$numRow][$meta['name']] =  (float)$row[$meta['name']];
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
     * A statement ready to be executed.
     *
     * PDO runs in its silent error mode here, so a statement it refuses to prepare comes back
     * as false. That has to be raised: answering with "no statement" made the caller return an
     * empty array, which the builder reads as an answer without rows - i.e. a rejected query
     * was reported as a successful one.
     *
     * @param string $query
     * @param array|null $params
     *
     * @return \PDOStatement
     */
    protected function prepare(string $query, ?array $params = []): \PDOStatement
    {
        $stm = $this->dbh->prepare($query);
        if (!$stm) {
            $this->error($query, $this->dbh->errorInfo());
        }
        if ($params) {
            foreach ($params as $key => $val) {
                if (is_int($val)) {
                    $stm->bindValue($key, $val, \PDO::PARAM_INT);
                }
                else {
                    $stm->bindValue($key, $val, \PDO::PARAM_STR);
                }
            }
        }

        return $stm;
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
        $stm = $this->prepare($query, $params);
        if ($stm->execute()) {
            $result['data'] = $stm->fetchAll(\PDO::FETCH_ASSOC);
            $result['count'] = $stm->rowCount();
        }
        else {
            $this->error($query, $stm->errorInfo());
        }

        return $result;
    }

    /**
     * @param string $query
     * @param array|null $params
     *
     * @return array
     */
    public function select(string $query, ?array $params = []): array
    {
        $result = [];
        $stm = $this->prepare($query, $params);
        if ($stm->execute()) {
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
        $result = ['data' => null];
        $stm = $this->prepare($query, $params);
        if ($stm->execute()) {
            $idStatement = $this->dbh->query('SELECT LAST_INSERT_ID()');
            if (!$idStatement) {
                $this->error('SELECT LAST_INSERT_ID()', $this->dbh->errorInfo());
            }
            if (($rows = $idStatement->fetch()) && !empty($rows[0])) {
                $id = array_map('intval', explode(',', $rows[0]));
                $result['data'] = (count($id) === 1) ? reset($id) : $id;
            }
        }
        else {
            $this->error($query, $stm->errorInfo());
        }

        return $result;
    }

}
