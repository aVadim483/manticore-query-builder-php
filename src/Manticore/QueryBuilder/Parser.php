<?php

declare(strict_types=1);

namespace avadim\Manticore\QueryBuilder;

class Parser
{
    private string $sql = '';
    private ?string $prefix = '';


    /**
     * Constructor
     */
    public function __construct(?string $prefix = null)
    {
        $this->prefix = $prefix;
    }

    /**
     * Get SQL string
     *
     * @return string
     */
    public function getSql(): string
    {
        return $this->sql;
    }

    /**
     * @param string $sql
     *
     * @return array
     */
    public function parse(string $sql): array
    {
        $this->sql = self::trim($sql, ';');
        $result = $this->parseSql($this->sql);
        $result['original'] = $sql;

        return $result;
    }

    /**
     * @param $query
     *
     * @return array
     */
    protected function parseSql($query): array
    {
        $command = $this->getCommand($query);
        $result = [
            'command' => $command,
            'query' => '',
        ];

        switch ($command) {
            case 'SELECT':
                $parts = [];
                $mp = [];
                if (preg_match('#^(SELECT)\s+(.+)\s+(FROM)\s+(.+)$#siU', $query, $m)) {
                    $result['query'] = $command . ' ' . $this->_formatFields($m[2]) . ' FROM';

                    [$exp1, $exp2] = $this->_extractExpression($m[4]);
                    if ($exp2 !== null) {
                        // has sub query
                        $sub = $this->parseSql(substr($exp1, 1, -1));
                        $result['query'] .= ' ' . $exp1[0] . $sub['query'] . $exp1[-1];
                        preg_match('#^((WHERE)\s+(?P<where>.+))?(\s+(GROUP\s+BY)\s+(?P<group>.+))?(\s+(ORDER\s+BY)\s+(?P<order>.+))?(\s+(LIMIT)\s+(?P<limit>.+))?(\s+(OPTION)\s+(?P<option>.+))?$#siU', $exp2, $mp);
                    }
                    else {
                        if (preg_match('#^(?P<tables>[\w\.]+)(\s+(WHERE)\s+(?P<where>.+))?(\s+(GROUP\s+BY)\s+(?P<group>.+))?(\s+(ORDER\s+ BY)\s+(?P<order>.+))?(\s+(LIMIT)\s+(?P<limit>.+))?(\s+(OPTION)\s+(?P<option>.+))?$#siU', $exp1, $mp)) {
                            $result['index'] = $this->_formatTables($mp['tables']);
                            $parts[] = $result['index'];
                        }
                    }
                }
                elseif (preg_match('#^(SELECT)\s+(.+)$#siU', $query, $m)) {
                    $result['query'] = $command . ' ' . $this->_formatFields($m[2]);
                }
                else {
                    // unknown
                }
                if (!empty($mp['where'])) {
                    $parts[] ='WHERE';
                    $parts[] = $this->_formatWhereParams($mp['where']);
                }
                if (!empty($mp['group'])) {
                    $parts[] ='GROUP BY';
                    $parts[] = $this->_formatGroupParams($mp['group']);
                }
                if (!empty($mp['order'])) {
                    $parts[] ='ORDER BY';
                    $parts[] = $this->_formatOrderParams($mp['order']);
                }
                if (!empty($mp['limit'])) {
                    $parts[] ='LIMIT';
                    $parts[] = $this->_formatLimitParams($mp['limit']);
                }
                if (!empty($mp['option'])) {
                    $parts[] ='OPTION';
                    $parts[] = $this->_formatOptionParams($mp['option']);
                }
                $result['query'] .= ' ' . implode(' ', $parts);

                break;

            case 'INSERT':
                if (preg_match('#^INSERT\s+INTO\s+(?P<tables>[\w.?]+(\s+(AS\s+)?[\w.]+)?\s*)\((?P<fields>.+)\)\s+VALUES([\s\(]+)(?P<values>.+)$#si', $query, $m)) {
                    $result['index'] = $this->_formatTables($m['tables']);
                    $result['query'] = 'INSERT INTO ' . $result['index'] . '(' . $this->_formatFields($m['fields']) . ') VALUES (' . trim($m['values']);
                }
                break;

            case 'UPDATE':
            case 'REPLACE':
                if (preg_match('#^(UPDATE|REPLACE)\s+(?P<tables>[\w.?]+(\s+(AS\s+)?[\w.]+)?\s*)SET(?P<set>[\S\s]*)(\s+WHERE\s+(?P<where>.+))?$#siU', $query, $m)) {
                    $result['query'] = $command . ' ' . $this->_formatTables($m['tables']) . ' SET ' . $this->_formatFieldsSet($m['set']);
                    if (!empty($m['where'])) {
                        $result['query'] .= ' WHERE ' . $this->_formatWhereParams($m['where']);
                    }
                }
                break;

            case 'DELETE':
                if (preg_match('#^DELETE\s+FROM\s+(?P<table>[\w.?]+)(\s+WHERE\s+(?P<where>.+))?$#siU', $query, $m)) {
                    $result['query'] = $command . ' FROM ' . $this->_tableName($m['table']);
                    if (!empty($m['where'])) {
                        $result['query'] .= ' WHERE ' . $this->_formatWhereParams($m['where']);
                    }
                }
                break;

            case 'CREATE TABLE':
                if (preg_match('#^CREATE\s+TABLE\s+(?P<if>IF NOT EXISTS\s+)?(?P<table>[\w.?]+)\s+\((?P<fields>.+)\)(?P<options>.*)$#siU', $query, $m)) {
                    $result['query'] = $command;
                    if (!empty($m['if'])) {
                        $result['query'] .= ' IF NOT EXISTS';
                    }
                    $result['query'] .= ' ' . $this->_tableName($m['table']) . '(' . trim($m['fields']) . ')';
                    if (!empty($m['options'])) {
                        $result['query'] .= ' ' . trim($m['options']);
                    }
                }
                elseif (preg_match('#^CREATE\s+TABLE\s+(?P<if>IF NOT EXISTS\s+)?(?P<table>[\w.?]+)\s+(?P<options>.*)$#siU', $query, $m)) {
                    $result['query'] = $command;
                    if (!empty($m['if'])) {
                        $result['query'] .= ' IF NOT EXISTS';
                    }
                    $result['query'] .= ' ' . $this->_tableName($m['table']) . ' ' . trim($m['options']);
                }
                break;

            case 'SHOW TABLES':
            case 'SHOW INDEXES':
                $result['query'] = 'SHOW TABLES';
                if (preg_match('#^SHOW\s+TABLES\s+LIKE\s+(.+)$#si', $query, $m)) {
                    $pattern = self::trim($this->_tableName($m[1]), '"\'');
                    if ($pattern) {
                        $result['query'] .= ' LIKE \'' . $pattern . '\'';
                    }
                }

                break;
        }

        return $result;
    }

