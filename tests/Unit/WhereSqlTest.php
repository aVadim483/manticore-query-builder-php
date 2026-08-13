<?php

namespace avadim\Manticore\Tests\Unit;

use avadim\Manticore\Tests\Support\UnitTestCase;

/**
 * WHERE tree rendering: every condition is wrapped in parentheses and glued
 * by the boolean of the *next* operand.
 */
final class WhereSqlTest extends UnitTestCase
{
    public function testWhereEquals(): void
    {
        $this->assertSqlSame(
            'SELECT * FROM products WHERE (price=100)',
            $this->query()->where('price', 100)->toSql()
        );
    }

    public function testWhereWithOperator(): void
    {
        $this->assertSqlSame(
            'SELECT * FROM products WHERE (price>100)',
            $this->query()->where('price', '>', 100)->toSql()
        );
    }

    public function testWhereQuotesAndEscapesStringValue(): void
    {
        $this->assertSqlSame(
            "SELECT * FROM products WHERE (manufacturer='O\\'Neill')",
            $this->query()->where('manufacturer', "O'Neill")->toSql()
        );
    }

    public function testWhereEscapesInjectionAttempt(): void
    {
        $sql = $this->query()->where('title', "x'; DROP TABLE products; --")->toSql();

        $this->assertSqlSame(
            "SELECT * FROM products WHERE (title='x\\'; DROP TABLE products; --')",
            $sql
        );
        // the closing quote of the literal must still be the last one
        $this->assertSame(2, substr_count($sql, "'") - substr_count($sql, "\\'"));
    }

    public function testWhereRendersBooleanAsInt(): void
    {
        $this->assertSqlSame(
            'SELECT * FROM products WHERE (on_sale=1)',
            $this->query()->where('on_sale', true)->toSql()
        );
        $this->assertSqlSame(
            'SELECT * FROM products WHERE (on_sale=0)',
            $this->query()->where('on_sale', false)->toSql()
        );
    }

    public function testWhereKeepsRawExpression(): void
    {
        $this->assertSqlSame(
            'SELECT * FROM products WHERE (DOUBLE(info.price)>250)',
            $this->query()->where('DOUBLE(info.price)>250')->toSql()
        );
    }

    public function testConditionsAreGluedWithAnd(): void
    {
        $this->assertSqlSame(
            'SELECT * FROM products WHERE (a=1)AND(b=2)',
            $this->query()->where('a', 1)->where('b', 2)->toSql()
        );
    }

    public function testAndWhereIsSameAsWhere(): void
    {
        $this->assertSame(
            $this->query()->where('a', 1)->where('b', 2)->toSql(),
            $this->query()->where('a', 1)->andWhere('b', 2)->toSql()
        );
    }

    public function testOrWhere(): void
    {
        $this->assertSqlSame(
            'SELECT * FROM products WHERE (a=1)OR(b=2)',
            $this->query()->where('a', 1)->orWhere('b', 2)->toSql()
        );
    }

    public function testWhereIn(): void
    {
        $this->assertSqlSame(
            "SELECT * FROM products WHERE (country IN('de','us'))",
            $this->query()->whereIn('country', ['de', 'us'])->toSql()
        );
    }

    public function testWhereInWithNumbers(): void
    {
        $this->assertSqlSame(
            'SELECT * FROM products WHERE (id IN(1,2,3))',
            $this->query()->whereIn('id', [1, 2, 3])->toSql()
        );
    }

    public function testWhereNotIn(): void
    {
        $this->assertSqlSame(
            "SELECT * FROM products WHERE (country NOT IN('de','us'))",
            $this->query()->whereNotIn('country', ['de', 'us'])->toSql()
        );
    }

    public function testAndWhereIn(): void
    {
        $this->assertSqlSame(
            "SELECT * FROM products WHERE (a=1)AND(country IN('de'))",
            $this->query()->where('a', 1)->andWhereIn('country', ['de'])->toSql()
        );
    }

    public function testOrWhereIn(): void
    {
        $this->assertSqlSame(
            'SELECT * FROM products WHERE (a=1)OR(id IN(1,2))',
            $this->query()->where('a', 1)->orWhereIn('id', [1, 2])->toSql()
        );
    }

    public function testAndWhereNotIn(): void
    {
        $this->assertSqlSame(
            'SELECT * FROM products WHERE (a=1)AND(id NOT IN(1))',
            $this->query()->where('a', 1)->andWhereNotIn('id', [1])->toSql()
        );
    }

    public function testOrWhereNotIn(): void
    {
        $this->assertSqlSame(
            'SELECT * FROM products WHERE (a=1)OR(id NOT IN(1))',
            $this->query()->where('a', 1)->orWhereNotIn('id', [1])->toSql()
        );
    }

    public function testWhereNull(): void
    {
        $this->assertSqlSame(
            'SELECT * FROM products WHERE (info.x IS NULL)',
            $this->query()->whereNull('info.x')->toSql()
        );
    }

