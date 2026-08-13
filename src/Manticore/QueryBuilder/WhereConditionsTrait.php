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
}