    /**
     * @param string $sql
     *
     * @return string
     */
    protected function getCommand(string $sql): string
    {
        $commands = [
            'SELECT', 'INSERT', 'UPDATE', 'DELETE',
            'RENAME', 'ALTER', 'TRUNCATE',
            'CREATE\s+INDEX', 'CREATE\s+TABLE',
            'SET', 'DROP',
            'EXPLAIN', 'DESCRIBE',
            'SHOW\s+TABLES', 'SHOW\s+INDEXES',
            'SHOW\s+META', 'SHOW\s+AGENT\s+STATUS', 'SHOW\s+COLLATION', 'SHOW\s+VARIABLES', 'SHOW\s+CHARACTER\s+SET',
        ];

        foreach ($commands as $pattern) {
            if (preg_match('#^(' . $pattern . ')(\s+.+)?$#si', $sql, $m)) {

                return str_replace('\s+', ' ', $pattern);
            }
        }

        return '';
    }

    /**
     * @param string $str
     *
     * @return array
     */
    protected function _extractExpression(string $str): array
    {
        $str = trim($str);
        if ($str) {
            $expression = $start = $end = null;
            if ($str[0] === '(') {
                $expression = $start = '(';
                $end = ')';
            }
            elseif ($str[0] === '[') {
                $expression = $start = '[';
                $end = ']';
            }
            elseif ($str[0] === '"') {
                $expression = $end = '"';
            }
            elseif ($str[0] === "'") {
                $expression = $end = "'";
            }

            if ($end) {
                $level = 1;
                $len = mb_strlen($str);
                for ($pos = 1; $pos < $len; $pos++) {
                    $char = $str[$pos];
                    $expression .= $char;
                    if ($char === $end) {
                        $level--;
                    }
                    elseif ($start && $char === $start) {
                        $level++;
                    }

                    if ($level <= 0) {
                        break;
                    }
                }

                return [$expression, trim(mb_substr($str, $pos + 1))];
            }
        }

        return [$str, null];
    }

