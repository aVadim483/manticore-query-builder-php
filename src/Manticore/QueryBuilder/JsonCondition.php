<?php

declare(strict_types=1);

namespace avadim\Manticore\QueryBuilder;

/** A condition over a hidden SELECT expression required by the SQL grammar. */
final class JsonCondition extends QueryCondition
{
    private string $expression;
    private string $alias;

    public function __construct(string $boolean, string $expression, string $operator, $value)
    {
        $this->expression = $expression;
        $this->alias = '__mqb_json_' . substr(hash('sha256', $expression), 0, 16);
        parent::__construct($boolean, $this->alias, $operator, Query::quoteParam($value));
    }

    public function computedExpressions(): array
    {
        return [$this->alias => $this->expression];
    }
}
