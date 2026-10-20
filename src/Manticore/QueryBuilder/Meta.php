<?php

declare(strict_types=1);

namespace avadim\Manticore\QueryBuilder;

/** Immutable metadata of a search. Unknown server fields remain available by key. */
final class Meta implements \ArrayAccess, \IteratorAggregate, \Countable
{
    private array $values;

    public function __construct(array $values = [])
    {
        $this->values = $values;
    }

    /** Normalize the rows returned by SHOW META. */
    public static function fromRows(array $rows): self
    {
        $values = [];
        foreach ($rows as $row) {
            $name = $row['Variable_name'];
            $value = $row['Value'];
            if ($name === 'time') {
                $value = (float)$value;
            }
            elseif (in_array($name, ['total', 'total_found'], true)
                || in_array(substr($name, 0, 5), ['docs[', 'hits['], true)) {
                $value = (int)$value;
            }
            $values[$name] = $value;
        }

        return new self($values);
    }

    public function totalFound(): ?int
    {
        return isset($this->values['total_found']) ? (int)$this->values['total_found'] : null;
    }

    public function total(): ?int
    {
        return isset($this->values['total']) ? (int)$this->values['total'] : null;
    }

    public function time(): ?float
    {
        return isset($this->values['time']) ? (float)$this->values['time'] : null;
    }

    public function toArray(): array
    {
        return $this->values;
    }

    public function offsetExists($offset): bool
    {
        return isset($this->values[$offset]);
    }

    /** @return mixed|null */
    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->values[$offset] ?? null;
    }

    public function offsetSet($offset, $value): void
    {
        throw new \LogicException('Search metadata is read-only.');
    }

    public function offsetUnset($offset): void
    {
        throw new \LogicException('Search metadata is read-only.');
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->values);
    }

    /** Number of metadata fields, not documents. */
    public function count(): int
    {
        return count($this->values);
    }
}
