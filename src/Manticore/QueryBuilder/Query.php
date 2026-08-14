<?php

declare(strict_types=1);

namespace avadim\Manticore\QueryBuilder;

use avadim\Manticore\QueryBuilder\Client\PDOClient;
use avadim\Manticore\QueryBuilder\Schema\SchemaTable;
use Psr\Log\LoggerInterface;

class Query
{
    use WhereConditionsTrait;

    /**
     * Placeholder for the argument of a unary operator.
     *
     * IS NULL / IS NOT NULL take no argument, but where($field, $arg) is the shortcut for
     * where($field, '=', $arg), so passing the operator alone would turn it into a value.
     * This third argument is never used by the condition itself - it only keeps that shortcut
     * from kicking in, which is why where($field, 'IS NULL') still compares against the
     * string "IS NULL".
     */
    public const NO_ARG = false;

    private array $config;
    private ?array $table;
    private string $prefix;
    private bool $forcePrefix = false;

    private $client;
    private Parser $parser;
    private array $indexPool = [];

    /** @var array one-element slot holding the last ResultSet, see setResultSlot() */
    private array $resultSlot = [];

    private SchemaTable $schema;

    private ?string $sql = null;
    private ?string $command = null;

    private array $select = [];
    private array $update = [];

    private ?string $match = null;
    private array $group = [];
    private array $having = [];
    private array $order = [];
    private array $limit = [];
    private array $options = [];
    private array $facets = [];
    private array $highlight = [];
    private array $params = [];
    private bool $ifNotExists = false;

    private QueryConditionSet $conditions;

    private ?LoggerInterface $logger = null;
    private array $logEnabled = [];

    /**
     * @param array $config
     * @param string|null $tableName
     * @param LoggerInterface|false|null $logger
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
        $this->setLogger($logger);

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

    /**
     * @param LoggerInterface|false|null $logger
     *
     * @return $this
     */
    public function setLogger($logger): Query
    {
        if ($logger) {
            $this->logger = $logger;
        }
        elseif ($logger === false) {
            $this->logger = null;
        }

        return $this;
    }

    public function logger(): ?LoggerInterface
    {
        return $this->logger;
    }

    /**
     * Logs with an arbitrary level.
     *
     * @param mixed $level
     * @param string $message
     * @param array $context
     *
     * @return void
     */
    protected function log($level, string $message, array $context = [])
    {
        if ($this->logger) {
            $this->logger->log($level, $message, $context);
        }
    }

    /**
     * Tells a set of rows from a single row: INSERT/REPLACE accept both.
     *
     * A set of rows is a list, i.e. its first key is numeric and holds an array.
     *
     * @param array $data
     *
     * @return bool
     */
    public static function isMultiRow(array $data): bool
    {
        if (!$data) {
            return false;
        }
        $firstKey = array_key_first($data);

        return is_numeric($firstKey) && is_array($data[$firstKey]);
    }

    /**
     * @param mixed $param
     *
     * @return string
     */
    public static function escapeParam($param): string
    {
        if (is_bool($param)) {
            return $param ? '1' : '0';
        }

        return is_string($param) ? addslashes($param) : (string)$param;
    }

    public static function quoteParam($param): string
    {
        if (!is_string($param) && is_numeric($param)) {
            $result = (string)$param;
            if (is_float($param)) {
                $result = str_replace(',', '.', $result);
            }
        }
        elseif (is_bool($param)) {
            $result = ($param ? '1' : '0');
        }
        elseif (preg_match('#^:\w+$#', $param)) {
            $result = $param;
        }
        else {
            $result = '\'' . self::escapeParam($param) . '\'';
        }

        return $result;
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
            $query['params'] = $this->params ?: [];

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
            'params' => $this->params ?: [],
        ];
        if (!empty($this->facets)) {
            $query['facets'] = $this->facets;
        }

