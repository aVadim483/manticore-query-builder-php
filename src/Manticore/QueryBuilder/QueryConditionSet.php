<?php

namespace avadim\Manticore\QueryBuilder;

class QueryConditionSet
{
    private array $operands = [];
    private array $params = [];


    protected function _add(string $bool, $field, $arg1, $arg2 = null)
    {
        if (!$this->operands) {
            $bool = '';
        }
        $this->operands[] = QueryCondition::create($bool, $field, $arg1, $arg2);
    }


    public function where($field, $arg1, $arg2 = null)
    {
        $this->_add('AND', $field, $arg1, $arg2);

        return $this;
    }


    public function andWhere($field, $arg1, $arg2 = null)
    {
        $this->_add('AND', $field, $arg1, $arg2);

        return $this;
    }


    public function orWhere($field, $arg1, $arg2 = null)
    {
        $this->_add('OR', $field, $arg1, $arg2);

        return $this;
    }

    /**
     * @param array $bind
     */
    public function bind(array $bind)
    {
        foreach ($bind as $name => $value) {
            if (preg_match('/^:\w+$/', $name)) {
                $this->params[$name] = addslashes($value);
            }
        }
    }

    /**
     * @param $needBool
     *
     * @return string
     */
    public function asString($needBool = false): string
    {
        $result = '';
        /** @var QueryCondition $condition */
        foreach ($this->operands as $n => $condition) {
            $condition->bind($this->params);
            $result .= $condition->asString($n);
        }

        return $result;
    }

    public function __toString()
    {
        return $this->asString();
    }

}