    /**
     * @param string $name
     *
     * @return string
     */
    protected function _tableName(string $name): string
    {
        return self::resolveIndexName($name, $this->prefix);
    }

    /**
     * @param string $name
     *
     * @return string
     */
    protected function _fieldName(string $name): string
    {
        if (strpos($name, '.')) {
            [$table, $field] = explode('.', $name);
            $name = $this->_tableName($table) . '.' . trim($field);
        }
        else {
            $name = trim($name);
        }

        return $name;
    }

    /**
     * @param string $str
     *
     * @return string
     */
    protected function _formatFields(string $str): string
    {
        $params = array_map('trim', explode(',', $str));
        foreach ($params as $n => $param) {
            if (preg_match('#^(.+)\s+as\s+([\w\.]+)$#', $param, $m)) {
                $params[$n] = $this->_fieldName($m[1]) . ' AS ' . trim($m[2]);
            }
        }

        return implode(', ', $params);
    }

    /**
     * @param string $str
     *
     * @return string
     */
    protected function _formatFieldsSet(string $str): string
    {
        $params = [];
        while ($str) {
            $str = self::trim($str, ',');
            if (!$str) {
                break;
            }
            if (preg_match('#^(?P<field>[\w\.\?]+)\s*=\s*(?P<val>.+)$#sU', $str, $m)) {
                $field = $this->_fieldName($m['field']);
                [$val, $str] = $this->_extractExpression($m['val']);
                if ($str === null) {
                    if ($n = strpos($m['val'], ',')) {
                        $val = substr($m['val'], 0, $n);
                        $str = substr($m['val'], $n + 1);
                    }
                }
                $params[] = $field . '=' . $val;
            }
            else {
                $params[] = $str;
                break;
            }
        }

        return implode(', ', $params);
    }

    /**
     * @param string $str
     *
     * @return string
     */
    protected function _formatTables(string $str): string
    {
        $params = explode(',', $str);
        foreach ($params as $n => $param) {
            $params[$n] = $this->_tableName($param);
        }

        return implode(', ', $params);
    }

    /**
     * @param string $str
     *
     * @return string
     */
    protected function _formatWhereParams(string $str): string
    {
        return trim($str);
    }

    /**
     * @param string $str
     *
     * @return string
     */
    protected function _formatGroupParams(string $str): string
    {
        return trim($str);
    }

    /**
     * @param string $str
     *
     * @return string
     */
    protected function _formatOrderParams(string $str): string
    {
        $params = array_map('trim', explode(',', $str));
        foreach ($params as $n => $param) {
            if (strpos($param, ' ')) {
                [$field, $direct] = explode(' ', $param);
                $direct = strtoupper($direct);
                if ($direct === 'ASC' || $direct === 'DESC') {
                    $params[$n] = $this->_fieldName($field) . ' ' . $direct;
                }
            }
        }

        return implode(', ', $params);
    }

    /**
     * @param string $str
     *
     * @return string
     */
    protected function _formatLimitParams(string $str): string
    {
        return trim($str);
    }

    /**
     * @param string $str
     *
     * @return string
     */
    protected function _formatOptionParams(string $str): string
    {
        return trim($str);
    }

