<?php

namespace avadim\Manticore\Tests\Support;

use avadim\Manticore\QueryBuilder\Connection;

/**
 * Stands for the Connection of a framework wrapper: everything as before, but the queries
 * are built of SubclassedQuery.
 */
final class SubclassedConnection extends Connection
{
    protected string $queryClass = SubclassedQuery::class;
}
