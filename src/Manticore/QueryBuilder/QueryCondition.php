<?php

namespace avadim\Manticore\QueryBuilder;

class QueryCondition
{
    private string $bool;
    private $operand;
    private ?string $operator = null;
    private $arg = null;
    private array $params = [];

    /**
     * @param string $bool
     * @param mixed $operand
     * @param string|null $op
     * @param string|array|mixed|null $arg
     */
    public function __construct(string $bool, $operand, string $op = null, $arg = null)
    {
        $this->bool = $bool;
        if (is_scalar($operand)) {
            $this->operand = $operand;
        }
        $this->operator = $op;
        $this->arg = $arg;
    }

    public static function _escape_string($val): string
    {
        if (is_numeric($val)) {
            return (string)$val;
        }
        return "'" . addslashes($val) . "'";
    }

    /**
     * @param $bool
     * @param $field
     * @param $arg1
     * @param null $arg2
     *
     * @return QueryCondition|QueryConditionSet
     */
    public static function create($bool, $field, $arg1, $arg2 = null)
    {
        if (is_callable($field)) {
            $condition = new QueryConditionSet();
            $field($condition);

            return $condition;
        }
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
        elseif ($op === 'BETWEEN') {
            $condition = new self($bool, $field, 'BETWEEN', $arg[0] . ' AND ' . $arg[1]);
        }
        elseif ($op === 'IS NULL') {
            $condition = new self($bool, $field, 'IS NULL');
        }
        else {
            $condition = new self($bool, $field, $op, $arg);
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

    public function asString($needBool = false)
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
