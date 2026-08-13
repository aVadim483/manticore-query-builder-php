<?php

namespace avadim\Manticore\Tests\Integration;

use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use avadim\Manticore\QueryBuilder\Schema\SchemaTable;
use avadim\Manticore\Tests\Support\IntegrationTestCase;

/**
 * CREATE / DROP / TRUNCATE and the table introspection helpers.
 */
final class TableManagementTest extends IntegrationTestCase
{
    /**
     * @return array<string, string|array>
     */
    private function fields(): array
    {
        return [
            'created_at' => 'timestamp',
            'manufacturer' => 'string',
            'title' => 'text',
            'info' => 'json',
            'price' => ['type' => 'float'],
            'categories' => 'multi',
            'on_sale' => 'bool',
        ];
    }

    public function testCreateAndDrop(): void
    {
        $table = $this->tableName('products');

        $result = ManticoreDb::table($table)->create($this->fields());
        $this->assertTrue($result->success(), (string)$result->error());
        $this->assertTrue($result->result());
        $this->assertSame('created', $result->status());

        $this->assertTrue(ManticoreDb::hasTable($table));

        $result = ManticoreDb::table($table)->drop();
        $this->assertTrue($result->result());
        $this->assertFalse(ManticoreDb::hasTable($table));
    }

    public function testDropOfMissingTableFails(): void
    {
        $result = ManticoreDb::drop($this->tableName('missing'));

        $this->assertFalse($result->success());
        $this->assertNotNull($result->error());
    }

    public function testDropIfExistsSucceedsOnMissingTable(): void
    {
        $result = ManticoreDb::dropIfExists($this->tableName('missing'));

        $this->assertTrue($result->success());
    }

    public function testCreateIfNotExistsDoesNotFailOnSecondCall(): void
    {
        $table = $this->tableName('products');

        $first = ManticoreDb::createIfNotExists($table, $this->fields());
        $this->assertTrue($first->success(), (string)$first->error());

        $second = ManticoreDb::createIfNotExists($table, $this->fields());
        $this->assertTrue($second->success(), (string)$second->error());
    }

    public function testCreateOverExistingTableFails(): void
    {
        $table = $this->createTable($this->fields(), 'products');

        $result = ManticoreDb::table($table)->create($this->fields());

        $this->assertFalse($result->success());
    }

    public function testCreateFromCallbackSchema(): void
    {
        $table = $this->tableName('callback');

        $result = ManticoreDb::create($table, function (SchemaTable $schema) {
            $schema->timestamp('created_at');
            $schema->string('manufacturer');
            $schema->text('title');
            $schema->float('price');
        });
        $this->assertTrue($result->success(), (string)$result->error());

        $describe = ManticoreDb::tableDescribe($table);
        $this->assertSame(
            ['id', 'title', 'created_at', 'manufacturer', 'price'],
            array_keys($describe)
        );
    }

    public function testCreateFromSchemaTableObject(): void
    {
        $table = $this->tableName('object');
        $schema = new SchemaTable();
        $schema->text('title');
        $schema->float('price');

        $result = ManticoreDb::create($table, $schema);

        $this->assertTrue($result->success(), (string)$result->error());
        $this->assertTrue(ManticoreDb::hasTable($table));
    }

    public function testTableOptionsAreStored(): void
    {
        $table = $this->createTable($this->fields(), 'options', [
            'charset_table' => 'cjk',
            'morphology' => 'icu_chinese',
        ]);

        $settings = ManticoreDb::tableSettings($table);

        $this->assertSame('cjk', $settings['charset_table']);
        $this->assertSame('icu_chinese', $settings['morphology']);
    }

    public function testTableStatusOfEmptyTable(): void
    {
        $table = $this->createTable($this->fields(), 'status');

        $status = ManticoreDb::tableStatus($table);

        // "index_type" was renamed to "table_type" in newer Manticore versions
        $this->assertSame('rt', $status['table_type'] ?? $status['index_type'] ?? null);
        $this->assertEquals(0, $status['indexed_documents']);
    }

