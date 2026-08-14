<?php

declare(strict_types=1);

namespace avadim\Manticore\QueryBuilder;

/**
 * The server rejected a statement that was asked for its rows.
 *
 * Reads throw, writes answer with a scalar and keep the whole answer in the ResultSet - the
 * same split as everywhere else in the builder. A query that wants the ResultSet of a failed
 * read instead of an exception has exec() and search() for that.
 */
class QueryErrorException extends \RuntimeException
{
    /**
     * @var string|null
     */
    private $sql;

    /**
     * @param string $message
     * @param string|null $sql
     */
    public function __construct(string $message, ?string $sql = null)
    {
        parent::__construct($sql ? $message . ' [SQL: ' . $sql . ']' : $message);
        $this->sql = $sql;
    }

    /**
     * The statement the server rejected
     *
     * @return string|null
     */
    public function sql(): ?string
    {
        return $this->sql;
    }
}
