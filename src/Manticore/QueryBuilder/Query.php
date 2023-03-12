<?php

declare(strict_types=1);

namespace avadim\Manticore\QueryBuilder;

use avadim\Manticore\QueryBuilder\Client\PDOClient;
use avadim\Manticore\QueryBuilder\Schema\SchemaTable;

class Query
{
    private array $config;
    private ?array $table;
    private string $prefix;
    private bool $forcePrefix = false;

    private $logger = null;
    private $client;
    private Parser $parser;
    private array $indexPool = [];

    private SchemaTable $schema;

    private ?string $sql = null;
    private ?string $command = null;

    private array $select = [];
    private array $update = [];

    private ?string $match = null;
    private array $orders = [];
    private array $limit = [];
    private array $options = [];
    private array $facets = [];
    private array $highlight = [];
    private array $params = [];

    private QueryConditionSet $conditions;

    /**
     * @param array $config
     * @param string|null $tableName
     * @param $logger
     */
    public function __construct(array $config, ?string $tableName = null, $logger = null)
    {
        $this->config = $config;
        $this->prefix = $config['prefix'] ?? '';
        if (!empty($config['force_prefix'])) {
            $this->forcePrefix = true;
        }
        $this->parser = new Parser($this->prefix);
        $this->schema = new SchemaTable();
        if ($logger) {
            $this->setLogger($logger);
        }

        if (is_object($config['client'])) {
            $this->client = $config['client'];
        }
        else {
            $this->client = new PDOClient($this->config['client'] ?? []);
        }

        $this->conditions = new QueryConditionSet();
        if ($tableName) {
            $this->table($tableName);
        }
    }

    public function setLogger($logger)
    {
        $this->logger = $logger;
    }

    public function logger()
    {
        return $this->logger;
    }

    /**
     * @return array
     */
    public function parse(): array
    {
        if ($this->sql) {
            $query = $this->parser->parse($this->sql);
            if (!empty($query['command'])) {
                $this->command = $query['command'];
            }

            return $query;
        }

        if (!$this->command) {
            $this->selectColumns('*');
        }

        $query = [
            'command' => $this->command,
            'table' => $this->_sqlTable(),
            'query' => $this->_makeSql(),
            'original' => null,
        ];
        if (!empty($this->facets)) {
            $query['facets'] = $this->facets;
        }

        return $query;
    }

    /**
     * @return string
     */
    public function toSql(): string
    {
        $querySet = $this->parse();

        return $querySet['query'];
    }

    /**
     * @param array $rows
     *
     * @return array
     */
    protected function _castResult(array $rows): array
    {
        $types = $this->columnTypes();
        $result = [];
        foreach ($rows as $num => $row) {
            $resNum = $row['_id'] ?: $num;
            foreach ($row as $col => $val) {
                if (isset($types[$col])) {
                    switch ($types[$col]) {
                        case 'bool':
                            $val = (int)$val;
                            $row[$col] = (bool)$val;
                            break;
                        case 'bigint':
                        case 'integer':
                        case 'timestamp':
                            $row[$col] = (int)$val;
                            break;
                        case 'float':
                            $row[$col] = (float)$val;
                            break;
                        case 'multi':
                        case 'multi64':
                        case 'mva':
                            $arr = [];
                            foreach (explode(',', $val) as $item) {
                                $arr[] = (int)$item;
                            }
                            $row[$col] = $arr;
                        break;
                        case 'json':
                            $row[$col] = $val ? json_decode($val, true) : [];
                            break;
                        default:
                            $row[$col] = $val;
                    }

                }
                else {
                    if (preg_match('/^(\w+)\(/', $col, $m)) {
                        $row[$col] = $this->_castFuncResult($m[1], $val);
                    }
                }
            }
            $result[$resNum] = $row;
        }

        return $result;
    }

    /**
     * @param $func
     * @param $val
     *
     * @return mixed
     */
    protected function _castFuncResult($func, $val)
    {
        switch (strtoupper($func)) {
            case 'BIGINT':
            case 'INTEGER':
            case 'UINT':
            case 'SINT':
            case 'COUNT':
                return (int)$val;
            case 'DOUBLE':
                return (float)$val;
        }

        return $val;
    }

