<?php

namespace avadim\Manticore\Tests\Unit;

use avadim\Manticore\Tests\Support\FakeClient;
use avadim\Manticore\Tests\Support\UnitTestCase;

/**
 * INSERT / REPLACE / UPDATE / DELETE assembly.
 *
 * Write commands are executed as soon as they are called, so the generated SQL is read
 * back from the client instead of toSql().
 */
final class WriteSqlTest extends UnitTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function row(): array
    {
        return [
            'created_at' => 1700000000,
            'manufacturer' => "O'Neill",
            'title' => 'Galaxy S23',
            'info' => ['color' => 'Red', 'storage' => 512],
            'price' => 1199.00,
            'categories' => [5, 7, 11],
            'on_sale' => true,
        ];
    }

    public function testInsertSingleRowFormatsEveryTypeByColumnType(): void
    {
        $client = new FakeClient($this->productColumnTypes());
        $this->queryFor($client)->insert($this->row());

        $this->assertSqlSame(
            'INSERT INTO products(created_at,manufacturer,title,info,price,categories,on_sale)'
            . ' VALUES(1700000000,\'O\\\'Neill\',\'Galaxy S23\',\'{\\"color\\":\\"Red\\",\\"storage\\":512}\',1199,(5,7,11),1)',
            $client->lastQuery()
        );
    }

    public function testInsertAcceptsIdInsideData(): void
    {
        $client = new FakeClient($this->productColumnTypes());
        $this->queryFor($client)->insert(['id' => 42, 'title' => 'x']);

        $this->assertSqlSame("INSERT INTO products(id,title) VALUES(42,'x')", $client->lastQuery());
    }

    public function testInsertMultipleRowsBuildsSingleStatement(): void
    {
        $client = new FakeClient($this->productColumnTypes());
        $this->queryFor($client)->insert([
            ['title' => 'first', 'price' => 1.5],
            ['title' => 'second', 'price' => 2.5],
        ]);

        $this->assertSqlSame(
            "INSERT INTO products(title,price) VALUES ('first',1.5),('second',2.5)",
            $client->lastQuery()
        );
    }

    public function testMultipleRowsFillMissingColumnsWithEmptyValueOfTheirType(): void
    {
        // Manticore rejects NULL in INSERT, so a column missing from one of the rows has to be
        // written as the empty value of its type - 0 / '' / ()
        $client = new FakeClient($this->productColumnTypes());
        $this->queryFor($client)->insert([
            [
                'title' => 'first',
                'created_at' => 1700000000,
                'info' => ['a' => 1],
                'categories' => [1, 2],
                'price' => 1.5,
                'on_sale' => true,
            ],
            ['title' => 'second'],
        ]);

        $this->assertSqlSame(
            'INSERT INTO products(title,created_at,info,categories,price,on_sale)'
            . ' VALUES (\'first\',1700000000,\'{\\"a\\":1}\',(1,2),1.5,1),(\'second\',0,\'\',(),0,0)',
            $client->lastQuery()
        );
    }

    public function testReplaceUsesReplaceKeyword(): void
    {
        $client = new FakeClient($this->productColumnTypes());
        $this->queryFor($client)->replace(['title' => 'x'], 42);

        $this->assertSqlSame("REPLACE INTO products(title,id) VALUES('x',42)", $client->lastQuery());
    }

    public function testReplaceMultipleRows(): void
    {
        $client = new FakeClient($this->productColumnTypes());
        $this->queryFor($client)->replace([
            ['id' => 1, 'title' => 'first'],
            ['id' => 2, 'title' => 'second'],
        ]);

        $this->assertSqlSame(
            "REPLACE INTO products(id,title) VALUES (1,'first'),(2,'second')",
            $client->lastQuery()
        );
    }

    public function testUpdateBuildsSetClauseAndWhere(): void
    {
        $client = new FakeClient($this->productColumnTypes());
        $this->queryFor($client)->where('id', 7)->update(['price' => 9.99, 'on_sale' => false]);

        $this->assertSqlSame(
            'UPDATE products SET price=9.99, on_sale=0 WHERE (id=7)',
            $client->lastQuery()
        );
    }

    public function testUpdateWithExplicitId(): void
    {
        $client = new FakeClient($this->productColumnTypes());
        $this->queryFor($client)->update(['price' => 1.0], 42);

        $this->assertSqlSame('UPDATE products SET price=1 WHERE (id=42)', $client->lastQuery());
    }

    public function testUpdateEscapesStringValues(): void
    {
        $client = new FakeClient($this->productColumnTypes());
        $this->queryFor($client)->where('id', 1)->update(['manufacturer' => "O'Neill"]);

        $this->assertSqlSame(
            "UPDATE products SET manufacturer='O\\'Neill' WHERE (id=1)",
            $client->lastQuery()
        );
    }

    public function testUpdateFormatsMultiValueAsList(): void
    {
        $client = new FakeClient($this->productColumnTypes());
        $this->queryFor($client)->where('id', 1)->update(['categories' => [1, 2]]);

        $this->assertSqlSame('UPDATE products SET categories=(1,2) WHERE (id=1)', $client->lastQuery());
    }

    public function testDeleteWithCondition(): void
    {
        $client = new FakeClient($this->productColumnTypes());
        $this->queryFor($client)->where('price', '<', 10)->delete();

        $this->assertSqlSame('DELETE FROM products WHERE (price<10)', $client->lastQuery());
    }

    public function testDeleteWithMatch(): void
    {
        $client = new FakeClient($this->productColumnTypes());
        $this->queryFor($client)->match('junk')->delete();

        $this->assertSqlSame("DELETE FROM products WHERE MATCH('junk')", $client->lastQuery());
    }

    public function testWriteCommandsAskForColumnTypesOnce(): void
    {
        $client = new FakeClient($this->productColumnTypes());
        $query = $this->queryFor($client);
        $query->insert(['title' => 'a']);
        $query->insert(['title' => 'b']);

        $describes = array_filter($client->queries, static function (string $sql) {
            return stripos($sql, 'DESCRIBE') === 0;
        });

        $this->assertCount(1, $describes, 'DESCRIBE result must be cached per table');
    }

    public function testTruncateStatement(): void
    {
        $client = new FakeClient();
        $this->queryFor($client)->truncate();

        $this->assertSqlSame('TRUNCATE TABLE products', $client->lastQuery());
    }

    public function testTruncateWithReconfigure(): void
    {
        $client = new FakeClient();
        $this->queryFor($client)->truncate(true);

        $this->assertSqlSame('TRUNCATE TABLE products WITH RECONFIGURE', $client->lastQuery());
    }

    public function testDropStatement(): void
    {
        $client = new FakeClient();
        $this->queryFor($client)->drop();

        $this->assertSqlSame('DROP TABLE products', $client->lastQuery());
    }

    public function testDropIfExistsStatement(): void
    {
        $client = new FakeClient();
        $this->queryFor($client)->dropIfExists();

        $this->assertSqlSame('DROP TABLE IF EXISTS products', $client->lastQuery());
    }

    public function testOptimizeStatement(): void
    {
        $client = new FakeClient();
        $this->queryFor($client)->optimize();

        $this->assertSqlSame('OPTIMIZE INDEX products', $client->lastQuery());
    }

    public function testOptimizeSyncStatement(): void
    {
        $client = new FakeClient();
        $this->queryFor($client)->optimize(true);

        $this->assertSqlSame('OPTIMIZE INDEX products OPTION sync=1', $client->lastQuery());
    }

    public function testDescribeStatement(): void
    {
        $client = new FakeClient();
        $this->queryFor($client)->describe();

        $this->assertSqlSame('DESCRIBE products', $client->lastQuery());
    }

    public function testShowCreateStatement(): void
    {
        $client = new FakeClient();
        $this->queryFor($client)->showCreate();

        $this->assertSqlSame('SHOW CREATE TABLE products', $client->lastQuery());
    }

    public function testCreateStatementFromArraySchema(): void
    {
        $client = new FakeClient();
        $this->queryFor($client)->create([
            'created_at' => 'timestamp',
            'title' => 'text',
            'price' => ['type' => 'float'],
        ]);

        $this->assertSqlSame(
            'CREATE TABLE products(created_at timestamp, title text, price float)',
            $client->lastQuery()
        );
    }

    public function testCreateIfNotExists(): void
    {
        $client = new FakeClient();
        $this->queryFor($client)->ifNotExists()->create(['title' => 'text']);

        $this->assertSqlSame('CREATE TABLE IF NOT EXISTS products(title text)', $client->lastQuery());
    }

    public function testCreateAppliesTableOptions(): void
    {
        $client = new FakeClient();
        $this->queryFor($client)
            ->options(['morphology' => 'stem_en', 'html_strip' => 1])
            ->create(['title' => 'text']);

        $this->assertSqlSame(
            "CREATE TABLE products(title text) morphology='stem_en' html_strip='1'",
            $client->lastQuery()
        );
    }
}
