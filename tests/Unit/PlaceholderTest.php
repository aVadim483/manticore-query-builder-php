<?php

namespace avadim\Manticore\Tests\Unit;

use avadim\Manticore\QueryBuilder\Parser;
use avadim\Manticore\Tests\Support\UnitTestCase;

/**
 * "?table" placeholders in raw SQL passed to sql().
 *
 * Parser::parse() rewrites the table name per command, so every supported command is covered.
 */
final class PlaceholderTest extends UnitTestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public function sqlProvider(): array
    {
        return [
            'create' => [
                'create table ?products(title text, price float) morphology=\'stem_en\'',
                'create table second_products(title text, price float) morphology=\'stem_en\'',
                'create table products(title text, price float) morphology=\'stem_en\'',
            ],
            'insert' => [
                'insert into ?products(title,price) values (\'crossbody bag with tassel\', 19.85), (\'microfiber sheet set\', 19.99)',
                'insert into second_products(title,price) values (\'crossbody bag with tassel\', 19.85), (\'microfiber sheet set\', 19.99)',
                'insert into products(title,price) values (\'crossbody bag with tassel\', 19.85), (\'microfiber sheet set\', 19.99)',
            ],
            'select' => [
                'select id, highlight(), price from ?products where match(\'remove hair\')',
                'select id, highlight(), price from second_products where match(\'remove hair\')',
                'select id, highlight(), price from products where match(\'remove hair\')',
            ],
            'update' => [
                'update ?products set price=18.5 where id = 1513686608316989452',
                'update second_products set price=18.5 where id = 1513686608316989452',
                'update products set price=18.5 where id = 1513686608316989452',
            ],
            'delete' => [
                'delete from ?products where price < 10',
                'delete from second_products where price < 10',
                'delete from products where price < 10',
            ],
            'truncate' => [
                'TRUNCATE TABLE ?products with reconfigure;',
                'truncate table second_products with reconfigure',
                'truncate table products with reconfigure',
            ],
        ];
    }

    /**
     * @dataProvider sqlProvider
     *
     * @param string $source
     * @param string $withPrefix
     */
    public function testPlaceholderIsReplacedWithPrefix(string $source, string $withPrefix): void
    {
        $sql = $this->query(null, [], ['prefix' => 'second_'])->sql($source)->toSql();

        $this->assertSame($withPrefix, mb_strtolower($sql));
    }

    /**
     * @dataProvider sqlProvider
     *
     * @param string $source
     * @param string $withPrefix
     * @param string $withoutPrefix
     */
    public function testPlaceholderIsRemovedWhenPrefixIsEmpty(string $source, string $withPrefix, string $withoutPrefix): void
    {
        $sql = $this->query(null, [], ['prefix' => ''])->sql($source)->toSql();

        $this->assertSame($withoutPrefix, mb_strtolower($sql));
    }

    public function testParserDetectsCommand(): void
    {
        $parser = new Parser('second_');

        $this->assertSame('SELECT', $parser->parse('select * from ?products')['command']);
        $this->assertSame('INSERT', $parser->parse('insert into ?products(a) values(1)')['command']);
        $this->assertSame('UPDATE', $parser->parse('update ?products set a=1')['command']);
        $this->assertSame('DELETE', $parser->parse('delete from ?products where a=1')['command']);
    }

    public function testParserKeepsOriginalQuery(): void
    {
        $parser = new Parser('second_');
        $source = 'select * from ?products';

        $this->assertSame($source, $parser->parse($source)['original']);
    }

    public function testPlaceholderInBackticksIsResolvedByTableMethod(): void
    {
        $sql = $this->query('`?products`', [], ['prefix' => 'second_'])->select()->toSql();

        $this->assertSame('select * from `second_products`', mb_strtolower($sql));
    }

    /**
     * @dataProvider backtickedSqlProvider
     *
     * @param string $source
     * @param string $expected
     */
    public function testPlaceholderInBackticksIsResolvedInRawSql(string $source, string $expected): void
    {
        $sql = $this->query(null, [], ['prefix' => 'second_'])->sql($source)->toSql();

        $this->assertSame($expected, mb_strtolower($sql));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public function backtickedSqlProvider(): array
    {
        return [
            'select' => ['select * from `?products` where id=1', 'select * from `second_products` where id=1'],
            'delete' => ['delete from `?products` where id=1', 'delete from `second_products` where id=1'],
            'insert' => [
                'insert into `?products`(title) values (\'a\')',
                'insert into `second_products`(title) values (\'a\')',
            ],
            'update' => ['update `?products` set price=1', 'update `second_products` set price=1'],
            'create' => ['create table `?products`(title text)', 'create table `second_products`(title text)'],
        ];
    }

    public function testQueryWithoutPlaceholderIsLeftAlone(): void
    {
        $source = 'select * from other_table where id=1';
        $sql = $this->query(null, [], ['prefix' => 'second_'])->sql($source)->toSql();

        $this->assertSame($source, mb_strtolower($sql));
    }

    /**
     * The parser reads the statement byte by byte, so a value written in a non-ASCII
     * alphabet must survive the rewriting of the table name as it is
     */
    public function testNonAsciiValuesSurviveTheRewriting(): void
    {
        $sql = $this->query(null, [], ['prefix' => 'second_'])
            ->sql("UPDATE ?products SET title='Привет, мир', qty=5 WHERE id=1")
            ->toSql();

        $this->assertSame("UPDATE second_products SET title='Привет, мир', qty=5 WHERE id=1", $sql);
    }

    public function testNonAsciiValuesSurviveTheRewritingOfASelect(): void
    {
        $sql = $this->query(null, [], ['prefix' => 'second_'])
            ->sql("SELECT id, title FROM ?products WHERE title='Привет мир' ORDER BY id ASC LIMIT 10")
            ->toSql();

        $this->assertSame(
            "SELECT id, title FROM second_products WHERE title='Привет мир' ORDER BY id ASC LIMIT 10",
            $sql
        );
    }
}