        return $query;
    }

    /**
     * @param bool|null $substParams
     * @return string
     */
    public function toSql(?bool $substParams = false): string
    {
        $querySet = $this->parse();
        if (!empty($querySet['params'])) {
            $subst = [];
            foreach ($querySet['params'] as $key => $val) {
                //$subst[$key] = self::quoteParam($val);
                $subst[$key] = $val;
            }

            return str_replace(array_keys($subst), array_values($subst), $querySet['query']);
        }

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
            $resNum = $row['_id'] ?? ($row['id'] ?? $num);
            foreach ($row as $col => $val) {
                if (isset($types[$col])) {
                    switch ($types[$col]) {
                        case 'bool':
                            $val = (int)$val;
                            $row[$col] = (bool)$val;
                            break;
                        case 'bigint':
                        case 'integer':
                        // DESCRIBE reports an integer column as "uint"
                        case 'uint':
                        case 'timestamp':
                            $row[$col] = (int)$val;
                            break;
                        case 'float':
                            $row[$col] = (float)$val;
                            break;
                        case 'multi':
                        case 'multi64':
                        case 'mva':
                        // DESCRIBE reports a multi64 column as "mva64"
                        case 'mva64':
                            $val = (string)$val;
                            // an empty attribute is an empty list, not a list holding one zero
                            $row[$col] = ($val === '') ? [] : array_map('intval', explode(',', $val));
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
     * @param string|null $status
     *
     * @return ResultSet
     */
    protected function _execQuery(array $parsedSql, ?string $status = null): ResultSet
    {
        $index = $this->_sqlTable();
        if (!$index && !empty($parsedSql['table'])) {
            $this->table($parsedSql['table']);
        }

        $result = [
            'command' => $parsedSql['command'],
            'query' => $parsedSql['query'],
            'exec_time' => 0.0,
            'total_time' => 0.0,
        ];
        $context = $parsedSql;
        $context['params'] = $this->params;
        $time = microtime(true);
        try {
            // REPLACE goes the INSERT way on purpose: the server counts it as an insert for
            // LAST_INSERT_ID(), so the id of the written row is there to be picked up - both
            // the generated one and the explicit one, and a list of them for a set of rows
            if ($parsedSql['command'] === 'INSERT' || $parsedSql['command'] === 'REPLACE') {
                $response = $this->client->insert($parsedSql['query'], $this->params);
            }
            elseif ($parsedSql['command'] === 'SELECT') {
                if (!$this->select) {
                    $query = 'SELECT id as _id, weight() as _score, ' . substr($parsedSql['query'], 6);
                }
                else {
                    $query = $parsedSql['query'];
                }
                $response = $this->client->select($query, $this->params);
            }
            else {
                $response = $this->client->query($parsedSql['query'], $this->params);
            }

            $result['exec_time'] = microtime(true) - $time;

            $context['exec_time'] = $result['exec_time'];
            $context['response'] = $response;
            $this->log('info', 'Manticore Query:', $context);

            if ($parsedSql['command'] === 'SHOW TABLES') {
                $data = [];
                foreach ($response['data'] as $n => $row) {
                    // Manticore used to call this column "Index" and calls it "Table" since v6;
                    // both are reported back, together with "Name" - the logical name, i.e. the
                    // one with the prefix mapped back to the "?table" placeholder
                    $tableName = $row['Index'] ?? ($row['Table'] ?? null);
                    $names = [];
                    if ($tableName !== null) {
                        $names['Index'] = $tableName;
                        $names['Table'] = $tableName;
                        $names['Name'] = ($this->prefix && strpos($tableName, $this->prefix) === 0)
                            ? '?' . substr($tableName, strlen($this->prefix))
                            : $tableName;
                    }
                    $data[$n] = array_merge($names, $row);
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
            elseif ($parsedSql['command'] === 'REPLACE') {
                $result['result'] = [
                    'type' => 'id',
                    'data' => $response['data'],
                    'status' => 'replaced',
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
                // an answer without a rowset leaves meta empty rather than raising a warning
                // that _execQuery() would catch and report as a failed query
                foreach ($meta['data'][0] ?? [] as $item) {
                    if ($item['Variable_name'] === 'time') {
                        $value = (float)$item['Value'];
                    }
                    elseif ($item['Variable_name'] === 'total' || $item['Variable_name'] === 'total_found' || in_array(substr($item['Variable_name'], 0, 5), ['docs[', 'hits['])) {
                        $value = (int)$item['Value'];
                    }
                    else {
                        $value = $item['Value'];
                    }
                    $result['meta'][$item['Variable_name']] = $value;
                }
            }
            elseif ($parsedSql['command'] === 'UPDATE') {
                $result['result'] = [
                    'type' => 'id',
                    'data' => $response['count'],
                    'status' => 'updated',
                ];
            }
            elseif ($parsedSql['command'] === 'DELETE') {
                // the server answers with no rowset at all, so without this branch DELETE would
                // fall through to the generic "true" below and the number of deleted rows -
                // the only thing DELETE has to say - would be dropped on the floor
                $result['result'] = [
                    'type' => 'id',
                    'data' => $response['count'] ?? 0,
                    'status' => 'deleted',
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
        }
        catch (\Throwable $e) {
            $this->log('error', $e->getMessage(), $context);
            $result['response']['error'] = $e->getMessage();
        }

        $result['total_time'] = microtime(true) - $time;
        $resultSet = $this->_resultSet($result, $status);
        $this->log('debug', 'Manticore Result:', $result);

        return $resultSet;
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

    public function highlight(array $options = [], array $fields = [], ?string $query = null): Query
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
            $value = trim($value);
            if ($value !== '' && $value[0] === '(' && $value[-1] === ')') {
                $value = substr($value, 1, -1);
            }
            foreach (explode(',', $value) as $str) {
                if (trim($str) === '') {
                    continue;
                }
                $pair = array_map('trim', explode('=', $str, 2));
                $field = $pair[0];
                $weight = $pair[1] ?? '';
                $this->fieldWeight($field, $weight === '' ? null : (int)$weight);
            }
        }
        else {
            foreach ($value as $field => $weight) {
                $this->fieldWeight($field, $weight === null ? null : (int)$weight);
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
     * Usage:
     *      where('field', '>', 123)
     *      where('field', 123) - equal to where('field', '=', 123)
     *      where('field', null) - equal to where('field', 'IS NULL')
     *      where(['field' => 123, 'other' => 'value'])
     *      where(function ($condition) { $condition->where(...); })
     *
     * @param $field
     * @param $arg1
     * @param $arg2
     *
     * @return $this
     */
    public function where($field, $arg1 = null, $arg2 = null): Query
    {
        // the arguments are forwarded as they came: their number is what tells
        // where($field, $op, null) from where($field, $op)
        $this->conditions->andWhere(...func_get_args());

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
        $this->conditions->andWhere(...func_get_args());

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
        $this->conditions->orWhere(...func_get_args());

        return $this;
    }

    /**
     * @return string
     */
    protected function _sqlSelectColumns(): string
    {
        if ($this->select) {
            // the list holds column names and expressions, not values: escaping it would break
            // every literal inside, e.g. IN(color, 'black') - values go through bind() instead
            $result = implode(', ', $this->select);
        }
        else {
            $result = '*';
        }

        if ($this->highlight) {
            $highlight = 'HIGHLIGHT(';
            if (!empty($this->highlight['options'])) {
                $options = [];
                foreach ($this->highlight['options'] as $key => $val) {
                    $options[] = $key . '=\'' . self::escapeParam($val) . '\'';
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
    protected function _sqlSchema(): string
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
            return !$raw ? self::quoteParam($this->match) : $this->match;
        }

        return null;
    }

    /**
     * @param bool $needBool
     *
     * @return string
     */
    protected function _sqlWhere(?bool $needBool = false): string
    {
        return trim($this->conditions->asString($needBool));
    }

    /**
     * @return string
     */
    protected function _sqlLimit(): string
    {
        if (isset($this->limit[0])) {
            $offset = isset($this->limit[1]) ? $this->limit[1] . ',' : '';

            return $offset . $this->limit[0];
        }
        if (isset($this->limit[1])) {
            // the server has no offset of its own: "OFFSET 5" alone is a syntax error, and the
            // MySQL workaround of a huge LIMIT makes Manticore ignore the offset altogether,
            // so silently dropping it would quietly return the first page instead of the asked one
            throw new \LogicException('offset() needs a limit(): Manticore has no OFFSET without LIMIT');
        }

        return '';
    }

    protected function _sqlGroup(): string
    {
        if ($this->group) {
            return implode(',', $this->group);
        }

        return '';
    }

    protected function _sqlHaving(): string
    {
        // a single expression by design: "HAVING a AND b", "HAVING (a AND b)" and "HAVING a, b"
        // are all syntax errors for the server, so having() refuses to collect a second one
        return $this->having ? (string)reset($this->having) : '';
    }

    /**
     * @return string
     */
    protected function _sqlOrder(): string
    {
        if ($this->order) {
            return implode(',', $this->order);
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
                    if ($where[0] === '(' && substr($where, -1) === ')' && substr_count($where, '(') === 1) {
                        $sql .= ' AND ' . $where;
                    }
                    else {
                        $sql .= ' AND (' . $where . ')';
                    }
                }
                else {
                    $sql .= ' WHERE ' . $where;
                }
            }
            if ($group = $this->_sqlGroup()) {
                $sql .= ' GROUP BY ' . $group;
            }
            if ($having = $this->_sqlHaving()) {
                $sql .= ' HAVING ' . $having;
            }
            if ($orders = $this->_sqlOrder()) {
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
            if (self::isMultiRow($this->update)) {
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
            $sql = 'CREATE TABLE ' . ($this->ifNotExists ? 'IF NOT EXISTS ' : '') . $this->_sqlTable() . $this->_sqlSchema();
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
        if (is_string($columns) || is_array($columns)) {
            $this->select = Parser::columnList([$columns]);
        }

        return $this;
    }

    /**
     * select('id, title'), select(['id', 'title']) and select('id', 'title') are the same
     *
     * @param string|array|null $columns
     * @param string|array ...$more
     *
     * @return $this
     */
    public function select($columns = '*', ...$more): Query
    {
        if ($more) {
            return $this->selectColumns(array_merge([$columns], $more));
        }

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
            foreach($schema as $name => $column) {
                if ($name === '_options') {
                    $this->schema->tableOptions($column);
                    continue;
                }
                elseif (is_int($name) && is_string($column)) {
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
     * Set options for CREATE statement
     *
     * @param array $options
     *
     * @return $this
     */
    public function options(array $options): Query
    {
        $this->table['options'] = $options;

        return $this;
    }


    /**
     * groupBy('cat'), groupBy('cat, brand'), groupBy(['cat', 'brand']) and groupBy('cat', 'brand')
     *
     * @param string|array ...$names
     *
     * @return $this
     */
    public function groupBy(...$names): Query
    {
        foreach (Parser::columnList($names) as $name) {
            $this->group[] = $name;
        }

        return $this;
    }

    /**
     * having('count(*) > 1') - a raw expression
     * having('cnt', '>', 1) - column, operator and value, the value gets quoted
     * having('cnt', 1) => having('cnt', '=', 1)
     * having('cnt', 'IN', [2, 3]) and having('cnt', 'BETWEEN', [2, 3]) - array arguments
     *
     * Only one expression can be set: the server grammar accepts a single HAVING condition,
     * so a second call throws instead of building SQL that Manticore rejects.
     *
     * @param string $column
     * @param mixed|null $operator
     * @param mixed|null $value
     *
     * @return $this
     */
    public function having(string $column, $operator = null, $value = null): Query
    {
        if ($this->having) {
            throw new \LogicException('Manticore accepts a single HAVING expression, having() cannot be called twice');
        }
        if (func_num_args() === 1) {
            $this->having[] = $column;

            return $this;
        }
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }
        $op = strtoupper(trim((string)$operator));
        if (is_array($value)) {
            $values = array_map([self::class, 'quoteParam'], array_values($value));
            if ($op === 'BETWEEN' || $op === 'NOT BETWEEN') {
                $arg = ($values[0] ?? '') . ' AND ' . ($values[1] ?? '');
            }
            else {
                $arg = '(' . implode(',', $values) . ')';
            }
        }
        else {
            $arg = self::quoteParam($value);
        }
        $this->having[] = $column . ' ' . $op . ' ' . $arg;

        return $this;
    }

    /**
     * orderBy('price'), orderBy('price', 'desc'), orderBy('price, id') and orderBy(['price', 'id'])
     *
     * @param string|array $names
     * @param string|null $direction "asc" (default) or "desc"
     *
     * @return $this
     */
    public function orderBy($names, ?string $direction = null): Query
    {
        foreach (Parser::orderList($names, $direction ?? 'ASC') as $order) {
            $this->order[] = $order;
        }

        return $this;
    }

    /**
     * @param string|array $names
     *
     * @return $this
     */
    public function orderByDesc($names): Query
    {
        foreach (Parser::orderList($names, 'DESC') as $order) {
            $this->order[] = $order;
        }

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
        if (is_array($param1)) {
            // limit([<limit>]) or limit([<offset>, <limit>])
            $args = array_values($param1);
            if (!$args) {
                return $this;
            }
            $param1 = (int)$args[0];
            $param2 = isset($args[1]) ? (int)$args[1] : $param2;
        }

        if ($param2 === null) {
            // limit - an offset set before is kept, so that offset(20)->limit(10) and
            // limit(10)->offset(20) mean the same thing
            $this->limit = [(int)$param1, $this->limit[1] ?? null];
        }
        else {
            // limit, offset
            $this->limit = [$param2, (int)$param1];
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
        $this->params = [];
        foreach ($params as $key => $val) {
            if ($key[0] !== ':') {
                $key = ':' . $key;
            }
            $this->params[$key] = $val;
        }

        return $this;
    }

    /**
     * @return $this
     */
    public function ifNotExists(): Query
    {
        $this->ifNotExists = true;

        return $this;
    }

    /**
     * @return ResultSet
     */
    public function exec(): ResultSet
    {
        $request = $this->parse();

        return $this->_execQuery($request);
    }

    /**
     * Allows to get the query transformation tree of a query without running it. Useful for testing queries.
     *
     * Explains the full-text expression given to match(), i.e.
     *      table('?products')->match('brown fox')->explain()
     * The tree is available as variable('transformed_tree'), and the rows of result() carry
     * it under the "Variable_name"/"Value" keys, the same way status() and settings() do.
     *
     * @param string|null $format render format of the tree, e.g. "dot" for graphviz
     *
     * @return ResultSet
     */
    public function explain(?string $format = null): ResultSet
    {
        $this->command = 'EXPLAIN';
        $sql = 'EXPLAIN QUERY ' . $this->_sqlTable() . ' ' . ($this->_sqlMatch() ?? '\'\'');
        if ($format) {
            $sql .= ' OPTION format=' . self::escapeParam($format);
        }

        $response = $this->_execServiceQuery($sql, $error);

        // the server answers with a "Variable"/"Value" pair; rename the first column so that
        // ResultSet picks the tree up as a variable
        $data = [];
        foreach ($response['data'] ?? [] as $row) {
            $name = $row['Variable'] ?? ($row['Variable_name'] ?? null);
            if ($name !== null) {
                $data[] = ['Variable_name' => $name, 'Value' => $row['Value'] ?? ''];
            }
        }

        $result = [
            'command' => $this->command,
            'query' => $sql,
            'original' => null,
            'result' => [
                'type' => 'collection',
                'data' => $data,
            ],
        ];
        if ($error !== null) {
            $result['response']['error'] = $error;
        }

        return $this->_resultSet($result);
    }

    /**
     * DELETE, answering with the full ResultSet
     *
     * @param int|null $id
     *
     * @return ResultSet
     */
    public function deleteResultSet(?int $id = null): ResultSet
    {
        $this->command = 'DELETE';
        if ($id) {
            $this->where('id', $id);
        }

        $request = $this->parse();

        return $this->_execQuery($request, 'deleted');
    }

    /**
     * DELETE, answering with the number of deleted rows the way Laravel does
     *
     * A failed statement gives 0 as well, and 0 alone does not tell "nothing matched" from
     * "the statement did not run" - the reason is in deleteResultSet() or lastResultSet().
     *
     * @param int|null $id
     *
     * @return int
     */
    public function delete(?int $id = null): int
    {
        return (int)$this->deleteResultSet($id)->result();
    }

    /**
     * @param string $name
     *
     * @return bool
     */
    public function hasTable(string $name): bool
    {
        $res = $this->showTables($name)->result();

        return !empty($res);
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
        if (!empty($this->table['options'])) {
            $this->schema->tableOptions($this->table['options']);
        }
        if (!empty($this->table['engine'])) {
            $this->schema->tableEngine($this->table['engine']);
        }
        $this->command = 'CREATE';

        $request = $this->parse();
        // a table of this name may have existed before with another set of columns
        $this->_forgetSchema();

        return $this->_execQuery($request, 'created');
    }

    /**
     * Bring the table to the given schema (not implemented yet)
     *
     * Until it is done, the single operations are available on their own:
     * addColumn(), dropColumn(), modifyColumn(), rename(), alterSettings().
     *
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
        $this->_forgetSchema();

        return $this->_execQuery($request);
    }

    /**
     * Head of every ALTER statement, with a table name that is surely there
     *
     * @return string
     */
    protected function _sqlAlterTable(): string
    {
        $table = $this->_sqlTable();
        if (!$table) {
            throw new \LogicException('ALTER needs a table: call table() first');
        }

        return 'ALTER TABLE ' . $table;
    }

    /**
     * Run a chain of ALTER statements
     *
     * ALTER is the only command of the builder that may need more than one query: the server
     * takes exactly one operation per statement (there is no "ADD COLUMN a, ADD COLUMN b"),
     * so the chain cannot go through parse()/_makeSql()/_execQuery() the way every other
     * command does. There is no transaction behind it either - the steps that ran before a
     * failing one stay applied - therefore the chain stops at the first error, and the queries
     * that really reached the server are reported back in ResultSet::sqlQuery().
     *
     * @param string[] $steps
     * @param string|null $status
     *
     * @return ResultSet
     */
    protected function _execAlter(array $steps, ?string $status = 'altered'): ResultSet
    {
        $this->command = 'ALTER';
        $done = [];
        $error = null;
        $time = microtime(true);
        foreach ($steps as $sql) {
            $done[] = $sql;
            $this->log('info', 'Manticore Query:', ['command' => $this->command, 'query' => $sql]);
            // _execServiceQuery() keeps the promise of the other service commands:
            // a failed query lands in ResultSet::error() instead of being thrown
            $this->_execServiceQuery($sql, $error);
            if ($error !== null) {
                break;
            }
        }
        // the set of columns has changed, the cached DESCRIBE of the table is stale now
        $this->_forgetSchema();

        $result = [
            'command' => $this->command,
            'query' => implode('; ', $done),
            'original' => null,
            'exec_time' => microtime(true) - $time,
            'result' => [
                'type' => 'bool',
                'data' => $error === null,
            ],
        ];
        if ($error !== null) {
            $result['response']['error'] = $error;
        }
        $this->log('debug', 'Manticore Result:', $result);

        return $this->_resultSet($result, $status);
    }

    /**
     * ALTER TABLE <table> ADD COLUMN <name> <type>
     *
     * The type is written the same way as in create():
     *      addColumn('title', 'text indexed stored')
     *      addColumn('time', ['type' => 'timestamp', 'engine' => 'columnar'])
     *      addColumn('article', 'text', 'indexed')
     * Mind that ADD COLUMN takes fewer options than CREATE TABLE - fast_fetch is not one of
     * them and is dropped, see SchemaColumn::alterDefinition().
     *
     * The server fills the new attribute of the existing rows with an empty value of its type,
     * and keeps the table unavailable for queries while the column is being added.
     *
     * @param string $name
     * @param string|array $type
     * @param string|array|null $options
     *
     * @return ResultSet
     */
    public function addColumn(string $name, $type, $options = null): ResultSet
    {
        // a throwaway schema parses "text indexed stored" and ['type' => .., ..] as create() does
        $column = (new SchemaTable())->addColumn($name, $type, $options);

        return $this->_execAlter([$this->_sqlAlterTable() . ' ADD COLUMN ' . $column->alterDefinition()]);
    }

    /**
     * ALTER TABLE <table> DROP COLUMN <name>
     *
     * Several names are dropped one statement after another. A field that is a full-text field
     * and a string attribute at the same time takes two drops of the same name - the first one
     * removes the attribute, the second one the field:
     *      dropColumn(['title', 'title'])
     * The id column cannot be dropped.
     *
     * @param string|array $name
     *
     * @return ResultSet
     */
    public function dropColumn($name): ResultSet
    {
        $steps = [];
        foreach ((array)$name as $column) {
            $steps[] = $this->_sqlAlterTable() . ' DROP COLUMN ' . $column;
        }
        if (!$steps) {
            throw new \InvalidArgumentException('dropColumn() needs a column name');
        }

        return $this->_execAlter($steps);
    }

    /**
     * ALTER TABLE <table> MODIFY COLUMN <name> <type>
     *
     * The server widens an int column to bigint and nothing else: any other change of type is
     * a drop and an add, i.e. a loss of the data of that column, and has to be asked for
     * explicitly. A type it does not accept comes back as ResultSet::error().
     *
     * @param string $name
     * @param string $type
     *
     * @return ResultSet
     */
    public function modifyColumn(string $name, string $type): ResultSet
    {
        return $this->_execAlter([$this->_sqlAlterTable() . ' MODIFY COLUMN ' . $name . ' ' . $type]);
    }

    /**
     * ALTER TABLE <table> RENAME <new_name>
     *
     * The new name goes through the prefix resolution as any other table name, so
     * table('?products')->rename('?goods') renames <prefix>products to <prefix>goods.
     * On success the query keeps working with the same table under its new name.
     *
     * Renaming is served by Manticore Buddy, i.e. it needs a server with Buddy running.
     *
     * @param string $newName
     *
     * @return ResultSet
     */
    public function rename(string $newName): ResultSet
    {
        $result = $this->_execAlter([$this->_sqlAlterTable() . ' RENAME ' . $this->resolveTableName($newName)], 'renamed');
        if ($result->success()) {
            $this->table($newName);
            // the schema was cached under the old name, the new one may be stale as well
            $this->_forgetSchema();
        }

        return $result;
    }

    /**
     * ALTER TABLE <table> <setting>='<value>'[, <setting>='<value>']
     *
     * Changes the full-text settings of a table (charset_table, html_strip, morphology, ...);
     * the columns are not touched. Unlike ADD/DROP COLUMN, the server takes the whole list of
     * settings in one statement.
     *
     * @param array $settings ['setting' => 'value'], a list value is joined with commas
     *
     * @return ResultSet
     */
    public function alterSettings(array $settings): ResultSet
    {
        $parts = [];
        foreach ($settings as $name => $value) {
            if (is_int($name)) {
                throw new \InvalidArgumentException('alterSettings() needs a "setting => value" map');
            }
            if (is_array($value)) {
                $value = implode(',', $value);
            }
            $parts[] = $name . '=\'' . self::escapeParam($value) . '\'';
        }
        if (!$parts) {
            throw new \InvalidArgumentException('alterSettings() needs at least one setting');
        }

        return $this->_execAlter([$this->_sqlAlterTable() . ' ' . implode(', ', $parts)]);
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
        if (!empty($reconfigure)) {
            // WITH RECONFIGURE re-reads the settings, the column set can differ afterwards
            $this->_forgetSchema();
        }

        return $this->_execQuery($request, 'truncated');
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
        $this->_forgetSchema();

        return $this->_execQuery($request, 'dropped');
    }

    /**
     * @return ResultSet
     */
    public function dropIfExists(): ResultSet
    {
        return $this->drop(true);
    }

    /**
     * SHOW TABLES [ LIKE pattern ]
     *
     * @param string|null $pattern
     *
     * @return ResultSet
     */
    public function showTables(?string $pattern = null): ResultSet
    {
        $this->command = 'SHOW TABLES';
        $sql = 'SHOW TABLES';
        if (!$pattern && $this->prefix) {
            $pattern = '?%';
        }
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

        return $this->_execQuery($request);
    }

    /**
     * SHOW TABLE $tableName STATUS
     *
     * @param string|null $tableName the table to ask about, the one of table() by default
     *
     * @return ResultSet
     */
    public function status(?string $tableName = null): ResultSet
    {
        if ($tableName !== null) {
            $this->table($tableName);
        }
        $this->command = 'STATUS';
        $sql = 'SHOW TABLE ' . $this->_sqlTable() . ' STATUS';
        $request = [
            'command' => $this->command,
            'query' => $sql,
            'original' => null,
        ];

        return $this->_execQuery($request);
    }

    /**
     * SHOW TABLE $tableName SETTINGS
     *
     * @param string|null $tableName the table to ask about, the one of table() by default
     *
     * @return ResultSet
     */
    public function settings(?string $tableName = null): ResultSet
    {
        if ($tableName !== null) {
            $this->table($tableName);
        }
        $this->command = 'SETTINGS';
        $sql = 'SHOW TABLE ' . $this->_sqlTable() . ' SETTINGS';
        $request = [
            'command' => $this->command,
            'query' => $sql,
            'original' => null,
        ];

        return $this->_execQuery($request);
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

        $resultSet = $this->_execQuery($request);
        if (!is_array($resultSet->result())) {
            // an empty answer is packed as the boolean "true" by _execQuery(), but callers of
            // a listing expect a list - Connection::showVariables() is even typed ": array"
            $resultSet->setResult('collection', []);
        }

        return $resultSet;
    }

    /**
     * Runs a service statement that does not go through _execQuery().
     *
     * Errors must not escape as exceptions here either: the whole builder reports a failed
     * query through ResultSet::error(). Note that columnTypes() calls describe() before any
     * INSERT/UPDATE/REPLACE is even built, so without this an unknown table would throw
     * instead of returning an unsuccessful ResultSet.
     *
     * @param string $sql
     * @param string|null $error filled with the error message, if any
     *
     * @return array raw client response
     */
    protected function _execServiceQuery(string $sql, ?string &$error = null): array
    {
        $error = null;
        try {
            return $this->client->query($sql) ?: [];
        }
        catch (\Throwable $e) {
            $error = $e->getMessage();
            $this->log('error', $e->getMessage(), ['query' => $sql]);

            return [];
        }
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
        $response = $this->_execServiceQuery($sql, $error);
        $result = [
            'command' => 'DESCRIBE',
            'query' => $sql,
            'original' => null,
            'result' => [
                'type' => 'array',
                'data' => $response['data'] ?? [],
            ]
        ];
        if ($error !== null) {
            $result['response']['error'] = $error;
        }

        return $this->_resultSet($result);
    }

    public function showCreate(): ResultSet
    {
        $sql = 'SHOW CREATE TABLE ' . $this->_sqlTable();
        $response = $this->_execServiceQuery($sql, $error);
        $result = [
            'command' => 'SHOW CREATE TABLE',
            'query' => $sql,
            'original' => null,
            'result' => [
                'type' => 'array',
                'data' => $response['data'][0] ?? [],
            ]
        ];
        if ($error !== null) {
            $result['response']['error'] = $error;
        }

        return $this->_resultSet($result);
    }

    /**
     * Share the schema cache of the connection.
     *
     * DESCRIBE is asked for before every INSERT/UPDATE/REPLACE and after every SELECT that
     * returns rows, while Connection::query() builds a fresh Query for each call - so a cache
     * living in this object alone would never survive a single request. The pool is taken by
     * reference, which lets every Query of one connection fill and read the same one.
     *
     * @param array $pool
     *
     * @return $this
     */
    public function setSchemaPool(array &$pool): Query
    {
        $this->indexPool = &$pool;

        return $this;
    }

    /**
     * Share the "last result" slot of the connection.
     *
     * The Laravel-shaped write methods - insert(), update(), delete() - answer with a scalar,
     * so the ResultSet they build has nowhere to go; and since Connection::query() makes a fresh
     * Query per call, the caller of ManticoreDb::table(..)->insert(..) never gets to hold that
     * Query either. The slot is taken by reference, exactly like setSchemaPool() does, so that
     * Connection::lastResultSet() can hand the ResultSet back afterwards.
     *
     * @param array $slot
     *
     * @return $this
     */
    public function setResultSlot(array &$slot): Query
    {
        $this->resultSlot = &$slot;

        return $this;
    }

    /**
     * The single place where a ResultSet is born, so that the slot above never goes stale.
     *
     * @param array $result
     * @param string|null $status
     *
     * @return ResultSet
     */
    protected function _resultSet(array $result, ?string $status = null): ResultSet
    {
        $resultSet = new ResultSet($result, $status);
        $this->resultSlot['last'] = $resultSet;

        return $resultSet;
    }

    /**
     * The ResultSet of the last statement this query has run
     *
     * @return ResultSet|null
     */
    public function lastResultSet(): ?ResultSet
    {
        return $this->resultSlot['last'] ?? null;
    }

    /**
     * Drop the cached schema of a table, after a statement that may have changed it.
     *
     * @param string|null $tableName the table of table() by default
     *
     * @return void
     */
    protected function _forgetSchema(?string $tableName = null): void
    {
        unset($this->indexPool[$tableName ?? $this->_sqlTable()]);
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
        $this->_execServiceQuery($sql, $error);
        $result = [
            'command' => $this->command,
            'query' => $sql,
            'original' => null,
            'result' => [
                'type' => 'bool',
                'data' => $error === null,
            ]
        ];
        if ($error !== null) {
            $result['response']['error'] = $error;
        }

        return $this->_resultSet($result);
    }

    /**
     * @param string|array|null $columns
     * @param string|array ...$more
     *
     * @return ResultSet
     */
    public function search($columns = '*', ...$more): ResultSet
    {
        if (func_num_args()) {
            $this->select($columns, ...$more);
        }
        else {
            $this->selectColumns(null);
        }

        return $this->exec();
    }


    /**
     * @param string|array|null $columns
     * @param string|array ...$more
     *
     * @return mixed|null
     */
    public function get($columns = '*', ...$more)
    {
        if (func_num_args()) {
            $this->select($columns, ...$more);
        }
        else {
            $this->selectColumns(null);
        }

        return $this->exec()->result();
    }

    /**
     * @return int
     */
    public function count(): int
    {
        $this->selectColumns('COUNT(*) as _count');
        $result = $this->exec()->result();
        if ($result && ($arr = reset($result))) {
            return $arr['_count'] ?? 0;
        }

        return 0;
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
     * @param string $name
     *
     * @return array
     */
    public function pluck(string $name): array
    {
        $result = $this->get();

        if ($result) {
            return array_combine(array_keys($result), array_column($result, $name));
        }

        return [];
    }

    /**
     * INSERT, answering with the full ResultSet
     *
     * @param array $data
     * @param int|null $id
     *
     * @return ResultSet
     */
    public function insertResultSet(array $data, ?int $id = 0): ResultSet
    {
        $this->command = 'INSERT';
        $this->update = $data;
        if ($id && !self::isMultiRow($data)) {
            $this->update['id'] = $id;
        }

        $request = $this->parse();

        return $this->_execQuery($request, 'inserted');
    }

    /**
     * INSERT, answering with a success flag the way Laravel does
     *
     * Unlike Laravel, this library never throws on a failed statement, so false is all that is
     * left of the error here - the text of it is in insertResultSet() or lastResultSet().
     *
     * @param array $data
     * @param int|null $id
     *
     * @return bool
     */
    public function insert(array $data, ?int $id = 0): bool
    {
        return $this->insertResultSet($data, $id)->success();
    }

    /**
     * INSERT, answering with the id of the inserted row the way Laravel does
     *
     * A set of rows makes the server answer with a list of ids, and this returns the first of
     * them, as LAST_INSERT_ID() of MySQL would; the whole list is in insertResultSet().
     *
     * @param array $data
     * @param int|null $id
     *
     * @return int|null null when the statement failed
     */
    public function insertGetId(array $data, ?int $id = 0): ?int
    {
        return self::firstId($this->insertResultSet($data, $id));
    }

    /**
     * The id of a written row out of the answer to an INSERT or a REPLACE.
     *
     * A set of rows makes LAST_INSERT_ID() answer with a list, and the first of it is taken,
     * as MySQL would have it.
     *
     * @param ResultSet $resultSet
     *
     * @return int|null null when the statement failed
     */
    private static function firstId(ResultSet $resultSet): ?int
    {
        if (!$resultSet->success()) {
            return null;
        }
        $result = $resultSet->result();

        return (int)(is_array($result) ? reset($result) : $result);
    }

    /**
     * UPDATE, answering with the full ResultSet
     *
     * @param array $data
     * @param int|null $id
     *
     * @return ResultSet
     */
    public function updateResultSet(array $data, ?int $id = 0): ResultSet
    {
        $this->command = 'UPDATE';
        $this->update = $data;
        if ($id) {
            $this->where('id', $id);
        }

        $request = $this->parse();

        return $this->_execQuery($request, 'updated');
    }

    /**
     * UPDATE, answering with the number of updated rows the way Laravel does
     *
     * A failed statement gives 0 as well, and 0 alone does not tell "nothing matched" from
     * "the statement did not run" - the reason is in updateResultSet() or lastResultSet().
     *
     * @param array $data
     * @param int|null $id
     *
     * @return int
     */
    public function update(array $data, ?int $id = 0): int
    {
        return (int)$this->updateResultSet($data, $id)->result();
    }

    /**
     * REPLACE, answering with the full ResultSet
     *
     * @param array $data
     * @param int|null $id
     *
     * @return ResultSet
     */
    public function replaceResultSet(array $data, ?int $id = 0): ResultSet
    {
        $this->command = 'REPLACE';

        $this->update = $data;
        if ($id && !self::isMultiRow($data)) {
            $this->update['id'] = $id;
        }

        $request = $this->parse();

        return $this->_execQuery($request, 'replaced');
    }

    /**
     * REPLACE, answering with a success flag
     *
     * The server has no REPLACE of its own in the Laravel query builder to be compatible with -
     * this only keeps the shape of the insert() family, see insertResultSet() for why.
     *
     * @param array $data
     * @param int|null $id
     *
     * @return bool
     */
    public function replace(array $data, ?int $id = 0): bool
    {
        return $this->replaceResultSet($data, $id)->success();
    }

    /**
     * REPLACE, answering with the id of the written row
     *
     * @param array $data
     * @param int|null $id
     *
     * @return int|null null when the statement failed
     */
    public function replaceGetId(array $data, ?int $id = 0): ?int
    {
        return self::firstId($this->replaceResultSet($data, $id));
    }

}
