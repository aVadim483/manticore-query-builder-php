<?php

namespace avadim\Manticore\Tests\Unit;

use avadim\Manticore\Tests\Support\FakeClient;
use avadim\Manticore\Tests\Support\UnitTestCase;

/**
 * SQL of the imperative ALTER statements: ADD / DROP / MODIFY COLUMN, RENAME, settings.
 */
final class AlterSqlTest extends UnitTestCase
{
    public function testAddColumnWithAPlainType(): void
    {
        $client = new FakeClient();
        $this->queryFor($client, 'products')->addColumn('price', 'float');

        $this->assertSame('ALTER TABLE products ADD COLUMN price float', $client->lastQuery());
    }

    public function testAddColumnSplitsTheFlagsOfTheType(): void
    {
        $client = new FakeClient();
        $this->queryFor($client, 'products')->addColumn('title', 'text indexed stored');

        $this->assertSame('ALTER TABLE products ADD COLUMN title text indexed stored', $client->lastQuery());
    }

    public function testAddColumnTakesTheFlagsAsAnOption(): void
    {
        $client = new FakeClient();
        $this->queryFor($client, 'products')->addColumn('article', 'text', 'indexed');

        $this->assertSame('ALTER TABLE products ADD COLUMN article text indexed', $client->lastQuery());
    }

    public function testAddColumnTakesAnArrayDefinition(): void
    {
        $client = new FakeClient();
        $this->queryFor($client, 'products')->addColumn('time', ['type' => 'timestamp', 'engine' => 'columnar']);

        $this->assertSame('ALTER TABLE products ADD COLUMN time timestamp engine=\'columnar\'', $client->lastQuery());
    }

    /**
     * ADD COLUMN has no fast_fetch in its grammar, the server would reject the statement
     */
    public function testAddColumnDropsFastFetch(): void
    {
        $client = new FakeClient();
        $this->queryFor($client, 'products')->addColumn('country', ['type' => 'string', 'attribute', 'fast_fetch' => 0]);

        $this->assertSame('ALTER TABLE products ADD COLUMN country string attribute', $client->lastQuery());
    }

    public function testAddColumnResolvesThePrefix(): void
    {
        $client = new FakeClient();
        $this->queryFor($client, '?products', ['prefix' => 'test_'])->addColumn('price', 'float');

        $this->assertSame('ALTER TABLE test_products ADD COLUMN price float', $client->lastQuery());
    }

    public function testDropColumn(): void
    {
        $client = new FakeClient();
        $this->queryFor($client, 'products')->dropColumn('price');

        $this->assertSame('ALTER TABLE products DROP COLUMN price', $client->lastQuery());
    }

    /**
     * The server takes one operation per statement, so a list of columns is a chain of queries
     */
    public function testDropColumnSendsAStatementPerName(): void
    {
        $client = new FakeClient();
        $result = $this->queryFor($client, 'products')->dropColumn(['title', 'title', 'price']);

        $this->assertSame([
            'ALTER TABLE products DROP COLUMN title',
            'ALTER TABLE products DROP COLUMN title',
            'ALTER TABLE products DROP COLUMN price',
        ], $client->queries);
        // and every query that ran is reported back
        $this->assertSame(implode('; ', $client->queries), $result->sqlQuery());
    }

    public function testDropColumnNeedsAName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->query('products')->dropColumn([]);
    }

    public function testModifyColumn(): void
    {
        $client = new FakeClient();
        $this->queryFor($client, 'products')->modifyColumn('group_id', 'bigint');

        $this->assertSame('ALTER TABLE products MODIFY COLUMN group_id bigint', $client->lastQuery());
    }

    public function testRenameResolvesThePrefixOfBothNames(): void
    {
        $client = new FakeClient();
        $query = $this->queryFor($client, '?products', ['prefix' => 'test_']);
        $query->rename('?goods');

        $this->assertSame('ALTER TABLE test_products RENAME test_goods', $client->lastQuery());

        // the query goes on with the same table under its new name
        $query->dropColumn('price');
        $this->assertSame('ALTER TABLE test_goods DROP COLUMN price', $client->lastQuery());
    }

    public function testAlterSettingsSendsThemInOneStatement(): void
    {
        $client = new FakeClient();
        $this->queryFor($client, 'products')->alterSettings([
            'charset_table' => 'a,b,c',
            'html_strip' => 1,
        ]);

        $this->assertSame('ALTER TABLE products charset_table=\'a,b,c\', html_strip=\'1\'', $client->lastQuery());
    }

    public function testAlterSettingsJoinsAListValue(): void
    {
        $client = new FakeClient();
        $this->queryFor($client, 'products')->alterSettings(['morphology' => ['lemmatize_uk_all', 'lemmatize_de_all']]);

        $this->assertSame('ALTER TABLE products morphology=\'lemmatize_uk_all,lemmatize_de_all\'', $client->lastQuery());
    }

    public function testAlterSettingsNeedsAMap(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->query('products')->alterSettings(['html_strip=1']);
    }

    public function testAlterNeedsATable(): void
    {
        $this->expectException(\LogicException::class);

        $this->query(null)->addColumn('price', 'float');
    }

    /**
     * The result carries the status of the whole chain, not of a single statement
     */
    public function testResultOfASuccessfulAlter(): void
    {
        $result = $this->query('products')->addColumn('price', 'float');

        $this->assertTrue($result->success());
        $this->assertSame('ALTER', $result->command());
        $this->assertSame('altered', $result->status());
        $this->assertNull($result->error());
    }
}