    /**
     * @param array $parsedSql
     *
     * @return array
     */
    protected function _execQuery(array $parsedSql): array
    {
        $index = $this->_sqlTable();
        if (!$index && !empty($parsedSql['table'])) {
            $this->table($parsedSql['table']);
        }

        $time = microtime(true);
        if ($parsedSql['command'] === 'INSERT') {
            $response = $this->client->insert($parsedSql['query'], $this->params);
        }
        elseif ($parsedSql['command'] === 'SELECT') {
            $query = 'SELECT id as _id, weight() as _score, ' . substr($parsedSql['query'], 6);
            //$query = $parsedSql['query'];
            $response = $this->client->select($query, $this->params);
        }
        else {
            $response = $this->client->query($parsedSql['query'], $this->params);
        }
        $time = microtime(true) - $time;

        $result = [
            'command' => $parsedSql['command'],
            'query' => $parsedSql['query'],
            'exec_time' => $time,
        ];

        if ($parsedSql['command'] === 'SHOW TABLES') {
            $data = [];
            foreach ($response['data'] as $n => $index) {
                foreach ($index as $key => $val) {
                    $data[$n][$key] = $val;
                    if ($key === 'Index') {
                        $data[$n]['Table'] = $val;
                        if ($this->prefix && strpos($val, $this->prefix) === 0) {
                            $name = '?' . substr($val, strlen($this->prefix));
                        } else {
                            $name = $val;
                        }
                        $data[$n]['Name'] = $name;
                    }
                }
            }
            $result['result'] = [
                'type' => 'collection',
                'data' => $data,
            ];
        }
        elseif ($parsedSql['command'] === 'INSERT') {
            $result['result'] = [
                'type' => 'id',
                'data' => $response['data'],
                'status' => 'inserted',
            ];
        }
        elseif ($parsedSql['command'] === 'SELECT') {
            $result['result'] = [
                'type' => 'collection',
                'data' => !empty($response['data'][0]) ? $this->_castResult($response['data'][0]) : [],
            ];
            unset($response['data'][0]);
            if (!empty($parsedSql['facets']) && $response['data']) {
                $result['facets'] = [];
                foreach ($parsedSql['facets'] as $n => $desc) {
                    if (isset($response['data'][$n + 1])) {
                        $data = $this->_castResult($response['data'][$n + 1]);
                        foreach ($data as $dataKey => $dataSet) {
                            if (isset($dataSet['count(*)'])) {
                                $data[$dataKey]['_count'] = $dataSet['count(*)'];
                            }
                        }
                    }
                    else {
                        $data = [];
                    }
                    $result['facets'][] = [
                        'desc' => $desc,
                        'data' => $data,
                    ];
                }
            }
            $meta = $this->client->select('SHOW META');
            $result['meta'] = [];
            foreach ($meta['data'][0] as $item) {
                $result['meta'][$item['Variable_name']] = $item['Value'];
            }
        }
        elseif ($parsedSql['command'] === 'UPDATE') {
            $result['result'] = [
                'type' => 'id',
                'data' => $response['count'],
                'status' => 'updated',
            ];
        }
        elseif ($parsedSql['command'] === 'SHOW CREATE TABLE') {
            $result['result'] = [
                'type' => 'array',
                'data' => $response['data'][0] ?? [],
            ];
        }
        elseif ($response['data'] && is_array($response['data'])) {
            $row = reset($response['data']);
            if (array_key_first($response['data']) === 0 && is_array($row)) {
                $result['result'] = [
                    'type' => 'collection',
                    'data' => $response['data'],
                ];
            }
            else {
                $result['result'] = [
                    'type' => 'array',
                    'data' => $response['data'],
                ];
            }
        }
        else {
            $result['result'] = [
                'type' => 'bool',
                'data' => true,
            ];
        }

        return $result;
    }

    /**
     * @param array $params
     *
     * @return array
     */
    protected function _explainQuery(array $params): array
    {
        $time = microtime(true);
        $response = $this->client->explainQuery($params);
        //$response = [];
        $time = microtime(true) - $time;
        $items = [$response];

        return ['exec_time' => $time, 'response' => $response, 'items' => $items];
    }

    /**
     * @param string $sql
     *
     * @return $this
     */
    public function sql(string $sql): Query
    {
        $this->sql = $sql;

        return $this;
    }

    /**
     * @param string $name
     *
     * @return string
     */
    protected function resolveTableName(string $name): string
    {

        return Parser::resolveTableName($name, $this->prefix, $this->forcePrefix);
    }

    /**
     * Set table name
     *
     * @param string $name
     *
     * @return $this
     */
    public function table(string $name): Query
    {
        $this->table = ['real_name' => Parser::resolveTableName($name, $this->prefix, $this->forcePrefix)];

        return $this;
    }

    /**
     * Alias of table()
     *
     * @param string $name
     *
     * @return $this
     */
    public function index(string $name): Query
    {
        return $this->table($name);
    }

