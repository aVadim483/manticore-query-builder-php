<?php

namespace avadim\Manticore\Tests\Support;

/**
 * Stand-in for PDOClient that never opens a connection.
 *
 * Query accepts any object under the "client" config key, so this makes it possible to test
 * SQL generation (toSql()) offline. DESCRIBE is answered from a caller-supplied column map,
 * because INSERT/REPLACE/UPDATE ask for column types before building their SQL.
 */
class FakeClient
{
    /** @var array<string, string> column name => Manticore type */
    private array $columnTypes;

    /** @var string[] every query this client was asked to run */
    public array $queries = [];

    /** @var int what rowCount() would give, i.e. the affected rows of UPDATE/DELETE */
    public int $affectedRows = 0;

    /** @var mixed what LAST_INSERT_ID() would give: an int, or a list of them for a set of rows */
    public $insertedId = 1;

    /**
     * Rowsets a SELECT is answered with, the way PDOClient::select() hands them over:
     * [0] the rows of the query itself, [$n + 1] the rows of the n-th facet.
     * SHOW META is left empty, so that the answer carries no meta.
     *
     * @var array
     */
    public array $selectData = [];

    /**
     * @param array<string, string>|null $columnTypes
     */
    public function __construct(?array $columnTypes = [])
    {
        $this->columnTypes = $columnTypes ?: [];
    }

    /**
     * @param string $query
     * @param array|null $params
     *
     * @return array
     */
    public function query(string $query, ?array $params = []): array
    {
        $this->queries[] = $query;

        if (stripos($query, 'DESCRIBE') === 0) {
            $data = [];
            foreach ($this->columnTypes as $field => $type) {
                $data[] = ['Field' => $field, 'Type' => $type, 'Properties' => ''];
            }

            return ['data' => $data, 'count' => count($data)];
        }

        return ['data' => [], 'count' => $this->affectedRows];
    }

    /**
     * @param string $query
     * @param array|null $params
     *
     * @return array
     */
    public function select(string $query, ?array $params = []): array
    {
        $this->queries[] = $query;

        if ($this->selectData && stripos(ltrim($query), 'SELECT') === 0) {
            return ['data' => $this->selectData];
        }

        return ['data' => []];
    }

    /**
     * @param string $query
     * @param array|null $params
     *
     * @return array
     */
    public function insert(string $query, ?array $params = []): array
    {
        $this->queries[] = $query;

        return ['data' => $this->insertedId];
    }

    /**
     * The last query passed to this client
     *
     * @return string|null
     */
    public function lastQuery(): ?string
    {
        return $this->queries ? $this->queries[count($this->queries) - 1] : null;
    }
}
