<?php

declare(strict_types=1);

namespace avadim\Manticore\QueryBuilder;

/** An immutable full-text leaf, later combined into one MATCH expression. */
final class FullTextCondition extends QueryCondition
{
    private string $expression;

    public function __construct(string $boolean, $columns, string $text, array $options = [])
    {
        if (!is_string($columns) && !is_array($columns)) {
            throw new \InvalidArgumentException('whereFullText() needs a field or a list of fields');
        }
        $columns = (array)$columns;
        if (!$columns || array_keys($columns) !== range(0, count($columns) - 1)) {
            throw new \InvalidArgumentException('whereFullText() needs a non-empty list of fields');
        }
        foreach ($columns as $column) {
            if (!is_string($column) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $column)) {
                throw new \InvalidArgumentException('Invalid whereFullText() field');
            }
        }
        if (trim($text) === '') {
            throw new \InvalidArgumentException('whereFullText() needs non-empty search text');
        }
        if (array_diff(array_keys($options), ['escape'])) {
            throw new \InvalidArgumentException('Unknown whereFullText() option');
        }
        if (isset($options['escape']) && !is_bool($options['escape'])
            || array_key_exists('escape', $options) && $options['escape'] === null) {
            throw new \InvalidArgumentException('whereFullText() escape must be boolean');
        }
        $fields = count($columns) === 1 ? reset($columns) : '(' . implode(',', $columns) . ')';
        $this->expression = '@' . $fields . ' (' . (($options['escape'] ?? true) ? Query::escapeMatch($text) : $text) . ')';
        parent::__construct($boolean, '');
    }

    public function expression(): string
    {
        return $this->expression;
    }
}