    /**
     * @param string $match
     *
     * @return $this
     */
    public function match(string $match): Query
    {
        $this->selectColumns(null);
        $this->match = $match;

        return $this;
    }

    // +++ OPTIONS +++ //

    /**
     * @param string $key
     * @param string|int|null $value
     *
     * @return $this
     */
    public function option(string $key, $value = null): Query
    {
        if (isset($this->options[$key]) && $value === null) {
            unset($this->options[$key]);
        } else {
            $this->options[$key] = $value;
        }

        return $this;
    }

    public function highlight(array $options = [], array $fields = [], string $query = null): Query
    {
        $this->highlight['alias'] = '_highlight';
        if ($options) {
            $this->highlight['options'] = $options;
        }
        if ($fields) {
            $this->highlight['fields'] = $fields;
        }
        if ($query) {
            $this->highlight['$query'] = $query;
        }

        return $this;
    }

    /**
     * @param string $field
     * @param int|null $weight
     *
     * @return $this
     */
    public function fieldWeight(string $field, ?int $weight = null): Query
    {
        if (isset($this->options['field_weights'][$field]) && $weight === null) {
            unset($this->options['field_weights'][$field]);
        }
        else {
            $this->options['field_weights'][$field] = $weight;
        }

        return $this;
    }

    /**
     * @param string|array $value
     *
     * @return $this
     */
    public function fieldWeights($value): Query
    {
        if (is_string($value)) {
            if ($value[0] === '(' && $value[-1] === ')') {
                $value = substr($value, 1, -1);
            }
            $value = explode(',', $value);
            foreach ($value as $str) {
                [$field, $weight] = array_map('trim', explode('=', $str));
                if ($weight === '') {
                    $weight = null;
                }
                $this->fieldWeight($field, $weight);
            }
        }
        else {
            foreach ($value as $field => $weight) {
                $this->fieldWeight($field, $weight);
            }
        }

        return $this;
    }

    /**
     * Max time in milliseconds to wait for remote queries to complete
     * @see https://manual.manticoresearch.com/Creating_an_index/Creating_a_distributed_index/Remote_indexes#agent_query_timeout
     *
     * @param int $value
     *
     * @return $this
     */
    public function agentQueryTimeout(int $value): Query
    {
        return $this->option('agent_query_timeout', $value);
    }

    /**
     * Per-query max matches value.
     * Maximum amount of matches that the server keeps in RAM for each index and can return to the client.
     * Default is 1000
     *
     * @param int $value
     *
     * @return $this
     */
    public function maxMatches(int $value): Query
    {
        return $this->option('max_matches', $value);
    }

    /**
     * Sets maximum search query time, in milliseconds.
     * Must be a non-negative integer. Default value is 0 which means "do not limit"
     *
     * @param int $value
     *
     * @return $this
     */
    public function maxQueryTime(int $value): Query
    {
        return $this->option('max_query_time', $value);
    }

    /**
     * Allowed values: proximity_bm25, bm25, none, wordcount, proximity, matchany, fieldmask, sph04, expr, export
     *
     * @see https://manual.manticoresearch.com/Searching/Sorting_and_ranking#Available-built-in-rankers
     *
     * @param string $value
     *
     * @return $this
     */
    public function ranker(string $value): Query
    {
        return $this->option('ranker', $value);
    }

    /**
     * Expands keywords with exact forms and/or stars when possible
     *
     * @param bool $value
     *
     * @return $this
     */
    public function expandKeywords(bool $value): Query
    {
        return $this->option('expand_keywords', $value);
    }
    // +++ WHERE +++ //


    /**
     * @param $field
     * @param $arg1
     * @param $arg2
     *
     * @return $this
     */
    public function where($field, $arg1 = null, $arg2 = null): Query
    {
        $this->conditions->andWhere($field, $arg1, $arg2);

        return $this;
    }

    /**
     * @param $field
     * @param $arg1
     * @param $arg2
     *
     * @return $this
     */
    public function andWhere($field, $arg1 = null, $arg2 = null): Query
    {
        $this->conditions->andWhere($field, $arg1, $arg2);

        return $this;
    }

    /**
     * @param $field
     * @param $arg1
     * @param $arg2
     *
     * @return $this
     */
    public function orWhere($field, $arg1 = null, $arg2 = null): Query
    {
        $this->conditions->orWhere($field, $arg1, $arg2);

        return $this;
    }

    /**
     * @param $field
     *
     * @return $this
     */
    public function whereNull($field): Query
    {
        return $this->andWhere($field, 'IS NULL');
    }

