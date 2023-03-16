<?php

namespace avadim\Manticore\QueryBuilder;

class QueryCondition
{
    private string $bool;
    private $operand;
    private ?string $operator = null;
    private $arg = null;
    private array $params = [];
    private int $level = 0;

    /**
     * @param string $bool
     * @param mixed $operand
     * @param string|null $op
     * @param string|array|mixed|null $arg
     * @param int|null $level
     */
    public function __construct(string $bool, $operand, string $op = null, $arg = null, ?int $level = 0)
    {
        $this->level = $level;
        $this->bool = $bool;
        if (is_scalar($operand)) {
            $this->operand = $operand;
        }
        $this->operator = $op;
        $this->arg = $arg;
    }

    public static function _escape_string($val)
    {
        return Query::quoteParam($val);
    }

    /**
     * @param $bool
     * @param $field
     * @param mixed|null $arg1
     * @param mixed|null $arg2
     * @param int|null $level
     *
     * @return QueryCondition|QueryConditionSet
     */
    public static function create($bool, $field, $arg1 = null, $arg2 = null, ?int $level = 0)
    {
        if (is_callable($field)) {
            $condition = new QueryConditionSet($bool, $level);
            $field($condition);

            return $condition;
        }
        if ($arg1 !== null) {
            if ($arg2 === null) {
                $arg2 = $arg1;
                $arg1 = '=';
            }
            $op = strtoupper($arg1);
            if (is_array($arg2)) {
                $arg = array_map([self::class, '_escape_string'], $arg2);
            }
            else {
                $arg = self::_escape_string($arg2);
            }

            if ($op === 'IN') {
                $condition = new self($bool, $field, 'IN', '(' . implode(',', (array)$arg) . ')');
            }
            elseif ($op === 'NOT IN') {
                $condition = new self($bool, $field, 'NOT IN', '(' . implode(',', (array)$arg) . ')');
            }
            elseif ($op === 'BETWEEN') {
                $condition = new self($bool, $field, 'BETWEEN', $arg[0] . ' AND ' . $arg[1]);
            }
            elseif ($op === 'NOT BETWEEN') {
                $condition = new self($bool, $field, 'NOT BETWEEN', $arg[0] . ' AND ' . $arg[1]);
            }
            elseif ($op === 'IS NULL') {
                $condition = new self($bool, $field, 'IS NULL');
            }
            elseif ($op === 'IS NOT NULL') {
                $condition = new self($bool, $field, 'IS NOT NULL');
            }
            else {
                $condition = new self($bool, $field, $op, $arg);
            }
        }
        else {
            $condition = new self($bool, $field, null, null);
        }

        return $condition;
    }

    /**
     * @param array $bind
     *
     * @return $this
     */
    public function bind(array $bind): QueryCondition
    {
        foreach ($bind as $name => $value) {
            if (preg_match('/^:\w+$/', $name)) {
                $this->params[$name] = addslashes($value);
            }
        }

        return $this;
    }

    public function asString($needBool = false): string
    {
        if (is_array($this->operand)) {
            $field = '';
            foreach ($this->operand as $n => $condition) {
                $field .= $condition->asString($n);
            }
            $field = '(' . $field . ')';
        }
        else {
            $field = str_replace(array_keys($this->params), array_values($this->params), $this->operand);
        }
        if ($this->arg !== null) {
            $arg = str_replace(array_keys($this->params), array_values($this->params), $this->arg);
        }
        else {
            $arg = '';
        }
        if (!$this->operator) {
            $result = $field;
        }
        else {
            $operator = $this->operator;
            if ($this->operator[0] >= 'A') {
                $operator = ' ' . $operator;
                if ($arg !== '') {
                    $operator .= ' ';
                }
            }
            $result = $field . $operator . $arg;
        }
/*
        elseif ($this->operator === 'IS NULL') {
            $result = $field . ' IS NULL';
        }
        else {
            if ($this->operator === 'IN' || $this->operator === 'BETWEEN') {
                $operator = ' ' . $this->operator . ' ';
            }
            else {
                $operator = $this->operator;
            }
            $result = $field . $operator . $arg;
        }
*/
        if ($needBool) {
            $result = $this->bool . ' (' . $result . ')';
        }
        else {
            $result = ' (' . $result . ')';
        }

        return $result;
    }

    public function __toString()
    {
        return $this->asString();
    }

}