    /**
     * @param string $name
     * @param string|null $prefix
     * @param bool|null $forcePrefix
     *
     * @return string
     */
    public static function resolveIndexName(string $name, ?string $prefix = '', ?bool $forcePrefix = false): string
    {
        $name = trim($name);
        if (strpos($name, '?') !== false) {
            if ($name[0] === '?') {
                $name = $prefix . substr($name, 1);
            }
            elseif ($name[0] === $name[-1] && substr($name, 0, 2) === '`?') {
                $name = $name[0] . $prefix . substr($name, 2, -1) . $name[0];
            }
        }
        elseif ($prefix && $forcePrefix) {
            if ($name[0] === '`' && $name[0] === $name[-1] && substr($name, 0, 2) === '`?') {
                $name = $name[0] . $prefix . substr($name, 1, -1) . $name[0];
            }
            else {
                $name = $prefix . $name;
            }
        }

        return $name;
    }

    /**
     * @param string $str
     * @param string|null $chars
     *
     * @return string
     */
    public static function trim(string $str, ?string $chars = null): string
    {
        return trim($str, " \n\r\t\v\x00" . $chars);
    }

    /**
     * Split a string into substrings including quotes
     *
     * @param string $separator
     * @param string $expression
     * @param bool|null $trim
     *
     * @return array
     */
    public static function explode(string $separator, string $expression, ?bool $trim = false): array
    {
        $result = [];
        $expression = trim($expression);
        $level = 0;
        $stack = [];
        $chunk = '';
        $quote = null;
        for ($pos = 0; $pos < mb_strlen($expression); $pos++) {
            if ($quote || ($pos > 0 && $expression[$pos - 1] === '\\')) {
                if ($expression[$pos] === $quote) {
                    // end of quoted string
                    $quote = null;
                }
                $chunk .= $expression[$pos];
                continue;
            }

            if ($level === 0 && $expression[$pos] === $separator) {
                $result[] = $chunk;
                $chunk = '';
                continue;
            }

            if (in_array($expression[$pos], ['"', "'"])) {
                // start of quoted string
                $chunk .= $expression[$pos];
                $quote = $expression[$pos];
                continue;
            }

            if (in_array($expression[$pos], ['(', '[', '{'])) {
                $stack[++$level] = $expression[$pos];
            }
            elseif (($expression[$pos] === ')' && $stack[$level] === '(')
                || ($expression[$pos] === ']' && $stack[$level] === '[')
                || ($expression[$pos] === '}' && $stack[$level] === '{')) {
                unset($stack[$level--]);
            }
            elseif (in_array($expression[$pos], [')', ']', '}'])) {
                $stack[++$level] = $expression[$pos];
            }
            $chunk .= $expression[$pos];
        }
        if ($chunk) {
            $result[] = $chunk;
        }

        if ($result && $trim) {
            $result = array_map('trim', $result);
        }

        return $result;
    }

    /**
     * @param array $arr
     * @param string|null $type
     *
     * @return string
     */
    public static function formatArray(array $arr, ?string $type = null): string
    {
        if ($type === 'json') {
            return self::formatScalar(json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), 'str');
        }

        $result = '';
        foreach ($arr as $value) {
            if ($result) {
                $result .= ',';
            }
            $result .= self::formatValue($value, $type);
        }

        return '(' . $result . ')';
    }

    /**
     * @param $value
     * @param string|null $type
     *
     * @return string
     */
    public static function formatScalar($value, ?string $type = null): string
    {
        if ($type === null) {
            if ($value === null) {
                return 'NULL';
            }
            $type = gettype($value);
        }
        switch ($type) {
            case 'string':
            case 'str':
            case 'text':
                return '\'' . addslashes($value) . '\'';
            case 'boolean':
            case 'bool':
                return $value ? '1' : '0';
            case 'bigint':
            case 'integer':
            case 'int':
            case 'timestamp':
                return (string)((int)$value);
            case 'float':
            case 'double':
                $value = (float)$value;
                return str_replace(',', '.', (string)$value);
            case 'multi':
            case 'multi64':
                return self::formatArray($value, 'int');
            case 'NULL':
                return 'NULL';
        }

        return (string)$value;
    }

    /**
     * @param $value
     * @param string|null $type
     *
     * @return string
     */
    public static function formatValue($value, ?string $type = null): string
    {
        if (is_array($value)) {
            return self::formatArray($value, $type);
        }

        return self::formatScalar($value, $type);
    }

}