    /**
     * @param $field
     *
     * @return $this
     */
    public function andWhereNull($field): Query
    {
        return $this->andWhere($field, 'IS NULL');
    }

    /**
     * @param $field
     *
     * @return $this
     */
    public function orWhereNull($field): Query
    {
        return $this->orWhere($field, 'IS NULL');
    }

    /**
     * @param $field
     *
     * @return $this
     */
    public function whereNotNull($field): Query
    {
        return $this->andWhere($field, 'IS NOT NULL');
    }

    /**
     * @param $field
     *
     * @return $this
     */
    public function andWhereNotNull($field): Query
    {
        return $this->andWhere($field, 'IS NOT NULL');
    }

    /**
     * @param $field
     *
     * @return $this
     */
    public function orWhereNotNull($field): Query
    {
        return $this->orWhere($field, 'IS NOT NULL');
    }

    /**
     * @param $field
     * @param array $arg
     *
     * @return $this
     */
    public function whereIn($field, array $arg): Query
    {
        return $this->andWhere($field, 'IN', $arg);
    }

    /**
     * @param $field
     * @param array $arg
     *
     * @return $this
     */
    public function andWhereIn($field, array $arg): Query
    {
        return $this->andWhere($field, 'IN', $arg);
    }

    /**
     * @param $field
     * @param array $arg
     *
     * @return $this
     */
    public function orWhereIn($field, array $arg): Query
    {
        return $this->orWhere($field, 'IN', $arg);
    }

    /**
     * @param $field
     * @param array $arg
     *
     * @return $this
     */
    public function whereNotIn($field, array $arg): Query
    {
        return $this->andWhere($field, 'NOT IN', $arg);
    }

    /**
     * @param $field
     * @param array $arg
     *
     * @return $this
     */
    public function andWhereNotIn($field, array $arg): Query
    {
        return $this->andWhere($field, 'NOT IN', $arg);
    }

    /**
     * @param $field
     * @param array $arg
     *
     * @return $this
     */
    public function orWhereNotIn($field, array $arg): Query
    {
        return $this->orWhere($field, 'NOT IN', $arg);
    }

    /**
     * @param $field
     * @param array $arg
     *
     * @return $this
     */
    public function whereBetween($field, array $arg): Query
    {
        return $this->where($field, 'BETWEEN', $arg);
    }

    /**
     * @param $field
     * @param array $arg
     *
     * @return $this
     */
    public function orWhereBetween($field, array $arg): Query
    {
        return $this->orWhere($field, 'BETWEEN', $arg);
    }

    /**
     * @param $field
     * @param array $arg
     *
     * @return $this
     */
    public function whereNotBetween($field, array $arg): Query
    {
        return $this->where($field, 'NOT BETWEEN', $arg);
    }

    /**
     * @param $field
     * @param array $arg
     *
     * @return $this
     */
    public function orWhereNotBetween($field, array $arg): Query
    {
        return $this->orWhere($field, 'NOT BETWEEN', $arg);
    }

    /**
     * @return string
     */
    protected function _sqlSelectColumns(): string
    {
        if ($this->select) {
            $result = implode(', ', array_map('addslashes', $this->select));
        }
        else {
            $result = '*';
        }

        if ($this->highlight) {
            $highlight = 'HIGHLIGHT(';
            if (!empty($this->highlight['options'])) {
                $options = [];
                foreach ($this->highlight['options'] as $key => $val) {
                    if (is_numeric($val)) {
                        $options[] = $key . '=' . addslashes($val);
                    }
                    else {
                        $options[] = $key . '=\'' . addslashes($val) . '\'';
                    }
                }
                $highlight .= '{' . implode(',', $options) . '}';
            }
            $highlight .= ') AS ' . $this->highlight['alias'];
            $result .= ', ' . $highlight;
        }
        return $result;
    }

    /**
     * @return string
     */
    protected function _sqlUpdateColumns(): string
    {
        if ($this->update) {
            $set = [];
            $types = $this->columnTypes();
            foreach ($this->update as $column => $value) {
                if (isset($types[$column])) {
                    $set[] = $column . '=' . Parser::formatValue($value, $types[$column]);
                }
                else {
                    $set[] = $column . '=' . Parser::formatValue($value);
                }
            }

            return implode(', ', $set);
        }

        return '';
    }

    /**
     * @return string
     */
    protected function _sqlSchemaColumns(): string
    {
        return (string)$this->schema;
    }

    /**
     * @return string|null
     */
    protected function _sqlTable(): ?string
    {
        return $this->table['real_name'] ?? '';
    }