    public function testRecreatedTableIsDescribedAgain(): void
    {
        // the schema cache lives in the connection now, so a table recreated with other
        // columns must not be read back through the columns of its previous incarnation
        $table = $this->createTable(['title' => 'text', 'qty' => 'integer'], 'recreate');
        ManticoreDb::table($table)->insert(['title' => 'first', 'qty' => 7]);
        $before = ManticoreDb::table($table)->first();
        $this->assertSame(7, $before['qty']);

        ManticoreDb::drop($table);
        ManticoreDb::create($table, ['title' => 'text', 'qty' => 'multi']);
        ManticoreDb::table($table)->insert(['title' => 'second', 'qty' => [1, 2, 3]]);

        $after = ManticoreDb::table($table)->first();
        $this->assertSame([1, 2, 3], $after['qty'], 'the new column type must be used');
    }

    public function testTableDescribeReportsColumnTypes(): void
    {
        $table = $this->createTable($this->fields(), 'describe');

        $describe = ManticoreDb::tableDescribe($table);

        $this->assertSame('bigint', $describe['id']['Type']);
        foreach ($this->fields() as $column => $definition) {
            $type = is_array($definition) ? $definition['type'] : $definition;
            $this->assertSame(
                $type === 'multi' ? 'mva' : $type,
                $describe[$column]['Type'],
                'Unexpected type of column ' . $column
            );
        }
    }

    public function testDescribeIsAliasOfTableDescribe(): void
    {
        $table = $this->createTable(['title' => 'text'], 'alias');

        $this->assertSame(ManticoreDb::tableDescribe($table), ManticoreDb::describe($table));
    }

    public function testShowTablesReportsCreatedTable(): void
    {
        $table = $this->createTable($this->fields(), 'shown');

        $tables = ManticoreDb::showTables($table);

        $this->assertCount(1, $tables);
        $this->assertSame('rt', $tables[0]['Type']);
        // "Index" is the pre-6.0 name of the column, "Table" the current one - both are reported
        $this->assertSame($table, $tables[0]['Index']);
        $this->assertSame($table, $tables[0]['Table']);
    }

    public function testShowTablesKeepsRealNameWhenItHasNoPrefix(): void
    {
        $table = $this->createTable(['title' => 'text'], 'noprefix');

        $tables = ManticoreDb::showTables($table);

        $this->assertSame($table, $tables[0]['Name']);
    }

    public function testHasTableIsFalseForMissingTable(): void
    {
        $this->assertFalse(ManticoreDb::hasTable($this->tableName('missing')));
    }

    public function testShowCreateReturnsStatement(): void
    {
        $table = $this->createTable(['title' => 'text', 'price' => 'float'], 'create');

        $sql = ManticoreDb::showCreate($table);

        $this->assertStringContainsStringIgnoringCase('CREATE TABLE', $sql);
        $this->assertStringContainsString($table, $sql);
        $this->assertStringContainsStringIgnoringCase('title', $sql);
    }

    public function testTruncateRemovesRowsButKeepsTable(): void
    {
        $table = $this->createTable(['title' => 'text'], 'truncate');
        ManticoreDb::table($table)->insert([['title' => 'a'], ['title' => 'b']]);

        $result = ManticoreDb::table($table)->truncate();

        $this->assertTrue($result->success(), (string)$result->error());
        $this->assertTrue(ManticoreDb::hasTable($table));
        $this->assertSame(0, ManticoreDb::table($table)->count());
    }

    /**
     * TRUNCATE ... WITH RECONFIGURE only works for tables declared in manticore.conf,
     * so only the statement itself is asserted here - see WriteSqlTest for the SQL.
     */
    public function testTruncateWithReconfigureIsSentToServer(): void
    {
        $table = $this->createTable(['title' => 'text'], 'reconfigure');
        ManticoreDb::table($table)->insert(['title' => 'a']);

        $result = ManticoreDb::table($table)->truncate(true);

        $this->assertSame('TRUNCATE TABLE ' . $table . ' WITH RECONFIGURE', $result->sqlQuery());
    }

    public function testOptimizeRuns(): void
    {
        $table = $this->createTable(['title' => 'text'], 'optimize');
        ManticoreDb::table($table)->insert(['title' => 'a']);

        $result = ManticoreDb::table($table)->optimize();

        $this->assertTrue($result->success(), (string)$result->error());
    }

    public function testShowVariablesReturnsServerSettings(): void
    {
        $variables = ManticoreDb::showVariables();

        $this->assertNotEmpty($variables);
    }

    public function testShowVariablesWithPatternThatMatchesNothingReturnsEmptyArray(): void
    {
        $this->assertSame([], ManticoreDb::showVariables('%no_such_variable%'));
    }
}
