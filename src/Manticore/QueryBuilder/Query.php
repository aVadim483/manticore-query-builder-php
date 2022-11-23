<?php

declare(strict_types=1);

namespace avadim\Manticore\QueryBuilder;

use avadim\Manticore\QueryBuilder\Client\PDOClient;
use Illuminate\Support\Collection;
use Manticoresearch\Client;
use Manticoresearch\Index;
use avadim\Manticore\QueryBuilder\Schema\SchemaIndex;

class Query
{
    private array $config;
    private ?array $index;
    private string $prefix;
    private bool $forcePrefix = false;

    private $client;
    private Parser $parser;
    private array $indexPool = [];

    private SchemaIndex $schema;

    private ?string $sql = null;
    private ?string $command = null;

    private array $select = [];
    private array $update = [];

    private ?string $match = null;
    private array $limit = [];
    private array $options = [];
    private QueryConditionSet $conditions;

    /**
     * @param array $config
     * @param string|null $indexName
     */
    public function __construct(array $config, ?string $indexName = null)
    {
        $this->config = $config;
        $this->prefix = $config['prefix'] ?? '';
        if (!empty($config['force_prefix'])) {
            $this->forcePrefix = true;
        }
        $this->parser = new Parser($this->prefix);
        $this->schema = new SchemaIndex();

        if (is_object($config['client'])) {
            $this->client = $config['client'];
        }
        else {
            //$this->client = new Client($config['client']);
            $this->client = new PDOClient();
        }

        $this->conditions = new QueryConditionSet();
        if ($indexName) {
            $this->index($indexName);
        }
    }

    /**
     * @return array
     */
    public function parse(): array
    {
        if ($this->sql) {
            return $this->parser->parse($this->sql);
        }

        if (!$this->command) {
            $this->select('*');
        }

        return [
            'command' => $this->command,
            'index' => $this->_sqlIndex(),
            'query' => $this->_makeSql(),
            'original' => null,
        ];
    }

    /**
     * @param array $rows
     *
     * @return array
     */
    protected function _castResult(array $rows): array
    {
        $types = $this->columnsType();
        $result = [];
        foreach ($rows as $num => $row) {
            $row['_id'] = (int)$row['_id'];
            $row['_score'] = (int)$row['_score'];
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
            }
            $result[$resNum] = $row;
        }

