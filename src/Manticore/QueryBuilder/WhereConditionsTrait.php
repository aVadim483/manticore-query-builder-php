<?php

namespace avadim\Manticore\QueryBuilder;

/**
 * The where() family built on top of andWhere() / orWhere().
 *
 * Shared by Query and QueryConditionSet, so that a closure passed to where() offers the same
 * methods as the builder itself - otherwise whereIn() would work outside a group and fail
 * inside one.
 */
trait WhereConditionsTrait
{
    /**
     * @param $field
     *
     * @return $this
     */
    public function whereNull($field): self
    {
        return $this->andWhere($field, 'IS NULL', Query::NO_ARG);
    }

    /**
     * @param $field
     *
     * @return $this
     */
    public function andWhereNull($field): self
    {
        return $this->andWhere($field, 'IS NULL', Query::NO_ARG);
    }

    /**
     * @param $field
     *
     * @return $this
     */
    public function orWhereNull($field): self
    {
        return $this->orWhere($field, 'IS NULL', Query::NO_ARG);
    }

    /**
     * @param $field
     *
     * @return $this
     */
    public function whereNotNull($field): self
    {
        return $this->andWhere($field, 'IS NOT NULL', Query::NO_ARG);
    }

    /**
     * @param $field
     *
     * @return $this
     */
    public function andWhereNotNull($field): self
    {
        return $this->andWhere($field, 'IS NOT NULL', Query::NO_ARG);
    }

    /**
     * @param $field
     *
     * @return $this
     */
    public function orWhereNotNull($field): self
    {
        return $this->orWhere($field, 'IS NOT NULL', Query::NO_ARG);
    }

    /**
     * @param $field
     * @param array $arg
     *
     * @return $this
     */
    public function whereIn($field, array $arg): self
    {
        return $this->andWhere($field, 'IN', $arg);
    }

    /**
     * @param $field
     * @param array $arg
     *
     * @return $this
     */
    public function andWhereIn($field, array $arg): self
    {
        return $this->andWhere($field, 'IN', $arg);
    }

    /**
     * @param $field
     * @param array $arg
     *
     * @return $this
     */
    public function orWhereIn($field, array $arg): self
    {
        return $this->orWhere($field, 'IN', $arg);
    }

    /**
     * @param $field
     * @param array $arg
     *
     * @return $this
     */
    public function whereNotIn($field, array $arg): self
    {
        return $this->andWhere($field, 'NOT IN', $arg);
    }

    /**
     * @param $field
     * @param array $arg
     *
     * @return $this
     */
    public function andWhereNotIn($field, array $arg): self
    {
        return $this->andWhere($field, 'NOT IN', $arg);
    }

    /**
     * @param $field
     * @param array $arg
     *
     * @return $this
     */
    public function orWhereNotIn($field, array $arg): self
    {
        return $this->orWhere($field, 'NOT IN', $arg);
    }

    /**
     * @param $field
     * @param array $arg
     *
     * @return $this
     */
    public function whereBetween($field, array $arg): self
    {
        return $this->andWhere($field, 'BETWEEN', $arg);
    }

    /**
     * @param $field
     * @param array $arg
     *
     * @return $this
     */
    public function andWhereBetween($field, array $arg): self
    {
        return $this->andWhere($field, 'BETWEEN', $arg);
    }

    /**
     * @param $field
     * @param array $arg
     *
     * @return $this
     */
    public function orWhereBetween($field, array $arg): self
    {
        return $this->orWhere($field, 'BETWEEN', $arg);
    }

    /**
     * @param $field
     * @param array $arg
     *
     * @return $this
     */
    public function whereNotBetween($field, array $arg): self
    {
        return $this->andWhere($field, 'NOT BETWEEN', $arg);
    }

    /**
     * @param $field
     * @param array $arg
     *
     * @return $this
     */
    public function andWhereNotBetween($field, array $arg): self
    {
        return $this->andWhere($field, 'NOT BETWEEN', $arg);
    }

    /**
     * @param $field
     * @param array $arg
     *
     * @return $this
     */
    public function orWhereNotBetween($field, array $arg): self
    {
        return $this->orWhere($field, 'NOT BETWEEN', $arg);
    }

