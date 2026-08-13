<?php

declare(strict_types=1);

namespace avadim\Manticore\QueryBuilder\Schema;

class SchemaColumn
{
    private string $name;
    private string $type;
    private ?string $engine = null;
    private ?string $fastFetch = null;
    private array $options = [];


    public function __construct(string $name, string $type, ?array $options = [])
    {
        $this->name = $name;
        $this->type = $type;
        if ($options) {
            $this->options($options);
        }
    }

    /**
     * @param array $options
     *
     * @return $this
     */
    public function options(array $options): SchemaColumn
    {
        if (isset($options['engine'])) {
            $this->engine($options['engine']);
            unset($options['engine']);
        }
        if (isset($options['fast_fetch'])) {
            $this->fastFetch((string)((int)$options['fast_fetch']));
            unset($options['fast_fetch']);
        }
        $this->options = $options;

        return $this;
    }

    /**
     * @param $engine
     *
     * @return $this
     */
    public function columnEngine($engine): SchemaColumn
    {
        $this->engine = $engine;

        return $this;
    }

    /**
     * @param $engine
     *
     * @return $this
     */
    public function engine($engine): SchemaColumn
    {
        return $this->columnEngine($engine);
    }

    /**
     * @return $this
     */
    public function columnar(): SchemaColumn
    {

        return $this->columnEngine('columnar');
    }

    /**
     * @return $this
     */
    public function rowwise(): SchemaColumn
    {

        return $this->columnEngine('rowwise');
    }

    /**
     * @param $fastFetch
     *
     * @return $this
     */
    public function fastFetch($fastFetch): SchemaColumn
    {
        $this->fastFetch = $fastFetch;

        return $this;
    }

    /**
     * @return $this
     */
    public function stored(): SchemaColumn
    {
        $this->options[] = 'stored';

        return $this;
    }

    /**
     * @return $this
     */
    public function attribute(): SchemaColumn
    {
        $this->options[] = 'attribute';

        return $this;
    }

    /**
     * @return $this
     */
    public function indexed(): SchemaColumn
    {
        $this->options[] = 'indexed';

        return $this;
    }

    /**
     * @param bool $fastFetch render the fast_fetch option too
     *
     * @return string
     */
    private function render(bool $fastFetch): string
    {
        $column = trim($this->name . ' ' . $this->type);
        if ($this->engine) {
            $column .= ' engine=\'' . $this->engine . '\'';
        }
        if ($fastFetch && $this->fastFetch !== null) {
            $column .= ' fast_fetch=\'' . $this->fastFetch . '\'';
        }
        foreach ($this->options as $key => $val) {
            if (is_int($key)) {
                $column .= ' ' . $val;
            }
            else {
                $column .= ' ' . $key . '=\'' . $val . '\'';
            }
        }

        return $column;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->render(true);
    }

    /**
     * Column definition for ALTER TABLE ... ADD COLUMN
     *
     * The grammar of ADD COLUMN is narrower than the one of CREATE TABLE: it takes the type,
     * engine='columnar' and the text flags (indexed/stored/attribute), but knows no fast_fetch,
     * so that option is left out here instead of being sent and rejected by the server.
     *
     * @return string
     */
    public function alterDefinition(): string
    {
        return $this->render(false);
    }

    /**
     * @param string $name
     * @param string|array $type
     * @param string|array|null $options
     *
     * @return SchemaColumn
     */
    public static function define(string $name, $type, $options = null): SchemaColumn
    {
        // options may come as a string of flags, e.g. text('title', 'indexed stored')
        if (is_string($options)) {
            $options = ($options = trim($options)) === '' ? [] : preg_split('/\s+/', $options);
        }
        if (is_array($type)) {
            if ($options) {
                $options = array_replace_recursive((array)$options, $type);
            }
            else {
                $options = $type;
            }
            if (isset($options['type'])) {
                $type = $options['type'];
                unset($options['type']);
            }
        }

        return new self($name, $type, $options);
    }
}