    /**
     * @param ?bool $raw
     *
     * @return string|null
     */
    protected function _sqlMatch(?bool $raw = false): ?string
    {
        if (!empty($this->match)) {
            return !$raw ? '\'' . addslashes($this->match) . '\'' : $this->match;
        }

        return null;
    }

    /**
     * @return string
     */
    protected function _sqlWhere(): string
    {
        return $this->conditions->asString();
    }

    /**
     * @return string
     */
    protected function _sqlLimit(): string
    {
        if ($this->limit) {
            $offset = isset($this->limit[1]) ? $this->limit[1] . ',' : '';

            return $offset . $this->limit[0];
        }

        return '';
    }

    /**
     * @return string
     */
    protected function _sqlOrders(): string
    {
        if ($this->orders) {
            return implode(',', $this->orders);
        }

        return '';
    }

    /**
     * @return string
     */
    protected function _sqlOptions(): string
    {
        $options = '';
        if (!empty($this->options)) {
            foreach ($this->options as $name => $value) {
                if ($options) {
                    $options .= ',';
                }
                if ($name === 'field_weights' && !empty($value)) {
                    if (is_array($value)) {
                        $str = '';
                        foreach ($value as $field => $weight) {
                            if ($str) {
                                $str .= ',';
                            }
                            $str .= $field . '=' . $weight;
                        }
                        $value = '(' . $str . ')';
                    }
                }
                elseif ($value === true) {
                    $value = 1;
                }
                elseif ($value === false) {
                    $value = 0;
                }
                $options .= $name . '=' . $value;
            }
        }
        return $options;
    }

    /**
     * @return string
     */
    protected function _sqlFacets(): string
    {
        if ($this->facets) {
            $facets = [];
            foreach ($this->facets as $facet) {
                $facets[] = (string)$facet;
            }

            return ' ' . implode(' ', $facets);
        }

        return '';
    }

    /**
     * @return string
     */
    protected function _makeSql(): string
    {
        if ($this->command === 'SELECT' || $this->command === 'UPDATE' || $this->command === 'DELETE') {
            if ($this->command === 'SELECT') {
                $sql = 'SELECT ' . $this->_sqlSelectColumns() . ' FROM ' . $this->_sqlTable();
            }
            elseif ($this->command === 'UPDATE') {
                $sql = 'UPDATE ' . $this->_sqlTable() . ' SET ' . $this->_sqlUpdateColumns();
            }
            else {
                $sql = 'DELETE FROM ' . $this->_sqlTable();
            }

            $match = $this->_sqlMatch();
            $where = $this->_sqlWhere();
            if ($match !== null) {
                $sql .= ' WHERE MATCH(' . $match . ')';
            }
            if ($where) {
                if ($match !== null) {
                    $sql .= ' AND (' . trim($where) . ')';
                }
                else {
                    $sql .= ' WHERE' . $where;
                }
            }
            if ($orders = $this->_sqlOrders()) {
                $sql .= ' ORDER BY ' . $orders;
            }
            if ($limit = $this->_sqlLimit()) {
                $sql .= ' LIMIT ' . $limit;
            }
            if ($options = $this->_sqlOptions()) {
                $sql .= ' OPTION ' . $options;
            }
            if ($this->command === 'SELECT') {
                $sql .= $this->_sqlFacets();
            }
        }

        elseif ($this->command === 'INSERT' || $this->command === 'REPLACE') {
            $columns = $values = [];
            $types = $this->columnTypes();
            // single or multiple insert/replace
            if (is_numeric($firstKey = array_key_first($this->update)) && is_array($this->update[$firstKey])) {
                // $this->update has [][] -- multiple operation
                foreach ($this->update as $row) {
                    foreach($row as $col => $val) {
                        if (!in_array($col, $columns)) {
                            $columns[] = $col;
                        }
                    }
                }
                foreach ($this->update as $numRow => $row) {
                    foreach($columns as $col) {
                        $values[$numRow][] = Parser::formatValue($row[$col] ?? null, $types[$col] ?? null);
                    }
                }
                $sql = $this->command . ' INTO ' . $this->_sqlTable() . '(' . implode(',', $columns) . ') VALUES ';
                $sqlValues = [];
                foreach ($values as $rowValues) {
                    $sqlValues[] = '(' . implode(',', $rowValues) . ')';
                }
                $sql .= implode(',', $sqlValues);
            }
            else {
                // $this->update has [] -- single record
                foreach ($this->update as $col => $val) {
                    $columns[] = $col;
                    $values[] = Parser::formatValue($val, $types[$col] ?? null);
                }
                $sql = $this->command . ' INTO ' . $this->_sqlTable() . '(' . implode(',', $columns) . ') VALUES('. implode(',', $values) . ')';
            }
        }

        elseif ($this->command === 'CREATE') {
            $sql = 'CREATE TABLE ' . $this->_sqlTable() . '(' . $this->_sqlSchemaColumns() . ')';
            if (!empty($this->schema->engine)) {
                $sql .= ' engine=\'' . $this->schema->engine . '\'';
            }
            elseif (!empty($this->table['engine'])) {
                $sql .= ' engine=\'' . $this->table['engine'] . '\'';
            }
        }

        else {
            $sql = '';
        }

        return $sql;
    }