    /**
     * A condition as it is written: whereRaw('price > 100 AND qty < 10')
     *
     * @param string $expression
     *
     * @return $this
     */
    public function whereRaw(string $expression): self
    {
        return $this->andWhere($expression);
    }

    /**
     * REGEX(<column>, '<pattern>') - the substring and pattern matching of Manticore.
     *
     * This is what LIKE would have been: the server takes no LIKE in WHERE at all. Two things
     * to know about it:
     *
     *   - the pattern is matched against string attributes only. A full-text field is not an
     *     attribute, and the server answers a REGEX over one with a syntax error - use match()
     *     for those;
     *   - matching is case sensitive, unlike the LIKE of MySQL. Prefix the pattern with the
     *     inline flag "(?i)" to make it insensitive.
     *
     * The pattern is not anchored, so it matches anywhere in the value unless "^" and "$" say
     * otherwise. It goes through no index, i.e. it is a full scan of the attribute.
     *
     * @param string $column
     * @param string $pattern
     *
     * @return $this
     */
    public function whereRegex(string $column, string $pattern): self
    {
        return $this->andWhere(self::regexExpression($column, $pattern));
    }

    /**
     * @param string $column
     * @param string $pattern
     *
     * @return $this
     */
    public function orWhereRegex(string $column, string $pattern): self
    {
        return $this->orWhere(self::regexExpression($column, $pattern));
    }

    /**
     * @param string $column
     * @param string $pattern
     *
     * @return $this
     */
    public function whereNotRegex(string $column, string $pattern): self
    {
        return $this->whereNot(self::regexExpression($column, $pattern));
    }

    /**
     * @param string $column
     * @param string $pattern
     *
     * @return string
     */
    private static function regexExpression(string $column, string $pattern): string
    {
        return 'REGEX(' . $column . ', ' . Query::quoteParam($pattern) . ')';
    }

    /**
     * @param string $expression
     *
     * @return $this
     */
    public function orWhereRaw(string $expression): self
    {
        return $this->orWhere($expression);
    }

    /**
     * Negate a condition, a whole group of them included
     *
     *      whereNot('qty', 1)
     *      whereNot(function ($condition) { $condition->where('qty', 1)->orWhere('qty', 2); })
     *
     * @param mixed $field
     * @param mixed|null $arg1
     * @param mixed|null $arg2
     *
     * @return $this
     */
    public function whereNot($field, $arg1 = null, $arg2 = null): self
    {
        $args = func_get_args();

        return $this->andWhere(static function ($condition) use ($args) {
            $condition->where(...$args);
        }, 'NOT');
    }

    /**
     * The rows where at least one of the columns matches
     *
     *      whereAny(['title', 'brand'], 'acme')
     *      whereAny(['price', 'qty'], '>', 10)
     *
     * @param array $columns
     * @param mixed|null $arg1
     * @param mixed|null $arg2
     *
     * @return $this
     */
    public function whereAny(array $columns, $arg1 = null, $arg2 = null): self
    {
        $args = array_slice(func_get_args(), 1);

        return $this->andWhere(static function ($condition) use ($columns, $args) {
            foreach ($columns as $column) {
                $condition->orWhere($column, ...$args);
            }
        });
    }

    /**
     * The rows where every one of the columns matches
     *
     * @param array $columns
     * @param mixed|null $arg1
     * @param mixed|null $arg2
     *
     * @return $this
     */
    public function whereAll(array $columns, $arg1 = null, $arg2 = null): self
    {
        $args = array_slice(func_get_args(), 1);

        return $this->andWhere(static function ($condition) use ($columns, $args) {
            foreach ($columns as $column) {
                $condition->where($column, ...$args);
            }
        });
    }

    /**
     * The rows where none of the columns matches
     *
     * @param array $columns
     * @param mixed|null $arg1
     * @param mixed|null $arg2
     *
     * @return $this
     */
    public function whereNone(array $columns, $arg1 = null, $arg2 = null): self
    {
        $args = array_slice(func_get_args(), 1);

        return $this->whereNot(static function ($condition) use ($columns, $args) {
            foreach ($columns as $column) {
                $condition->orWhere($column, ...$args);
            }
        });
    }
}