        return $result;
    }

    /**
     * @param array $parsedSql
     *
     * @return array
     */
    protected function _execQuery(array $parsedSql): array
    {
        $index = $this->_sqlIndex();
        if (!$index && !empty($parsedSql['index'])) {
            $this->index($parsedSql['index']);
        }

        $time = microtime(true);
        if ($parsedSql['command'] === 'INSERT') {
            $response = (int)$this->client->insert($parsedSql['query']);
        }
        elseif ($parsedSql['command'] === 'SELECT') {
            $query = 'SELECT id as _id, weight() as _score, ' . substr($parsedSql['query'], 6);
            //$query = $parsedSql['query'];
            $response = $this->client->select($query);
        }
        else {
            $response = $this->client->query($parsedSql['query']);
        }
        $time = microtime(true) - $time;

        $result = [
            'command' => $parsedSql['command'],
            'query' => $parsedSql['query'],
            'exec_time' => $time,
            'result' => []
        ];

        if ($parsedSql['command'] === 'SHOW TABLES') {
            $data = [];
            foreach ($response as $n => $index) {
                foreach ($index as $key => $val) {
                    $data[$n][$key] = $val;
                    if ($key === 'Index') {
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
                'data' => Collection::make($data),
            ];
        }
        elseif ($parsedSql['command'] === 'SELECT') {
            $result['result'] = [
                'type' => 'collection',
                'data' => Collection::make($this->_castResult($response)),
            ];
            $meta = $this->client->select('SHOW META');
            $result['meta'] = [];
            foreach ($meta as $item) {
                $result['meta'][$item['Variable_name']] = $item['Value'];
            }
        }
        elseif ($response && is_array($response)) {
            $row = reset($response);
            if (array_key_first($response) === 0 && is_array($row)) {
                $result['result'] = [
                    'type' => 'collection',
                    'data' => Collection::make($response),
                ];
            }
            else {
                $result['result'] = [
                    'type' => 'array',
                    'data' => $response,
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
    protected function resolveIndexName(string $name): string
    {
        $name = trim($name);
        if (strpos($name, '?') !== false) {
            if ($name[0] === '?') {
                $name = $this->prefix . substr($name, 1);
            }
            elseif ($name[0] === $name[-1] && substr($name, 0, 2) === '`?') {
                $name = $name[0] . $this->prefix . substr($name, 2, -1) . $name[0];
            }
        }
        elseif ($this->prefix && $this->forcePrefix) {
            if ($name[0] === '`' && $name[0] === $name[-1] && substr($name, 0, 2) === '`?') {
                $name = $name[0] . $this->prefix . substr($name, 1, -1) . $name[0];
            }
            else {
                $name = $this->prefix . $name;
            }
        }

        return $name;
    }

    /**
     * Set index name
     *
     * @param string $name
     *
     * @return $this
     */
    public function index(string $name): Query
    {
        $this->index = ['real_name' => Parser::resolveIndexName($name)];

        return $this;
    }

    /**
     * @param string $name
     *
     * @return $this
     */
    public function table(string $name): Query
    {
        return $this->index($name);
    }

    /**
     * @param string $match
     *
     * @return $this
     */
    public function match(string $match): Query
    {
        $this->select(null);
        $this->match = $match;

        return $this;
    }

    /**
     * @param $match
     *
     * @return $this
     */
    public function whereMatch($match): Query
    {
        return $this->match($match);
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
        } else {
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
        } else {
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
    public function andWhere($field, $arg1, $arg2 = null): Query
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
    public function orWhere($field, $arg1, $arg2 = null): Query
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
     * @return string
     */
    protected function _sqlSelectColumns(): string
    {
        if ($this->select) {
            return implode(', ', array_map('addslashes', $this->select));
        }

        return '*';
    }

    /**
     * @return string
     */
    protected function _sqlUpdateColumns(): string
    {
        if ($this->update) {
            $set = [];
            foreach ($this->update as $column => $value) {
                $set[] = $column . '=' . Parser::formatValue($value);
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
    protected function _sqlIndex(): ?string
    {
        return $this->index['real_name'] ?? '';
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
        $where = $this->conditions->asString();

        return $where;
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

    protected function _makeSql()
    {
        if ($this->command === 'SELECT' || $this->command === 'UPDATE' || $this->command === 'DELETE') {
            if ($this->command === 'SELECT') {
                $sql = 'SELECT ' . $this->_sqlSelectColumns() . ' FROM ' . $this->_sqlIndex();
            }
            elseif ($this->command === 'UPDATE') {
                $sql = 'UPDATE ' . $this->_sqlIndex() . ' SET ' . $this->_sqlUpdateColumns();
            }
            else {
                $sql = 'DELETE FROM ' . $this->_sqlIndex();
            }

            $match = $this->_sqlMatch();
            $where = $this->_sqlWhere();
            if ($match !== null) {
                $sql .= ' WHERE MATCH(' . $match . ')';
            }
            if ($where) {
                if ($match !== null) {
                    $sql .= ' AND (' . $where . ')';
                }
                else {
                    $sql .= ' WHERE' . $where;
                }
            }
            if ($limit = $this->_sqlLimit()) {
                $sql .= ' LIMIT ' . $limit;
            }
            if ($options = $this->_sqlOptions()) {
                $sql .= ' OPTION ' . $options;
            }
        }

        elseif ($this->command === 'INSERT') {
            $columns = $values = [];
            $types = $this->columnsType();
            foreach ($this->update as $col => $val) {
                $columns[] = $col;
                $values[] = Parser::formatValue($val, $types[$col] ?? null);
            }
            $sql = 'INSERT INTO ' . $this->_sqlIndex() . '(' . implode(',', $columns) . ') VALUES('. implode(',', $values) . ')';
        }

        elseif ($this->command === 'CREATE') {
            $sql = 'CREATE TABLE ' . $this->_sqlIndex() . '(' . $this->_sqlSchemaColumns() . ')';
            if (!empty($this->index['engine'])) {
                $sql .= ' engine=\'' . $this->index['engine'] . '\'';
            }
        }
        else {
            $sql = '';
        }
var_dump($sql);
        return $sql;
    }

    /**
     * @param string|array|null $columns
     *
     * @return $this
     */
    public function select($columns = '*'): Query
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
     * Make schema for a new index
     *
     * @param array|Index|callable $schema
     *
     * @return $this
     */
    public function schema($schema): Query
    {
        $this->schema = new SchemaIndex();
        if ($schema instanceof SchemaIndex) {
            $this->schema = $schema;
        }
        elseif (is_callable($schema)) {
            $schema($this->schema);
        }
        else {
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
        $this->index['engine'] = $engine;

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
     * @param $limit
     * @param $offset
     *
     * @return $this
     */
    public function limit($limit, $offset = null): Query
    {
        if ($limit === null) {
            $this->limit = [];
        }
        elseif (is_array($limit)) {
            $this->limit = array_values($limit);
        }
        elseif ($offset !== null) {
            $this->limit = [$limit, $offset];
        }
        else {
            $this->limit = [$limit, null];
        }

        return $this;
    }

    /**
     * @return Result
     */
    public function exec(): Result
    {
        $request = $this->parse();

        $result = $this->_execQuery($request);

        return new Result($result);
    }

    /**
     * Allows to get the query transformation tree of a query without running it. Useful for testing queries.
     *
     * @return Result
     */
    public function explain(): Result
    {
        $response = [
            'command' => 'EXPLAIN',
            'query' => $this->_sqlMatch(true),
            'original' => null,
        ];

        $params = [
            'index' => $this->_sqlIndex(),
            'body' => [
                'query' => $this->_sqlMatch(),
            ],
        ];

        $response['result'] = $this->_explainQuery($params);

        return new Result($response);
    }

    /**
     * @return Result
     */
    public function delete(): Result
    {
        $this->command = 'DELETE';

        return $this->exec();
    }

    /**
     * @param string $name
     * @param array|SchemaIndex|callable $schema
     *
     * @return Result
     */
    public function create(string $name, $schema): Result
    {
        $this->index($name);
        $this->schema($schema);
        $this->command = 'CREATE';

        $request = $this->parse();
        $result = $this->_execQuery($request);

        return new Result($result);
    }

    /**
     * @param array|null $schema
     *
     * @return Result
     */
    public function alter(?array $schema = null): Result
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

        return new Result($this->_execQuery($result));
    }

    /**
     * Truncate index
     *
     * @param bool|null $reconfigure
     *
     * @return Result
     */
    public function truncate(?bool $reconfigure = false): Result
    {
        $this->command = 'TRUNCATE';
        $sql = 'TRUNCATE TABLE ' . $this->_sqlIndex() . (!empty($reconfigure) ? ' WITH RECONFIGURE' : '');
        $request = [
            'command' => $this->command,
            'query' => $sql,
            'original' => null,
        ];
        $result = $this->_execQuery($request);

        return new Result($result);
    }

    /**
     * Drop index
     *
     * @param bool|null $ifExists
     *
     * @return Result
     */
    public function drop(?bool $ifExists = false): Result
    {
        $this->command = 'DROP';
        $sql = 'DROP TABLE ' . (!empty($ifExists) ? 'IF EXISTS ' : '') . $this->_sqlIndex();
        $request = [
            'command' => $this->command,
            'query' => $sql,
            'original' => null,
        ];
        $result = $this->_execQuery($request);

        return new Result($result);
    }

    /**
     * @return Result
     */
    public function dropIfExists(): Result
    {
        return $this->drop(true);
    }

    /**
     * @param string|null $pattern
     *
     * @return Result
     */
    public function showTables(?string $pattern = null): Result
    {
        $this->command = 'SHOW TABLES';
        $sql = 'SHOW TABLES';
        if ($pattern) {
            $sql .= ' LIKE \'' . Parser::resolveIndexName($pattern) . '\'';
        }
        $request = [
            'command' => $this->command,
            'query' => $sql,
            'original' => null,
        ];
        $result = $this->_execQuery($request);

        return new Result($result);
    }

    /**
     * @param string|null $pattern
     *
     * @return Result
     */
    public function showVariables(?string $pattern = null): Result
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

        return new Result($result);
    }

    /**
     * DESCRIBE statement lists table columns and their associated types. Columns are document ID, full-text fields,
     * and attributes
     *
     * @return Result
     */
    public function describe(): Result
    {
        $indexName = $this->_sqlIndex();
        if (empty($this->indexPool[$indexName])) {
            $sql = 'DESCRIBE ' . $indexName;
            $this->indexPool[$indexName]['describe'] = $this->client->query($sql);
        }
        $result = [
            'command' => $this->command,
            'query' => '',
            'original' => null,
            'result' => [
                'type' => 'array',
                'data' => $this->indexPool[$indexName]['describe'],
            ]
        ];

        return new Result($result);
    }

    /**
     * @return array
     */
    public function columnsType(): array
    {
        $types = [];
        $info = $this->describe();
        foreach ($info->result() as $row) {
            $types[$row['Field']] = $row['Type'];
        }

        return $types;
    }

    /**
     * @param bool $sync
     *
     * @return Result
     */
    public function optimize(bool $sync = false): Result
    {
        $this->command = 'OPTIMIZE';
        $sql = 'OPTIMIZE INDEX ' . $this->_sqlIndex();
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

        return new Result($result);
    }

    /**
     * @return Result
     */
    public function all(): Result
    {
        $this->select()->limit(null);

        return $this->get();
    }

    /**
     * @param string|array|null $columns
     *
     * @return Result
     */
    public function get($columns = '*'): Result
    {
        if (func_num_args()) {
            $this->select($columns);
        }
        else {
            $this->select(null);
        }

        return $this->exec();
    }

    /**
     * @param int $id
     *
     * @return Result
     */
    public function find(int $id): Result
    {
        $result = $this->where('id', $id)->limit(1)->get();
        $result->setResult('record', $result->first());

        return $result;
    }

    /**
     * @return Result
     */
    public function first(): Result
    {
        $result = $this->limit(1)->get();
        $result->setResult('record', $result->first());

        return $result;
    }

    /**
     * @param array $data
     * @param int|null $id
     *
     * @return Result
     */
    public function insert(array $data, ?int $id = 0): Result
    {
        $this->command = 'INSERT';
        $this->update = $data;

        return $this->exec();
    }

    /**
     * @param array $data
     * @param int|null $id
     *
     * @return Result
     */
    public function update(array $data, ?int $id = 0): Result
    {
        $this->command = 'UPDATE';
        $this->update = $data;

        return $this->exec();
    }

    /**
     * @param array $data
     * @param int|null $id
     *
     * @return Result
     */
    public function replace(array $data, ?int $id = 0): Result
    {
        $this->command = 'REPLACE';
        $columns = implode(',', array_keys($data));
        $values = Parser::formatArray(array_values($data));
        $sql = 'REPLACE INTO ' . $this->_sqlIndex() . '(' . $columns . ') VALUES'. $values;

        $request = [
            'command' => $this->command,
            'query' => $sql,
            'original' => null,
        ];

        if (isset($data['id'])) {
            $id = $data['id'];
            unset($data['id']);
        }

        $params = [
            'body' => [
                'index' => $this->_sqlIndex(),
                'id' => $id,
                'doc' => $data
            ]
        ];

        $response = $this->client->replace($params);
        $result['result']['items'][] = ['_id' => $response['_id']];

        $result['exec_time'] = $this->client->getLastResponse()->getTime();

        return new Result($result);
    }


}