    /**
     * @param string|array|null $columns
     *
     * @return $this
     */
    public function selectColumns($columns = '*'): Query
    {
        $this->command = 'SELECT';
        if (is_string($columns)) {
            $this->select = Parser::explode(',', $columns, true);
        }
        elseif (is_array($columns)) {
            $this->select = $columns;
        }

        return $this;
    }

    /**
     * @param string|array|null $columns
     *
     * @return $this
     */
    public function select($columns = '*'): Query
    {

        return $this->selectColumns($columns);
    }

    /**
     * Make schema for a new index
     *
     * @param array|callable $schema
     *
     * @return $this
     */
    public function schema($schema): Query
    {
        $this->schema = new SchemaTable();
        if ($schema instanceof SchemaTable) {
            $this->schema = $schema;
        }
        elseif (is_callable($schema)) {
            $schema($this->schema);
        }
        else {
            //var_dump($schema);exit;
            foreach($schema as $name => $column) {
                if (is_int($name) && is_string($column)) {
                    if (strpos($column, ' ')) {
                        [$name, $type] = explode(' ', $column);
                    }
                    else {
                        $name = $column;
                        $type = '';
                    }
                }
                else {
                    $type = $column;
                }
                $this->schema->addColumn($name, $type);
            }
        }

        return $this;
    }

    /**
     * @param string $engine
     *
     * @return $this
     */
    public function engine(string $engine): Query
    {
        $this->table['engine'] = $engine;

        return $this;
    }

    /**
     * Set columnar storage
     *
     * @return $this
     */
    public function columnar(): Query
    {
        return $this->engine('columnar');
    }

    /**
     * Set row-wise storage
     *
     * @return $this
     */
    public function rowwise(): Query
    {
        return $this->engine('rowwise');
    }

    /**
     * @param string $names
     *
     * @return $this
     */
    public function orderBy(string $names): Query
    {
        $this->orders[] = $names . ' ASC';

        return $this;
    }

    /**
     * @param string $names
     *
     * @return $this
     */
    public function orderByDesc(string $names): Query
    {
        $this->orders[] = $names . ' DESC';

        return $this;
    }

    /**
     * limit(<limit>)
     * limit(<offset>, <limit>)
     *
     * @param int|array|null $param1
     * @param int|null $param2
     *
     * @return $this
     */
    public function limit($param1, ?int $param2 = null): Query
    {
        if ($param2 === null) {
            // limit
            $this->limit = [$param1, null];
        }
        else {
            // limit, offset
            $this->limit = [$param2, $param1];
        }

        return $this;
    }

    public function offset(int $param): Query
    {
        $this->limit[1] = $param;

        return $this;
    }

    /**
     * @param string $column
     * @param callable|null $callback
     *
     * @return $this
     */
    public function facet(string $column, ?callable $callback = null): Query
    {
        $facet = new Facet($column);
        if ($callback) {
            $callback($facet);
        }
        $this->facets[] = $facet;

        return $this;
    }

    /**
     * @param array $params
     *
     * @return $this
     */
    public function bind(array $params): Query
    {
        $this->params = $params;

        return $this;
    }

    /**
     * @return ResultSet
     */
    public function exec(): ResultSet
    {
        $request = $this->parse();
        $result = $this->_execQuery($request);

        return new ResultSet($result);
    }

    /**
     * Allows to get the query transformation tree of a query without running it. Useful for testing queries.
     *
     * @return ResultSet
     */
    public function explain(): ResultSet
    {
        $response = [
            'command' => 'EXPLAIN',
            'query' => $this->_sqlMatch(true),
            'original' => null,
        ];

        $params = [
            'table' => $this->_sqlTable(),
            'body' => [
                'query' => $this->_sqlMatch(),
            ],
        ];

        $response['result'] = $this->_explainQuery($params);

        return new ResultSet($response);
    }

