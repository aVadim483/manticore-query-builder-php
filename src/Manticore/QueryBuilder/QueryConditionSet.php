<?php

namespace avadim\Manticore\QueryBuilder;

class QueryConditionSet
{
    use WhereConditionsTrait;

    private ?string $bool = '';
    private array $operands = [];
    private array $params = [];
    private int $level = 0;
    private bool $negate = false;


    public function __construct(?string $bool = null, ?int $level = 0)
    {
        $this->level = $level;
        $this->bool = $bool;
    }

    /**
     * Wrap the whole group in NOT(), as whereNot() asks for
     *
     * @param bool $negate
     *
     * @return $this
     */
    public function negate(bool $negate = true): self
    {
        $this->negate = $negate;

        return $this;
    }


    /**
     * @param string $bool
     * @param array $args the arguments where() was called with, as they were passed
     *
     * @return void
     */
    protected function _add(string $bool, array $args)
    {
        if (!$this->bool) {
            $this->bool = $bool;
        }
        // the number of arguments is what tells where($field, $op, null) from where($field, $op)
        $args = array_slice(array_values($args), 0, 3);
        $this->operands[] = QueryCondition::create(
            $bool,
            $args[0] ?? null,
            $args[1] ?? null,
            $args[2] ?? null,
            $this->level + 1,
            count($args)
        );
    }

    /**
     * Usage:
     *      where('field', '>', 123)
     *      where('field', 123) - equal to where('field', '=', 123)
     *      where('field', null) - equal to where('field', 'IS NULL')
     *      where(['field' => 123, 'other' => 'value'])
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
        $this->_add('AND', func_get_args());

        return $this;
    }


    public function andWhere($field, $arg1 = null, $arg2 = null)
    {
        $this->_add('AND', func_get_args());

        return $this;
    }


    public function orWhere($field, $arg1 = null, $arg2 = null)
    {
        $this->_add('OR', func_get_args());

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
            if ($this->negate) {
                $result = 'NOT (' . $result . ')';
            }
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
