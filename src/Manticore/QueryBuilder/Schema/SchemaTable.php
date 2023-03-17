<?php

declare(strict_types=1);

namespace avadim\Manticore\QueryBuilder\Schema;

class SchemaTable
{
    private string $engine = '';

    private array $morphology = [];

    private array $columns = [];

    private array $options = [];


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
        if (!$options) {
            $options = [];
        }
        if (is_string($type) && strpos($type, ' ')) {
            $addOptions = explode(' ', $type);
            $type = array_shift($addOptions);
            $options = array_replace_recursive($addOptions, (array)$options);
        }
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

        $result = '(' . implode(', ', $columns) . ')';
        if ($this->engine) {
            $result .= ' engine=\'' . $this->engine . '\'';
        }
        if ($this->morphology) {
            $result .= ' morphology=\'' . implode(',', $this->morphology) . '\'';
        }
        if ($this->options) {
            foreach ($this->options as $name => $value) {
                if (is_array($value)) {
                    $value = implode(',', $value);
                }
                $result .= ' ' . (!is_int($name) ? $name . '=' : '') . '\'' . addslashes($value) . '\'';
            }
        }

        return $result;
    }

    /**
     * Set engine for the table schema
     *
     * @param string $engine
     *
     * @return $this
     */
    public function tableEngine(string $engine): SchemaTable
    {
        $this->engine = $engine;

        return $this;
    }

    /**
     * Set morphology for the table schema
     *
     * @param array|string $morphology
     *
     * @return $this
     */
    public function tableMorphology($morphology): SchemaTable
    {
        $this->morphology = (array)$morphology;

        return $this;
    }

    /**
     * Set morphology for the table schema (alias of tableMorphology())
     *
     * @param array|string $morphology
     *
     * @return $this
     */
    public function morphology($morphology): SchemaTable
    {
        $this->tableMorphology($morphology);

        return $this;
    }

    /**
     * @param array $options
     *
     * @return $this
     */
    public function tableOptions(array $options): SchemaTable
    {
        $this->options = [];
        foreach ($options as $name => $value) {
            $value = (string)$value;
            if ($name === 'engine') {
                $this->tableEngine($value);
            }
            elseif ($name === 'morphology') {
                $this->tableMorphology($value);
            }
            elseif (is_int($name)) {
                if (strpos($value, '=') && $value[0] !== '\'' && $value[0] !== '\"') {
                    [$key, $val] = explode('=', $value, 2);
                    $this->options[$key] = trim($val, '\'" ');
                }
                else {
                    $this->options[] = $value;
                }
            }
            else {
                $this->options[$name] = trim($value, '\'"');
            }
        }

        return $this;
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