    /**
     * @return ResultSet
     */
    public function delete(): ResultSet
    {
        $this->command = 'DELETE';

        $request = $this->parse();
        $result = $this->_execQuery($request);

        return new ResultSet($result, 'deleted');
    }

    /**
     * create('tableName', [..])
     * create('tableName', function(SchemaTable $table) {..})
     * table('tableName')->create([..])
     * table('tableName')->create(function(SchemaTable $table) {..})
     *
     * @param string|array|SchemaTable|callable $name
     * @param array|SchemaTable|callable|null $schema
     *
     * @return ResultSet
     */
    public function create($name, $schema = null): ResultSet
    {
        if (func_num_args() === 2 && is_string($name) && $schema) {
            $this->table($name);
        }
        elseif (func_num_args() === 1) {
            $schema = $name;
        }
        $this->schema($schema);
        $this->command = 'CREATE';

        $request = $this->parse();
        $result = $this->_execQuery($request);

        return new ResultSet($result, 'created');
    }

    /**
     * @param array|null $schema
     *
     * @return ResultSet
     */
    public function alter(?array $schema = null): ResultSet
    {
        if ($schema) {
            $this->schema($schema);
        }
        $this->command = 'ALTER';

        // 1. get index info
        // 2. define difference
        // 3. alter table drop column
        // 4. alter add column

        $request = $this->parse();
        $result = $this->_execQuery($request);

        return new ResultSet($this->_execQuery($result));
    }

    /**
     * Truncate index
     *
     * @param bool|null $reconfigure
     *
     * @return ResultSet
     */
    public function truncate(?bool $reconfigure = false): ResultSet
    {
        $this->command = 'TRUNCATE';
        $sql = 'TRUNCATE TABLE ' . $this->_sqlTable() . (!empty($reconfigure) ? ' WITH RECONFIGURE' : '');
        $request = [
            'command' => $this->command,
            'query' => $sql,
            'original' => null,
        ];
        $result = $this->_execQuery($request);

        return new ResultSet($result, 'truncated');
    }

    /**
     * Drop index
     *
     * @param bool|null $ifExists
     *
     * @return ResultSet
     */
    public function drop(?bool $ifExists = false): ResultSet
    {
        $this->command = 'DROP';
        $sql = 'DROP TABLE ' . (!empty($ifExists) ? 'IF EXISTS ' : '') . $this->_sqlTable();
        $request = [
            'command' => $this->command,
            'query' => $sql,
            'original' => null,
        ];
        $result = $this->_execQuery($request);

        return new ResultSet($result, 'dropped');
    }

    /**
     * @return ResultSet
     */
    public function dropIfExists(): ResultSet
    {
        return $this->drop(true);
    }

    /**
     * @param string|null $pattern
     *
     * @return ResultSet
     */
    public function showTables(?string $pattern = null): ResultSet
    {
        $this->command = 'SHOW TABLES';
        $sql = 'SHOW TABLES';
        if ($pattern) {
            $sql .= ' LIKE \'' . Parser::resolveTableName($pattern, $this->prefix, $this->forcePrefix) . '\'';
        }
        elseif ($this->forcePrefix && $pattern !== '' && $pattern !== '%') {
            $sql .= ' LIKE \'' . $this->prefix . '%\'';
        }
        $request = [
            'command' => $this->command,
            'query' => $sql,
            'original' => null,
        ];
        $result = $this->_execQuery($request);

        return new ResultSet($result);
    }

    /**
     * @param string|null $pattern
     *
     * @return ResultSet
     */
    public function showVariables(?string $pattern = null): ResultSet
    {
        $this->command = 'SHOW VARIABLES';
        $sql = 'SHOW VARIABLES';
        if ($pattern) {
            $sql .= ' LIKE \'' . $pattern . '\'';
        }
        $request = [
            'command' => $this->command,
            'query' => $sql,
            'original' => null,
        ];
        $result = $this->_execQuery($request);

        return new ResultSet($result);
    }

    /**
     * DESCRIBE statement lists table columns and their associated types. Columns are document ID, full-text fields,
     * and attributes
     *
     * @return ResultSet
     */
    public function describe(): ResultSet
    {
        $sql = 'DESCRIBE ' . $this->_sqlTable();
        $response = $this->client->query($sql);
        $result = [
            'command' => 'DESCRIBE',
            'query' => $sql,
            'original' => null,
            'result' => [
                'type' => 'array',
                'data' => $response['data'],
            ]
        ];

        return new ResultSet($result);
    }

