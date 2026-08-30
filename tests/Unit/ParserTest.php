<?php

namespace avadim\Manticore\Tests\Unit;

use avadim\Manticore\QueryBuilder\Parser;
use PHPUnit\Framework\TestCase;

/**
 * Parser static utilities: table name resolution, value formatting, quote-aware split.
 *
 * Parser::formatValue() is the single place where user values become SQL literals
 * (the builder does not bind them), so its escaping is covered here in detail.
 */
final class ParserTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: bool, 3: string}>
     */
    public function tableNameProvider(): array
    {
        return [
            'placeholder'                => ['?products', 'pre_', false, 'pre_products'],
            'placeholder in backticks'   => ['`?products`', 'pre_', false, '`pre_products`'],
            'plain name is left alone'   => ['products', 'pre_', false, 'products'],
            'plain name, force prefix'   => ['products', 'pre_', true, 'pre_products'],
            // the prefix belongs inside the backticks, not in front of them
            'backticks, force prefix'    => ['`products`', 'pre_', true, '`pre_products`'],
            'backticks, no force'        => ['`products`', 'pre_', false, '`products`'],
            'backticks placeholder'      => ['`?products`', 'pre_', true, '`pre_products`'],
            'empty prefix'               => ['?products', '', false, 'products'],
            'empty prefix, force'        => ['products', '', true, 'products'],
            'surrounding spaces trimmed' => ['  ?products  ', 'pre_', false, 'pre_products'],
        ];
    }

    /**
     * @dataProvider tableNameProvider
     *
     * @param string $name
     * @param string $prefix
     * @param bool $force
     * @param string $expected
     */
    public function testResolveTableName(string $name, string $prefix, bool $force, string $expected): void
    {
        $this->assertSame($expected, Parser::resolveTableName($name, $prefix, $force));
    }

    public function testResolveTableNameKeepsPlaceholderWhenPrefixIsNull(): void
    {
        $this->assertSame('products', Parser::resolveTableName('?products', null));
    }

    /**
     * @return array<string, array{0: mixed, 1: string|null, 2: string}>
     */
    public function formatValueProvider(): array
    {
        return [
            'string'                 => ['Samsung', 'string', "'Samsung'"],
            'text'                   => ['Samsung', 'text', "'Samsung'"],
            'string with quote'      => ["it's", 'string', "'it\\'s'"],
            'string with backslash'  => ['back\\slash', 'string', "'back\\\\slash'"],
            'string with SQL tail'   => ["x'; DROP TABLE t; --", 'string', "'x\\'; DROP TABLE t; --'"],
            'string with newline'    => ["a\nb", 'string', "'a\nb'"],
            'unicode is kept as is'  => ['Ёлка', 'string', "'Ёлка'"],
            'int'                    => [42, 'integer', '42'],
            'numeric string as int'  => ['42', 'integer', '42'],
            'bigint'                 => [9223372036854775807, 'bigint', '9223372036854775807'],
            'timestamp from int'     => [1700000000, 'timestamp', '1700000000'],
            'float'                  => [1.5, 'float', '1.5'],
            'float without fraction' => [1199.0, 'float', '1199'],
            'bool true'              => [true, 'bool', '1'],
            'bool false'             => [false, 'bool', '0'],
            'bool from int'          => [0, 'bool', '0'],
            'multi'                  => [[1, 2, 3], 'multi', '(1,2,3)'],
            'multi64'                => [[1, 2], 'multi64', '(1,2)'],
            'mva'                    => [[5, 7], 'mva', '(5,7)'],
            'empty multi'            => [[], 'multi', '()'],
            'multi casts to int'     => [['1', '2'], 'multi', '(1,2)'],
            'json object'            => [['a' => 1], 'json', '\'{\\"a\\":1}\''],
            'json list'              => [[1, 2], 'json', '\'[1,2]\''],
            'json empty'             => [[], 'json', '\'[]\''],
            'untyped null'           => [null, null, 'NULL'],
            'untyped string'         => ["it's", null, "'it\\'s'"],
            'untyped int'            => [7, null, '7'],
            'untyped bool'           => [true, null, '1'],
        ];
    }

    /**
     * @dataProvider formatValueProvider
     *
     * @param mixed $value
     * @param string|null $type
     * @param string $expected
     */
    public function testFormatValue($value, ?string $type, string $expected): void
    {
        $this->assertSame($expected, Parser::formatValue($value, $type));
    }

    /**
     * @return array<string, array{0: string|null, 1: string}>
     */
    public function emptyValueProvider(): array
    {
        return [
            'string'    => ['string', "''"],
            'text'      => ['text', "''"],
            'json'      => ['json', "''"],
            'integer'   => ['integer', '0'],
            'bigint'    => ['bigint', '0'],
            'timestamp' => ['timestamp', '0'],
            'float'     => ['float', '0'],
            'bool'      => ['bool', '0'],
            'multi'     => ['multi', '()'],
            'multi64'   => ['multi64', '()'],
            'mva'       => ['mva', '()'],
            // the type names DESCRIBE actually reports
            'uint'      => ['uint', '0'],
            'mva64'     => ['mva64', '()'],
            'unknown'   => [null, 'NULL'],
        ];
    }

    /**
     * Manticore rejects NULL in INSERT/REPLACE, so a missing value must become the empty
     * literal of its column type.
     *
     * @dataProvider emptyValueProvider
     *
     * @param string|null $type
     * @param string $expected
     */
    public function testNullBecomesEmptyValueOfItsType(?string $type, string $expected): void
    {
        $this->assertSame($expected, Parser::emptyValue($type));
        $this->assertSame($expected, Parser::formatValue(null, $type));
    }

    public function testJsonPassedAsStringIsQuoted(): void
    {
        $this->assertSame('\'{\\"a\\":1}\'', Parser::formatValue('{"a":1}', 'json'));
    }

    public function testUnparsableDateBecomesZero(): void
    {
        $this->assertSame('0', Parser::formatValue('not a date at all', 'timestamp'));
    }

    public function testFormatValueKeepsUnicodeUnescapedInJson(): void
    {
        $this->assertSame('\'{\\"name\\":\\"Ёлка\\"}\'', Parser::formatValue(['name' => 'Ёлка'], 'json'));
    }

    public function testFormatValueParsesDateStringForTimestamp(): void
    {
        $expected = (string)strtotime('2020-01-02 03:04:05');
        $this->assertSame($expected, Parser::formatValue('2020-01-02 03:04:05', 'timestamp'));
    }

    public function testFormatArrayOfStringsQuotesEveryItem(): void
    {
        $this->assertSame("('a','b')", Parser::formatArray(['a', 'b'], 'string'));
    }

    public function testFormatScalarEscapesObjectsAsStrings(): void
    {
        $object = new class {
            public function __toString(): string
            {
                return "it's";
            }
        };

        $this->assertSame("'it\\'s'", Parser::formatScalar($object, 'object'));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string[]}>
     */
    public function explodeProvider(): array
    {
        return [
            'plain list'            => [',', 'a, b, c', ['a', 'b', 'c']],
            'nested parentheses'    => [',', 'a, b(1,2), c', ['a', 'b(1,2)', 'c']],
            'quoted separator'      => [',', "a, 'c,d'", ['a', "'c,d'"]],
            'double quotes'         => [',', 'a, "c,d"', ['a', '"c,d"']],
            'nested braces'         => [',', 'a, {x,y}, b', ['a', '{x,y}', 'b']],
            'nested brackets'       => [',', 'a, [1,2], b', ['a', '[1,2]', 'b']],
            'function with args'    => [',', 'id, IN(color, 1, 2) as f', ['id', 'IN(color, 1, 2) as f']],
            'single item'           => [',', 'id', ['id']],
        ];
    }

    /**
     * @dataProvider explodeProvider
     *
     * @param string $separator
     * @param string $expression
     * @param string[] $expected
     */
    public function testExplode(string $separator, string $expression, array $expected): void
    {
        $this->assertSame($expected, Parser::explode($separator, $expression, true));
    }

    /**
     * An unmatched closing bracket must not open a level of its own - otherwise every
     * separator after it is swallowed.
     */
    public function testExplodeIgnoresUnmatchedClosingBrackets(): void
    {
        $this->assertSame(['a', '))b', 'c'], Parser::explode(',', 'a, ))b, c', true));
        $this->assertSame(['a', 'b]', 'c'], Parser::explode(',', 'a, b], c', true));
    }

    public function testExplodeKeepsTailOfUnclosedOpeningBracket(): void
    {
        // an opening bracket that is never closed still swallows the rest, as before
        $this->assertSame(['a', '((b, c'], Parser::explode(',', 'a, ((b, c', true));
    }

    public function testExplodeWithoutTrimKeepsSpaces(): void
    {
        $this->assertSame(['a', ' b'], Parser::explode(',', 'a, b'));
    }

    /**
     * The split walks the string byte by byte, so its length has to be counted in bytes:
     * with mb_strlen() every non-ASCII character used to cut one byte off the tail
     */
    public function testExplodeKeepsEveryItemOfANonAsciiExpression(): void
    {
        $this->assertSame(
            ['id', "'Привет, мир' as greeting", 'price'],
            Parser::explode(',', "id, 'Привет, мир' as greeting, price", true)
        );
    }

    public function testColumnListKeepsNonAsciiLiteralsWhole(): void
    {
        $this->assertSame(["title", "'Ёлка' as label"], Parser::columnList(["title, 'Ёлка' as label"]));
    }

    public function testTrimRemovesWhitespaceAndExtraChars(): void
    {
        $this->assertSame('SELECT 1', Parser::trim("  SELECT 1;\n", ';'));
        $this->assertSame('SELECT 1', Parser::trim("\tSELECT 1  "));
    }
}
