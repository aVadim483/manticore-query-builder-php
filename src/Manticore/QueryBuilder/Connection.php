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
    private ?LoggerInterface $logger = null;
    private array $logEnabled = [];

    /** @var array schema cache shared by every Query of this connection, see Query::setSchemaPool() */
    private array $schemaPool = [];

    /** @var array last ResultSet, filled by every Query of this connection, see Query::setResultSlot() */
    private array $resultSlot = [];

    /**
     * The class query() makes its objects of. A wrapper for a framework can subclass this
     * connection, point the property at its own subclass of Query and get the queries of the
     * whole connection built by it - the schema pool and the result slot are still shared,
     * because query() keeps handing them over.
     *
     * @var string
     */
    protected string $queryClass = Query::class;

    /** @var int how deep the code is in nested transaction() calls */
    protected int $transactions = 0;


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

        $query = new $this->queryClass($config, null, $this->logger);
        // one DESCRIBE per table for the whole connection, not per built query
        $query->setSchemaPool($this->schemaPool);
        // ... and one place to pick the ResultSet up from, whatever the query returned
        $query->setResultSlot($this->resultSlot);

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

        return $query->create($schema);
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
        return $this->query()->table($tableName)->addColumn($columnName, $type, $options);
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
        return $this->query()->table($tableName)->dropColumn($columnName);
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
        return $this->query()->table($tableName)->modifyColumn($columnName, $type);
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
        return $this->query()->table($tableName)->rename($newName);
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
        return $this->query()->table($tableName)->alterSettings($settings);
    }

    /**
     * Run an SQL statement and tell whether the server accepted it
     *
     * @param string $sql
     *
     * @return bool
     */
    public function statement(string $sql): bool
    {
        return $this->sql($sql)->exec()->success();
    }

    /**
     * Run an SQL statement and answer with its rows
     *
     * @param string $sql
     * @param array|null $bindings named parameters of the statement
     *
     * @return array
     * @throws QueryErrorException when the server rejected the statement
     */
    public function select(string $sql, ?array $bindings = []): array
    {
        $query = $this->sql($sql);
        if ($bindings) {
            $query->bind($bindings);
        }

        return $this->read($query->exec())->result() ?: [];
    }

    /**
     * @return int how many transactions are open
     */
    public function transactionLevel(): int
    {
        return $this->transactions;
    }

    /**
     * BEGIN, unless a transaction is already open - Manticore has no savepoints, so a nested
     * call only counts one level deeper
     *
     * @return bool
     */
    public function beginTransaction(): bool
    {
        if ($this->transactions === 0 && !$this->statement('BEGIN')) {
            return false;
        }
        $this->transactions++;

        return true;
    }

    /**
     * COMMIT, when the outermost transaction is the one being closed
     *
     * @return bool
     * @throws \LogicException when no transaction is open
     */
    public function commit(): bool
    {
        if ($this->transactions === 0) {
            throw new \LogicException('There is no transaction to commit');
        }
        $this->transactions--;

        return $this->transactions === 0 ? $this->statement('COMMIT') : true;
    }

    /**
     * ROLLBACK. Without savepoints there is nothing partial to roll back to, so this always
     * undoes the whole transaction, however deeply nested the call was
     *
     * @return bool
     * @throws \LogicException when no transaction is open
     */
    public function rollBack(): bool
    {
        if ($this->transactions === 0) {
            throw new \LogicException('There is no transaction to roll back');
        }
        $this->transactions = 0;

        return $this->statement('ROLLBACK');
    }

    /**
     * Run the callback inside a transaction, committing when it returns and rolling back when
     * it throws.
     *
     * Manticore serves BEGIN / COMMIT / ROLLBACK on real-time tables.
     *
     * @param callable $callback receives this connection
     * @param int|null $attempts how many times to try before giving up
     *
     * @return mixed whatever the callback returned
     * @throws \Throwable the last exception the callback threw
     */
    public function transaction(callable $callback, ?int $attempts = 1)
    {
        $attempts = max(1, (int)$attempts);

        for ($attempt = 1; ; $attempt++) {
            $this->beginTransaction();

            try {
                $result = $callback($this);
                $this->commit();

                return $result;
            }
            catch (\Throwable $e) {
                $this->rollBack();

                if ($attempt >= $attempts) {
                    throw $e;
                }
            }
        }
    }

    /**
     * A statement asked for its data: a rejected one throws rather than answering with nothing.
     * Same rule as in Query - reads throw, the ResultSet is there for whoever wants it.
     *
     * @param ResultSet $result
     *
     * @return ResultSet
     * @throws QueryErrorException
     */
    protected function read(ResultSet $result): ResultSet
    {
        if (!$result->success()) {
            throw new QueryErrorException((string)$result->error(), $result->sqlQuery());
        }

        return $result;
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
        return $this->read($this->query()->showTables($pattern))->result();
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
        return $this->read($this->query()->table($tableName)->status($tableName))->variables();
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
        return $this->read($this->query()->table($tableName)->settings($tableName))->variables();
    }

    /**
     * @param string $tableName
     *
     * @return array
     */
    public function tableDescribe(string $tableName): array
    {
        $result = [];
        foreach ($this->read($this->query()->table($tableName)->describe())->result() as $col) {
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
        return $this->read($this->query()->showVariables($pattern))->result();
    }

    /**
     * @param string $tableName
     *
     * @return string
     */
    public function showCreate(string $tableName): string
    {
        $result = $this->read($this->query()->table($tableName)->showCreate())->result();

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
     * The ResultSet of the last statement that went through this connection.
     *
     * This is what insert()/update()/delete() drop on the floor when they answer with a scalar:
     * the error text, the warning, the executed SQL, the timing. Note that it is the last
     * statement of the connection, service ones included - a DESCRIBE that columnTypes() asks
     * for before a write lands here too (before the write itself, so a write is never masked).
     *
     * @return ResultSet|null
     */
    public function lastResultSet(): ?ResultSet
    {
        return $this->resultSlot['last'] ?? null;
    }
}
