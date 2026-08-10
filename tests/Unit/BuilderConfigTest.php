<?php

namespace avadim\Manticore\Tests\Unit;

use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use PHPUnit\Framework\TestCase;

/**
 * Config handling of the static facade. No server is involved: init() only stores the config,
 * a connection is opened lazily by connection().
 */
final class BuilderConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        // the facade is static - do not leak a config into whatever runs next
        ManticoreDb::init([]);
        parent::tearDown();
    }

    public function testInitAcceptsNull(): void
    {
        ManticoreDb::init(null);

        $this->assertSame([], ManticoreDb::currentConfig());
    }

    public function testInitAcceptsEmptyArray(): void
    {
        ManticoreDb::init([]);

        $this->assertSame([], ManticoreDb::currentConfig());
    }

    public function testInitStoresConfigAsIs(): void
    {
        $config = [
            'defaultConnection' => 'main',
            'connections' => ['main' => ['host' => 'example.org', 'port' => 9307]],
        ];

        ManticoreDb::init($config);

        // the config is not merged with the defaults, it is stored verbatim
        $this->assertSame($config, ManticoreDb::currentConfig());
    }

    public function testDefaultConfigDescribesLocalServer(): void
    {
        $default = ManticoreDb::defaultConfig();

        $this->assertSame('default', $default['defaultConnection']);
        $this->assertSame(9306, $default['connections']['default']['port']);
        $this->assertSame('', $default['connections']['default']['prefix']);
    }

    public function testUnknownConnectionThrows(): void
    {
        ManticoreDb::init([
            'defaultConnection' => 'main',
            'connections' => ['main' => ['host' => '127.0.0.1', 'port' => 9306]],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('was not defined in the config');

        ManticoreDb::connection('missing_connection');
    }
}
