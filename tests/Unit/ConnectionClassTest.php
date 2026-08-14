<?php

namespace avadim\Manticore\Tests\Unit;

use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use avadim\Manticore\QueryBuilder\Connection;
use avadim\Manticore\Tests\Support\ConnectionWithoutClient;
use PHPUnit\Framework\TestCase;

/**
 * Builder::setConnectionClass() - the hook a wrapper for a framework needs.
 *
 * Without it the wrapper can subclass Connection all it wants: connection() would still hand
 * out the plain one, and the static facade would answer differently from the wrapped service.
 * No server is involved, the connection class used here opens nothing.
 */
final class ConnectionClassTest extends TestCase
{
    protected function tearDown(): void
    {
        // the builder is static - do not leak the class into whatever runs next
        ManticoreDb::setConnectionClass(Connection::class);
        ManticoreDb::init([]);

        parent::tearDown();
    }

    /**
     * @return array
     */
    private function config(): array
    {
        return [
            'defaultConnection' => 'main',
            'connections' => ['main' => ['host' => '127.0.0.1', 'port' => 9306]],
        ];
    }

    public function testConnectionIsBuiltOfConnectionByDefault(): void
    {
        $this->assertSame(Connection::class, ManticoreDb::connectionClass());
    }

    public function testConnectionIsBuiltOfTheGivenClass(): void
    {
        ManticoreDb::init($this->config());
        ManticoreDb::setConnectionClass(ConnectionWithoutClient::class);

        $this->assertInstanceOf(ConnectionWithoutClient::class, ManticoreDb::connection());
    }

    public function testTheClassMustExtendConnection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not extend');

        ManticoreDb::setConnectionClass(\stdClass::class);
    }

    /**
     * The wrapper sets the class once, on boot, and the config may arrive later - or arrive
     * again on every reconfiguration
     */
    public function testInitKeepsTheConnectionClass(): void
    {
        ManticoreDb::setConnectionClass(ConnectionWithoutClient::class);
        ManticoreDb::init($this->config());

        $this->assertSame(ConnectionWithoutClient::class, ManticoreDb::connectionClass());
        $this->assertInstanceOf(ConnectionWithoutClient::class, ManticoreDb::connection());
    }

    /**
     * The connections made so far are of the previous class, keeping them would mean the same
     * builder answering with two different kinds of connection
     */
    public function testChangingTheClassDropsTheConnectionsMadeSoFar(): void
    {
        ManticoreDb::init($this->config());
        ManticoreDb::setConnectionClass(ConnectionWithoutClient::class);
        $first = ManticoreDb::connection();

        ManticoreDb::setConnectionClass(Connection::class);
        ManticoreDb::setConnectionClass(ConnectionWithoutClient::class);

        $this->assertNotSame($first, ManticoreDb::connection());
    }
}
