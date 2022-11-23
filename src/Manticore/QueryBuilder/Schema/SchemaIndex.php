<?php

declare(strict_types=1);

namespace avadim\Manticore\QueryBuilder\Schema;

class SchemaIndex
{
    private array $columns = [];


    public function __construct(?array $data = [])
    {
        if ($data) {
            foreach($data as $name => $colData) {
                $type = $colData['type'];
                unset($colData['type']);
                $options = $colData['options'] ?? $colData;
                $this->addColumn($name, $type, $options);
            }
        }
    }

    /**
     * @param string $name
     * @param string|array $type
     * @param string|array $options
     *
     * @return SchemaColumn
     */
    public function addColumn(string $name, $type, $options = null): SchemaColumn
    {
        $this->columns[$name] = SchemaColumn::define($name, $type, $options);

        return $this->columns[$name];
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        $columns = [];
        foreach ($this->columns as $column) {
            $columns[] = (string)$column;
        }

        return implode(', ', $columns);
    }

    /**
     * Text columns are indexed and can be searched for keywords. Full-text columns can only be used in MATCH() clause
     * and cannot be used for sorting or aggregation
     *
     * Options: indexed, stored. Default - both. To keep text stored, but indexed specify "stored" only.
     * To keep text indexed only specify "indexed".
     *
     * @param string $name
     * @param string|array $options
     *
     * @return SchemaColumn
     */
    public function text(string $name, $options = null): SchemaColumn
    {
        return $this->addColumn($name, 'text', $options);
    }

    /**
     * Option: indexed - also index the strings in a full-text field with same name.
     *
     * @param string $name
     * @param string|array $options
     *
     * @return SchemaColumn
     */
    public function string(string $name, $options = null): SchemaColumn
    {
        return $this->addColumn($name, 'string', $options);
    }

    /**
     * @param string $name
     * @param string|array $options
     *
     * @return SchemaColumn
     */
    public function integer(string $name, $options = null): SchemaColumn
    {
        return $this->addColumn($name, 'integer', $options);
    }

    /**
     * @param string $name
     * @param string|array $options
     *
     * @return SchemaColumn
     */
    public function bigint(string $name, $options = null): SchemaColumn
    {
        return $this->addColumn($name, 'bigint', $options);
    }

    /**
     * @param string $name
     * @param string|array $options
     *
     * @return SchemaColumn
     */
    public function float(string $name, $options = null): SchemaColumn
    {
        return $this->addColumn($name, 'float', $options);
    }

    /**
     * @param string $name
     * @param string|array $options
     *
     * @return SchemaColumn
     */
    public function multi(string $name, $options = null): SchemaColumn
    {
        return $this->addColumn($name, 'multi', $options);
    }

    /**
     * @param string $name
     * @param string|array $options
     *
     * @return SchemaColumn
     */
    public function multi64(string $name, $options = null): SchemaColumn
    {
        return $this->addColumn($name, 'multi64', $options);
    }

    /**
     * @param string $name
     * @param string|array $options
     *
     * @return SchemaColumn
     */
    public function bool(string $name, $options = null): SchemaColumn
    {
        return $this->addColumn($name, 'bool', $options);
    }

    /**
     * @param string $name
     * @param string|array $options
     *
     * @return SchemaColumn
     */
    public function json(string $name, $options = null): SchemaColumn
    {
        return $this->addColumn($name, 'json', $options);
    }

    /**
     * @param string $name
     * @param string|array $options
     *
     * @return SchemaColumn
     */
    public function timestamp(string $name, $options = null): SchemaColumn
    {
        return $this->addColumn($name, 'timestamp', $options);
    }

}