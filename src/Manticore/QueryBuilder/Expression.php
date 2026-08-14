<?php

declare(strict_types=1);

namespace avadim\Manticore\QueryBuilder;

/**
 * A piece of SQL to be used where a value is expected, without being quoted or escaped.
 *
 *      where('price', '>', ManticoreDb::raw('qty * 2'))
 *
 * Everything else a value goes through - quoting, escaping - is skipped for it, so whatever is
 * put in here is the caller's responsibility, exactly as with the raw() of the Laravel query
 * builder.
 */
class Expression
{
    /**
     * @var string
     */
    private $value;

    /**
     * @param string $value
     */
    public function __construct(string $value)
    {
        $this->value = $value;
    }

    /**
     * @return string
     */
    public function value(): string
    {
        return $this->value;
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
