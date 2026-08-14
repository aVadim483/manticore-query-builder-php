<?php

namespace avadim\Manticore\Tests\Support;

use avadim\Manticore\QueryBuilder\Query;

/**
 * Stands for the Query of a framework wrapper: the same builder, with an answer of its own.
 */
final class SubclassedQuery extends Query
{
    /**
     * The rows of get(), wrapped the way a framework wrapper would wrap them
     *
     * @param string|array|null $columns
     * @param string|array ...$more
     *
     * @return array
     */
    public function getWrapped($columns = '*', ...$more): array
    {
        return ['wrapped' => func_num_args() ? $this->get($columns, ...$more) : $this->get()];
    }

    /**
     * Answers with an object rather than an array.
     *
     * This is only possible while the parent leaves the return type out of the signature -
     * declaring "array" there would make this class fatal at compile time, which is exactly
     * what the test built on this method guards against.
     *
     * @param string $column
     * @param string|null $key
     *
     * @return \ArrayObject
     */
    public function pluck(string $column, ?string $key = null)
    {
        return new \ArrayObject(parent::pluck($column, $key));
    }
}
