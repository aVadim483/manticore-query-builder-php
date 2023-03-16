<?php

namespace avadim\Manticore\QueryBuilder;

class QueryConditionSet
{
    private ?string $bool = '';
    private array $operands = [];
    private array $params = [];
    private int $level = 0;


    public function __construct(?string $bool = null, ?int $level = 0)
    {
        $this->level = $level;
        $this->bool = $bool;
    }


    protected function _add(string $bool, $field, $arg1 = null, $arg2 = null)
    {
        if (!$this->operands) {
            //$bool = '';
        }
        if (!$this->bool) {
            $this->bool = $bool;
        }
        $this->operands[] = QueryCondition::create($bool, $field, $arg1, $arg2, $this->level + 1);
    }

    /**
     * Usage:
     *      where('field', '>', 123)
     *      where('field', 123) - equal to where('field', '=', 123)
     *      where(function ($condition) { $condition->where(...); })
     *
     * @param mixed $field
     * @param mixed|null $arg1
     * @param mixed|null $arg2
     *
     * @return $this
     */
    public function where($field, $arg1 = null, $arg2 = null)
    {
        $this->_add('AND', $field, $arg1, $arg2);

        return $this;
    }


    public function andWhere($field, $arg1 = null, $arg2 = null, $level = 0)
    {
        $this->_add('AND', $field, $arg1, $arg2);

        return $this;
    }


    public function orWhere($field, $arg1 = null, $arg2 = null)
    {
        $this->_add('OR', $field, $arg1, $arg2);

        return $this;
    }

    /**
     * @param array $bind
     *
     * @return $this
     */
    public function bind(array $bind)
    {
        foreach ($bind as $name => $value) {
            if (preg_match('/^:\w+$/', $name)) {
                $this->params[$name] = addslashes($value);
            }
        }

        return $this;
    }

    /**
     * @param bool|null $needBool
     *
     * @return string
     */
    public function asString(?bool $needBool = false): string
    {
        $result = '';
        $strings = [];
        /** @var QueryCondition $condition */
        foreach ($this->operands as $n => $condition) {
            $condition->bind($this->params);
            $strings[] = $condition->asString($n);
        }
        if ($strings) {
            if (count($strings) === 1) {
                $result = reset($strings);
            }
            else {
                $result = implode($strings);
                if ($this->level) {
                    $result = '(' . $result . ')';
                }
            }
            $result = str_replace(['( ', ' ('], '(', $result);
            if ($needBool && $this->bool) {
                $result = $this->bool . ' ' . $result;
            }
        }

        return $result;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->asString();
    }

}