    public function testWhereNotNull(): void
    {
        $this->assertSqlSame(
            'SELECT * FROM products WHERE (info.x IS NOT NULL)',
            $this->query()->whereNotNull('info.x')->toSql()
        );
    }

    public function testAndWhereNull(): void
    {
        $this->assertSqlSame(
            'SELECT * FROM products WHERE (a=1)AND(info.x IS NULL)',
            $this->query()->where('a', 1)->andWhereNull('info.x')->toSql()
        );
    }

    public function testOrWhereNull(): void
    {
        $this->assertSqlSame(
            'SELECT * FROM products WHERE (a=1)OR(info.x IS NULL)',
            $this->query()->where('a', 1)->orWhereNull('info.x')->toSql()
        );
    }

    public function testAndWhereNotNull(): void
    {
        $this->assertSqlSame(
            'SELECT * FROM products WHERE (a=1)AND(info.x IS NOT NULL)',
            $this->query()->where('a', 1)->andWhereNotNull('info.x')->toSql()
        );
    }

    public function testOrWhereNotNull(): void
    {
        $this->assertSqlSame(
            'SELECT * FROM products WHERE (a=1)OR(info.x IS NOT NULL)',
            $this->query()->where('a', 1)->orWhereNotNull('info.x')->toSql()
        );
    }

    /**
     * The two-argument form is the shortcut for "=", including when the value happens to read
     * like an operator: use whereNull() for the unary one.
     */
    public function testTwoArgumentWhereTreatsIsNullAsAValue(): void
    {
        $this->assertSqlSame(
            "SELECT * FROM products WHERE (title='IS NULL')",
            $this->query()->where('title', 'IS NULL')->toSql()
        );
        $this->assertSqlSame(
            "SELECT * FROM products WHERE (title='IS NULL')",
            $this->query()->where('title', '=', 'IS NULL')->toSql()
        );
    }

    public function testNullValueMeansIsNull(): void
    {
        // both the two- and the three-argument form: passing null asks for IS NULL, it does
        // not turn the operator into a value
        $this->assertSqlSame(
            'SELECT * FROM products WHERE (title IS NULL)',
            $this->query()->where('title', null)->toSql()
        );
        $this->assertSqlSame(
            'SELECT * FROM products WHERE (title IS NULL)',
            $this->query()->where('title', '=', null)->toSql()
        );
    }

    public function testNullValueWithNotEqualsMeansIsNotNull(): void
    {
        $this->assertSqlSame(
            'SELECT * FROM products WHERE (title IS NOT NULL)',
            $this->query()->where('title', '!=', null)->toSql()
        );
        $this->assertSqlSame(
            'SELECT * FROM products WHERE (title IS NOT NULL)',
            $this->query()->where('title', '<>', null)->toSql()
        );
    }

    public function testNullValueWithAnOrderingOperatorIsRejected(): void
    {
        // "price > null" has no meaning, and silently comparing against the string "null"
        // or against the operator itself is worse than saying so
        $this->expectException(\InvalidArgumentException::class);

        $this->query()->where('price', '>', null);
    }

    public function testWhereFromArrayOfPairs(): void
    {
        $this->assertSqlSame(
            "SELECT * FROM products WHERE ((color='red')AND(price=10))",
            $this->query()->where(['color' => 'red', 'price' => 10])->toSql()
        );
    }

    public function testWhereFromArrayOfConditions(): void
    {
        $this->assertSqlSame(
            "SELECT * FROM products WHERE ((price>10)AND(color='red'))",
            $this->query()->where([['price', '>', 10], ['color', 'red']])->toSql()
        );
    }

    public function testWhereFromArrayKeepsNullAsIsNull(): void
    {
        $this->assertSqlSame(
            "SELECT * FROM products WHERE ((color='red')AND(title IS NULL))",
            $this->query()->where(['color' => 'red', 'title' => null])->toSql()
        );
    }

    public function testOrWhereFromArray(): void
    {
        $this->assertSqlSame(
            "SELECT * FROM products WHERE (a=1)OR((color='red')AND(price=10))",
            $this->query()->where('a', 1)->orWhere(['color' => 'red', 'price' => 10])->toSql()
        );
    }

    public function testWhereBetween(): void
    {
        $this->assertSqlSame(
            'SELECT * FROM products WHERE (price BETWEEN 10 AND 20)',
            $this->query()->whereBetween('price', [10, 20])->toSql()
        );
    }

    public function testAndWhereBetween(): void
    {
        $this->assertSqlSame(
            'SELECT * FROM products WHERE (a=1)AND(price BETWEEN 10 AND 20)',
            $this->query()->where('a', 1)->andWhereBetween('price', [10, 20])->toSql()
        );
    }

