<?php

namespace avadim\Manticore\Tests\Support;

use avadim\Manticore\QueryBuilder\Connection;
use Psr\Log\LoggerInterface;

/**
 * A Connection that opens nothing.
 *
 * Connection::__construct() builds a PDOClient right away, so a test that only wants to see
 * which class the builder instantiates would need a live server. This subclass skips the
 * constructor of the parent - it must never be asked for a query, only for its class.
 */
final class ConnectionWithoutClient extends Connection
{
    /**
     * @param array $config
     * @param LoggerInterface|null $logger
     */
    public function __construct(array $config, ?LoggerInterface $logger = null)
    {
        // deliberately no parent::__construct(): no PDO connection is opened
    }
}
