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
    public function __construct(string $bool, $operand, ?string $op = null, $arg = null, ?int $level = 0)
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
     * @param int|null $argsCount how many arguments the caller actually passed to where()
     *
     * @return QueryCondition|QueryConditionSet
     */
    public static function create($bool, $field, $arg1 = null, $arg2 = null, ?int $level = 0, ?int $argsCount = null)
    {
        if ($argsCount === null) {
            // called without the count (directly, not through where()): guess it the old way,
            // which cannot tell where($field, $op, null) from where($field, $op)
            $argsCount = ($arg1 === null) ? 1 : (($arg2 === null) ? 2 : 3);
        }

        if (is_callable($field)) {
            $condition = new QueryConditionSet($bool, $level);
            $field($condition);
            // where($closure, 'NOT') is how whereNot() asks for the whole group to be negated
            if ($argsCount >= 2 && is_string($arg1) && strtoupper(trim($arg1)) === 'NOT') {
                $condition->negate();
            }

            return $condition;
        }

        if (is_array($field)) {
            // where(['color' => 'red', 'price' => 10]) and where([['price', '>', 10], ['color', 'red']])
            $condition = new QueryConditionSet($bool, $level);
            foreach ($field as $key => $value) {
                if (is_array($value)) {
                    $condition->where(...array_values($value));
                }
                else {
                    $condition->where($key, $value);
                }
            }

            return $condition;
        }

        if ($argsCount < 2) {
            // a raw expression: where('color_filter=1')
            return new self($bool, $field, null, null);
        }

        if ($argsCount === 2) {
            // where($field, $value) is the shortcut for where($field, '=', $value)
            $op = '=';
            $value = $arg1;
        }
        else {
            $op = strtoupper(trim((string)$arg1));
            $value = $arg2;
        }

        if ($op === 'IS NULL' || $op === 'IS NOT NULL') {
            // the unary operators, passed with the Query::NO_ARG marker by whereNull() & co
            return new self($bool, $field, $op);
        }

        if ($value === null) {
            // comparing against null is how IS NULL is asked for in the three-argument form;
            // the two-argument where($field, 'IS NULL') still compares against the string
            if ($op === '=') {
                return new self($bool, $field, 'IS NULL');
            }
            if ($op === '!=' || $op === '<>') {
                return new self($bool, $field, 'IS NOT NULL');
            }

            throw new \InvalidArgumentException('NULL cannot be compared with the operator "' . $arg1 . '"');
        }

        if (is_array($value)) {
            $arg = array_map([self::class, '_escape_string'], $value);
        }
        else {
            $arg = self::_escape_string($value);
        }

        if ($op === 'IN' || $op === 'NOT IN') {
            // a raw expression brings its own brackets, e.g. IN(1,2) written by hand
            if ($value instanceof Expression) {
                return new self($bool, $field, $op, (string)$value);
            }

            return new self($bool, $field, $op, '(' . implode(',', (array)$arg) . ')');
        }
        if ($op === 'BETWEEN' || $op === 'NOT BETWEEN') {
            $arg = array_values((array)$arg);

            return new self($bool, $field, $op, ($arg[0] ?? '') . ' AND ' . ($arg[1] ?? ''));
        }

        return new self($bool, $field, $op, $arg);
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