    public function testAndWhereNotBetween(): void
    {
        $this->assertSqlSame(
            'SELECT * FROM products WHERE (a=1)AND(price NOT BETWEEN 10 AND 20)',
            $this->query()->where('a', 1)->andWhereNotBetween('price', [10, 20])->toSql()
        );
    }

    public function testWhereNotBetween(): void
    {
        $this->assertSqlSame(
            'SELECT * FROM products WHERE (price NOT BETWEEN 10 AND 20)',
            $this->query()->whereNotBetween('price', [10, 20])->toSql()
        );
    }

    public function testOrWhereBetween(): void
    {
        $this->assertSqlSame(
            'SELECT * FROM products WHERE (a=1)OR(price BETWEEN 10 AND 20)',
            $this->query()->where('a', 1)->orWhereBetween('price', [10, 20])->toSql()
        );
    }

    public function testOrWhereNotBetween(): void
    {
        $this->assertSqlSame(
            'SELECT * FROM products WHERE (a=1)OR(price NOT BETWEEN 10 AND 20)',
            $this->query()->where('a', 1)->orWhereNotBetween('price', [10, 20])->toSql()
        );
    }

    public function testNestedConditionsAreWrappedInParentheses(): void
    {
        $sql = $this->query()
            ->where(function ($condition) {
                $condition->where('country', 'de');
                $condition->orWhere('price', '>', 150);
            })
            ->toSql();

        $this->assertSqlSame("SELECT * FROM products WHERE ((country='de')OR(price>150))", $sql);
    }

    public function testNestedConditionsOfferTheWholeWhereFamily(): void
    {
        // the closure gets the same vocabulary as the builder itself
        $sql = $this->query()
            ->where(function ($condition) {
                $condition->whereIn('country', ['de', 'us']);
                $condition->orWhereNull('info.x');
                $condition->orWhereBetween('price', [10, 20]);
            })
            ->toSql();

        $this->assertSqlSame(
            "SELECT * FROM products WHERE ((country IN('de','us'))OR(info.x IS NULL)OR(price BETWEEN 10 AND 20))",
            $sql
        );
    }

    public function testNestedConditionsUnderstandNullValues(): void
    {
        $sql = $this->query()
            ->where(function ($condition) {
                $condition->where('title', null);
                $condition->orWhere('price', '=', null);
            })
            ->toSql();

        $this->assertSqlSame('SELECT * FROM products WHERE ((title IS NULL)OR(price IS NULL))', $sql);
    }

    public function testNestedConditionsFromArray(): void
    {
        $sql = $this->query()
            ->where('x', 0)
            ->orWhere(function ($condition) {
                $condition->where(['a' => 1, 'b' => 2]);
            })
            ->toSql();

        $this->assertSqlSame('SELECT * FROM products WHERE (x=0)OR((a=1)AND(b=2))', $sql);
    }

    public function testNestedConditionsAfterPlainCondition(): void
    {
        $sql = $this->query()
            ->where('x', 0)
            ->where(function ($condition) {
                $condition->where('a', 1);
                $condition->orWhere('b', 2);
            })
            ->toSql();

        $this->assertSqlSame('SELECT * FROM products WHERE (x=0)AND((a=1)OR(b=2))', $sql);
    }

    public function testMatchGoesToWhereClause(): void
    {
        $this->assertSqlSame(
            "SELECT * FROM products WHERE MATCH('galaxy')",
            $this->query()->match('galaxy')->toSql()
        );
    }

    public function testMatchEscapesQuotes(): void
    {
        $this->assertSqlSame(
            "SELECT * FROM products WHERE MATCH('it\\'s')",
            $this->query()->match("it's")->toSql()
        );
    }

    public function testSingleConditionIsAppendedToMatchWithoutExtraBraces(): void
    {
        $this->assertSqlSame(
            "SELECT * FROM products WHERE MATCH('galaxy') AND (price>100)",
            $this->query()->match('galaxy')->where('price', '>', 100)->toSql()
        );
    }

    public function testSeveralConditionsAreGroupedAfterMatch(): void
    {
        $this->assertSqlSame(
            "SELECT * FROM products WHERE MATCH('galaxy') AND ((a=1)AND(b=2))",
            $this->query()->match('galaxy')->where('a', 1)->where('b', 2)->toSql()
        );
    }

    public function testNamedParameterIsSubstitutedIntoCondition(): void
    {
        $sql = $this->query()
            ->where('country=:country')
            ->bind([':country' => 'de'])
            ->toSql();

        $this->assertSqlSame('SELECT * FROM products WHERE (country=de)', $sql);
    }

    public function testNamedParameterIsNotQuotedAsValue(): void
    {
        // a value that looks like a placeholder is passed through, not quoted
        $this->assertSqlSame(
            'SELECT * FROM products WHERE (country=:country)',
            $this->query()->where('country', ':country')->toSql()
        );
    }
}
