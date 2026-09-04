<?php

namespace avadim\Manticore\Tests\Unit;

use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use avadim\Manticore\QueryBuilder\Connection;
use avadim\Manticore\Tests\Support\ConnectionWithoutClient;
use PHPUnit\Framework\TestCase;

/**
 * Builder::forgetConnection() - one connection out of the pool.
 *
 * init() empties the pool as a whole, and takes the config and the logger with it, which is more
 * than a worker with one dead handle wants. No server is involved here: the connection class used
 * opens nothing.
 */
final class ForgetConnectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ManticoreDb::setConnectionClass(ConnectionWithoutClient::class);
        ManticoreDb::init($this->config());
    }

    protected function tearDown(): void
    {
        // the builder is static - do not leak the class or the config into whatever runs next
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
            'connections' => [
                'main'   => ['host' => '127.0.0.1', 'port' => 9306],
                'second' => ['host' => '127.0.0.1', 'port' => 9306],
            ],
        ];
    }

    public function testTheNextCallBuildsANewConnection(): void
    {
        $first = ManticoreDb::connection();

        $this->assertTrue(ManticoreDb::forgetConnection());
        $this->assertNotSame($first, ManticoreDb::connection());
    }

    public function testOnlyTheNamedConnectionIsForgotten(): void
    {
        $main = ManticoreDb::connection('main');
        $second = ManticoreDb::connection('second');

        $this->assertTrue(ManticoreDb::forgetConnection('second'));

        $this->assertSame($main, ManticoreDb::connection('main'));
        $this->assertNotSame($second, ManticoreDb::connection('second'));
    }

    /**
     * No name means the default one, the same as everywhere else in the builder
     */
    public function testNoNameMeansTheDefaultConnection(): void
    {
        $main = ManticoreDb::connection('main');

        $this->assertTrue(ManticoreDb::forgetConnection());

        $this->assertNotSame($main, ManticoreDb::connection('main'));
    }

    public function testForgettingWhatWasNeverBuiltIsNotAnError(): void
    {
        $this->assertFalse(ManticoreDb::forgetConnection('second'));
        $this->assertFalse(ManticoreDb::forgetConnection('nothing-of-the-sort'));
    }

    /**
     * The config and the logger are what init() would have taken along
     */
    public function testTheConfigStaysWhereItWas(): void
    {
        ManticoreDb::connection();
        ManticoreDb::forgetConnection();

        $this->assertSame($this->config(), ManticoreDb::currentConfig());
    }

    /**
     * A connection someone is still holding goes on working - only the pool forgot it
     */
    public function testTheForgottenObjectIsNotClosed(): void
    {
        $held = ManticoreDb::connection();

        ManticoreDb::forgetConnection();

        $this->assertInstanceOf(ConnectionWithoutClient::class, $held);
    }
}
