<?php

namespace avadim\Manticore\Tests\Integration;

use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use avadim\Manticore\QueryBuilder\Schema\SchemaTable;
use avadim\Manticore\Tests\Support\IntegrationTestCase;

/**
 * Named connections and the "?table" prefix placeholder.
 *
 * Both connections point at the same server but use different prefixes, so the same
 * "?products" name has to resolve to two distinct tables.
 */
final class ConnectionPrefixTest extends IntegrationTestCase
{
    /** @var string */
    private string $prefix1;

    /** @var string */
    private string $prefix2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prefix1 = 'conn1_' . str_replace('.', '', uniqid('', true)) . '_';
        $this->prefix2 = 'conn2_' . str_replace('.', '', uniqid('', true)) . '_';

        $config = $this->config();
        $config['connections'][self::CONNECTION_1]['prefix'] = $this->prefix1;
        $config['connections'][self::CONNECTION_2]['prefix'] = $this->prefix2;
        ManticoreDb::init($config);

        $this->registerTable('?products', self::CONNECTION_1);
        $this->registerTable('?products', self::CONNECTION_2);
    }

    /**
     * @return array<string, string|array>
     */
    private function fields(): array
    {
        return [
            'created_at' => 'timestamp',
            'manufacturer' => 'string',
            'title' => 'text',
            'price' => ['type' => 'float'],
        ];
    }

    public function testPlaceholderResolvesToPrefixedTable(): void
    {
        $result = ManticoreDb::table('?products')->create($this->fields());

        $this->assertTrue($result->success(), (string)$result->error());
        $this->assertStringContainsString($this->prefix1 . 'products', $result->sqlQuery());
        $this->assertTrue(ManticoreDb::hasTable($this->prefix1 . 'products'));
    }

    public function testSameNameGivesDifferentTablesPerConnection(): void
    {
        ManticoreDb::table('?products')->create($this->fields());
        ManticoreDb::connection(self::CONNECTION_2)->table('?products')->create($this->fields());

        ManticoreDb::table('?products')->insert(['title' => 'from first', 'price' => 1.0]);
        ManticoreDb::connection(self::CONNECTION_2)->table('?products')->insert([
            ['title' => 'from second', 'price' => 2.0],
            ['title' => 'from second too', 'price' => 3.0],
        ]);

        $this->assertSame(1, ManticoreDb::table('?products')->count());
        $this->assertSame(2, ManticoreDb::connection(self::CONNECTION_2)->table('?products')->count());

        $first = ManticoreDb::table('?products')->first();
        $this->assertSame('from first', $first['title']);
    }

    public function testTableOptionsPerConnection(): void
    {
        ManticoreDb::table('?products')->options([
            'charset_table' => 'cjk',
            'morphology' => 'icu_chinese',
        ])->create($this->fields());

        $result = ManticoreDb::connection(self::CONNECTION_2)->create('?products', function (SchemaTable $schema) {
            $schema->timestamp('created_at');
            $schema->text('title');
            $schema->tableMorphology('lemmatize_en_all');
            $schema->tableOptions(['min_stemming_len' => 5, 'html_strip' => 1]);
        });
        $this->assertTrue($result->success(), (string)$result->error());

        $settings1 = ManticoreDb::tableSettings('?products');
        $this->assertSame('cjk', $settings1['charset_table']);
        $this->assertSame('icu_chinese', $settings1['morphology']);

        $settings2 = ManticoreDb::connection(self::CONNECTION_2)->tableSettings('?products');
        $this->assertSame('lemmatize_en_all', $settings2['morphology']);
        $this->assertEquals(5, $settings2['min_stemming_len']);
        $this->assertEquals(1, $settings2['html_strip']);
    }

    public function testShowTablesListsPrefixedTables(): void
    {
        ManticoreDb::table('?products')->create($this->fields());

        $tables = array_column(ManticoreDb::showTables('?%'), 'Table');

        $this->assertContains($this->prefix1 . 'products', $tables);
    }

    public function testShowTablesOfSecondConnectionUsesItsOwnPrefix(): void
    {
        ManticoreDb::connection(self::CONNECTION_2)->table('?products')->create($this->fields());

        $tables = array_column(ManticoreDb::connection(self::CONNECTION_2)->showTables('?%'), 'Table');

        $this->assertContains($this->prefix2 . 'products', $tables);
    }

    public function testIndexIsAliasOfTableOnConnection(): void
    {
        ManticoreDb::connection(self::CONNECTION_2)->index('?products')->create($this->fields());

        $this->assertTrue(
            ManticoreDb::connection(self::CONNECTION_2)->hasTable($this->prefix2 . 'products')
        );
    }

    public function testForcePrefixAppliesToPlainNames(): void
    {
        $config = $this->config();
        $config['connections'][self::CONNECTION_1]['prefix'] = $this->prefix1;
        $config['connections'][self::CONNECTION_1]['force_prefix'] = true;
        ManticoreDb::init($config);
        $this->registerTable('products', self::CONNECTION_1);

        $result = ManticoreDb::table('products')->create($this->fields());

        $this->assertTrue($result->success(), (string)$result->error());
        $this->assertStringContainsString($this->prefix1 . 'products', $result->sqlQuery());
        // with force_prefix every name is prefixed, including the one passed to hasTable()
        $this->assertTrue(ManticoreDb::hasTable('products'));
    }

    public function testInitResetsConnectionPool(): void
    {
        ManticoreDb::table('?products')->create($this->fields());
        ManticoreDb::table('?products')->insert(['title' => 'first', 'price' => 1.0]);

        // re-init with a different prefix: the old connection must be gone
        $config = $this->config();
        $config['connections'][self::CONNECTION_1]['prefix'] = $this->prefix2;
        ManticoreDb::init($config);

        $this->assertFalse(ManticoreDb::hasTable('?products'), '?products now points at the other prefix');
        $this->assertTrue(ManticoreDb::hasTable($this->prefix1 . 'products'));
    }

    public function testDefaultConnectionIsUsedWhenNameIsOmitted(): void
    {
        $explicit = ManticoreDb::connection(self::CONNECTION_1);

        $this->assertSame($explicit, ManticoreDb::connection());
    }
}
