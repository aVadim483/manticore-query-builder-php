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
}
