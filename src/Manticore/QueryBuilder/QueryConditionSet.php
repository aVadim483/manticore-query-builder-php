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

    /** Internal extension point shared by Query and nested condition groups. */
    public function addSearchCondition(QueryCondition $condition): self
    {
        if (!$this->bool) {
            $this->bool = $condition->boolean();
        }
        $this->operands[] = $condition;
        return $this;
    }

    public function __clone()
    {
        foreach ($this->operands as $key => $operand) {
            $this->operands[$key] = clone $operand;
        }
    }

    public function hasFullText(): bool
    {
        foreach ($this->operands as $operand) {
            if ($operand instanceof FullTextCondition || $operand instanceof self && $operand->hasFullText()) {
                return true;
            }
        }
        return false;
    }

    public function hasRawConditions(): bool
    {
        foreach ($this->operands as $operand) {
            if ($operand instanceof self ? $operand->hasRawConditions() : $operand->isRaw()) {
                return true;
            }
        }
        return false;
    }

    public function computedExpressions(): array
    {
        $expressions = [];
        foreach ($this->operands as $operand) {
            if ($operand instanceof JsonCondition || $operand instanceof self) {
                foreach ($operand->computedExpressions() as $alias => $expression) {
                    if (isset($expressions[$alias]) && $expressions[$alias] !== $expression) {
                        throw new \LogicException('Computed JSON alias collision');
                    }
                    $expressions[$alias] = $expression;
                }
            }
        }
        return $expressions;
    }

    public function fullTextExpression(): ?string
    {
        if (!$this->hasFullText()) {
            return null;
        }
        return $this->partition()[0];
    }

    public function attributeString(?bool $needBool = false): string
    {
        if (!$this->hasFullText()) {
            return $this->asString($needBool);
        }
        return $this->partition()[1]->asString($needBool);
    }

    /** Separate only conjunctions; a mixed OR cannot be represented by one MATCH. */
    private function partition(): array
    {
        $texts = [];
        $attributes = clone $this;
        $attributes->operands = [];
        $hasOr = false;
        foreach ($this->operands as $position => $operand) {
            $boolean = $operand instanceof self ? $operand->bool : $operand->boolean();
            $hasOr = $hasOr || ($position > 0 && $boolean === 'OR');
            if ($operand instanceof FullTextCondition) {
                $texts[] = [$boolean, $operand->expression()];
            }
            elseif ($operand instanceof self) {
                [$text, $children] = $operand->partition();
                if ($text !== null) {
                    $texts[] = [$boolean, $text];
                }
                if ($children->operands) {
                    $attributes->operands[] = $children;
                }
            }
            else {
                $attributes->operands[] = clone $operand;
            }
        }
        if (!$texts) {
            return [null, $attributes];
        }
        if ($this->negate) {
            throw new \LogicException('Negated whereFullText() groups are not supported');
        }
        if ($attributes->operands && $hasOr) {
            throw new \LogicException('whereFullText() cannot be combined with attribute filters through OR');
        }
        $groups = [];
        $terms = [];
        foreach ($texts as [$boolean, $text]) {
            if ($boolean === 'OR' && $terms) {
                $groups[] = '(' . implode(' ', $terms) . ')';
                $terms = [];
            }
            $terms[] = '(' . $text . ')';
        }
        $groups[] = '(' . implode(' ', $terms) . ')';
        return [implode(' | ', $groups), $attributes];
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
