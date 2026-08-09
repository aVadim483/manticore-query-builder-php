<?php

namespace avadim\Manticore\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the library against "Implicitly marking parameter as nullable" - a typed parameter
 * with a null default and no "?" in its type. PHP 8.4 deprecates it, 9.0 makes it an error.
 *
 * The check reads the sources instead of using reflection, because reflection reports both
 * "?string $x = null" and "string $x = null" as nullable and cannot tell them apart. Reading
 * the sources also keeps the test meaningful on PHP versions that do not warn at all.
 *
 * PHPUnit would not catch the notice by itself either: it is emitted while the class is being
 * compiled, outside of any test, so a green run is no proof on its own.
 */
final class SignatureCompatibilityTest extends TestCase
{
    /**
     * @return array<string, array{0: string}> file name => [path]
     */
    public function sourceFileProvider(): array
    {
        $root = dirname(__DIR__, 2) . '/src';
        $files = array_merge(
            glob($root . '/Manticore/QueryBuilder/*.php') ?: [],
            glob($root . '/Manticore/QueryBuilder/*/*.php') ?: []
        );

        $result = [];
        foreach ($files as $file) {
            $result[substr($file, strlen($root) + 1)] = [$file];
        }
        self::assertNotEmpty($result, 'no library sources found');

        return $result;
    }

    /**
     * @dataProvider sourceFileProvider
     *
     * @param string $file
     */
    public function testNoImplicitlyNullableParameters(string $file): void
    {
        $code = file_get_contents($file);
        $this->assertNotFalse($code, 'cannot read ' . $file);

        $offenders = [];
        if (preg_match_all('/function\s+(\w+)\s*\(([^)]*)\)/s', $code, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                foreach (explode(',', $match[2]) as $parameter) {
                    // a leading "?" makes the first character non-alphabetic, so a nullable
                    // type never matches here
                    if (preg_match('/^\s*([A-Za-z_\\\\][\w\\\\]*)\s+&?\$(\w+)\s*=\s*null\s*$/i', $parameter, $found)) {
                        $offenders[] = sprintf('%s(%s $%s = null)', $match[1], $found[1], $found[2]);
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'A parameter defaulting to null must be typed as nullable, e.g. "?string $x = null"'
        );
    }
}
