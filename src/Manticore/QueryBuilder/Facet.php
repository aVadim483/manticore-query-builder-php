<?php

namespace avadim\Manticore\QueryBuilder;

class Facet
{
    private string $column;

    private string $alias = '';
    private array $orders = [];
    private array $limit = [];
    private string $expression = '';
    private string $distinct = '';


    public function __construct(string $column)
    {
        $this->column = $column;
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return $this->_makeSql();
    }

    /**
     * @param string $expression
     *
     * @return $this
     */
    public function byExpr(string $expression): Facet
    {
        $this->expression = $expression;

        return $this;
    }

    /**
     * @param string $column
     *
     * @return $this
     */
    public function distinct(string $column): Facet
    {
        $this->distinct = $column;

        return $this;
    }

    /**
     * @param string $alias
     *
     * @return $this
     */
    public function alias(string $alias): Facet
    {
        $this->alias = $alias;

        return $this;
    }

    /**
     * @param string $names
     *
     * @return $this
     */
    public function orderBy(string $names): Facet
    {
        $this->orders[] = $names . ' ASC';

        return $this;
    }

    /**
     * @param string $names
     *
     * @return $this
     */
    public function orderByDesc(string $names): Facet
    {
        $this->orders[] = $names . ' DESC';

        return $this;
    }

    /**
     * limit(<limit>)
     * limit(<offset>, <limit>)
     *
     * @param int|array $param1
     * @param int|null $param2
     *
     * @return $this
     */
    public function limit($param1, ?int $param2 = null): Facet
    {
        if ($param2 === null) {
            $this->limit = [$param1, null];
        }
        else {
            $this->limit = [$param2, $param1];
        }

        return $this;
    }

    /**
     * @return string
     */
    protected function _sqlDistinct(): string
    {
        if ($this->distinct) {
            return $this->distinct;
        }

        return '';
    }

    /**
     * @return string
     */
    protected function _sqlExpression(): string
    {
        if ($this->expression) {
            return $this->expression;
        }

        return '';
    }

    /**
     * @return string
     */
    protected function _sqlAlias(): string
    {
        if ($this->alias) {
            return $this->alias;
        }

        return '';
    }

    /**
     * @return string
     */
    protected function _sqlOrders(): string
    {
        if ($this->orders) {
            return implode(',', $this->orders);
        }

        return '';
    }

    /**
     * @return string
     */
    protected function _sqlLimit(): string
    {
        if ($this->limit) {
            $offset = isset($this->limit[1]) ? $this->limit[1] . ',' : '';

            return $offset . $this->limit[0];
        }

        return '';
    }

    /**
     * @return string
     */
    protected function _makeSql(): string
    {
        $result = 'FACET ' . $this->column;
        if ($alias = $this->_sqlAlias()) {
            $result .= ' AS ' . $alias;
        }
        if ($distinct = $this->_sqlDistinct()) {
            $result .= ' DISTINCT ' . $distinct;
        }
        if ($expr = $this->_sqlExpression()) {
            $result .= ' BY ' . $expr;
        }
        if ($orders = $this->_sqlOrders()) {
            $result .= ' ORDER BY ' . $orders;
        }
        if ($limit = $this->_sqlLimit()) {
            $result .= ' LIMIT ' . $limit;
        }

        return $result;
    }

}