    public function showCreate(): ResultSet
    {
        $sql = 'SHOW CREATE TABLE ' . $this->_sqlTable();
        $response = $this->client->query($sql);
        $result = [
            'command' => 'SHOW CREATE TABLE',
            'query' => $sql,
            'original' => null,
            'result' => [
                'type' => 'array',
                'data' => $response['data'][0] ?? [],
            ]
        ];

        return new ResultSet($result);
    }

    /**
     * @return array
     */
    public function columnTypes(): array
    {
        $tableName = $this->_sqlTable();
        if (empty($this->indexPool[$tableName]['columnsType'])) {
            $types = [];
            if (empty($this->indexPool[$tableName]['describe'])) {
                $this->indexPool[$tableName]['describe'] = $this->describe();
            }
            $info = $this->indexPool[$tableName]['describe'];
            foreach ($info->result() as $row) {
                $types[$row['Field']] = $row['Type'];
            }
            $this->indexPool[$tableName]['columnsType'] = $types;
        }

        return $this->indexPool[$tableName]['columnsType'];
    }

    /**
     * @param bool $sync
     *
     * @return ResultSet
     */
    public function optimize(bool $sync = false): ResultSet
    {
        $this->command = 'OPTIMIZE';
        $sql = 'OPTIMIZE INDEX ' . $this->_sqlTable();
        if ($sync) {
            $sql .= ' OPTION sync=1';
        }
        $this->client->query($sql);
        $result = [
            'command' => $this->command,
            'query' => $sql,
            'original' => null,
            'result' => [
                'type' => 'bool',
                'data' => true,
            ]
        ];

        return new ResultSet($result);
    }

    /**
     * @param string|array|null $columns
     *
     * @return ResultSet
     */
    public function search($columns = '*'): ResultSet
    {
        if (func_num_args()) {
            $this->selectColumns($columns);
        }
        else {
            $this->selectColumns(null);
        }

        return $this->exec();
    }


    /**
     * @param string|array|null $columns
     *
     * @return mixed|null
     */
    public function get($columns = '*')
    {
        if (func_num_args()) {
            $this->selectColumns($columns);
        }
        else {
            $this->selectColumns(null);
        }

        return $this->exec()->result();
    }

    /**
     * @return mixed|null
     */
    public function first()
    {
        return $this->limit(1)->exec()->first();
    }

    /**
     * @param int $id
     *
     * @return mixed|null
     */
    public function find(int $id)
    {
        return $this->where('id', $id)->first();
    }

    /**
     * @param array $data
     * @param int|null $id
     *
     * @return ResultSet
     */
    public function insert(array $data, ?int $id = 0): ResultSet
    {
        $this->command = 'INSERT';
        $this->update = $data;

        $request = $this->parse();
        $result = $this->_execQuery($request);

        return new ResultSet($result, 'inserted');
    }

    /**
     * @param array $data
     * @param int|null $id
     *
     * @return ResultSet
     */
    public function update(array $data, ?int $id = 0): ResultSet
    {
        $this->command = 'UPDATE';
        $this->update = $data;
        if ($id) {
            $this->where('id', $id);
        }

        $request = $this->parse();
        $result = $this->_execQuery($request);

        return new ResultSet($result, 'updated');
    }

    /**
     * @param array $data
     * @param int|null $id
     *
     * @return ResultSet
     */
    public function replace(array $data, ?int $id = 0): ResultSet
    {
        $this->command = 'REPLACE';

        $this->update = $data;
        if ($id) {
            $this->update['id'] = $id;
        }

        $request = $this->parse();
        $result = $this->_execQuery($request);

        return new ResultSet($result, 'replaced');
    }

    /**
     * @return ResultSet
     */
    public function status(): ResultSet
    {
        $sql = 'SHOW INDEX ' . $this->_sqlTable() . ' STATUS';
        $response = $this->client->query($sql);
        $result = [
            'command' => 'STATUS',
            'query' => $sql,
            'original' => null,
            'result' => [
                'type' => 'array',
                'data' => $response['data'],
            ]
        ];

        return new ResultSet($result);
    }

    /**
     * @return ResultSet
     */
    public function settings(): ResultSet
    {
        $sql = 'SHOW INDEX ' . $this->_sqlTable() . ' SETTINGS';
        $response = $this->client->query($sql);
        $result = [
            'command' => 'SETTINGS',
            'query' => $sql,
            'original' => null,
            'result' => [
                'type' => 'array',
                'data' => $response['data'],
            ]
        ];

        return new ResultSet($result);
    }
}
