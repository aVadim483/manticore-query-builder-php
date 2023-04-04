<?php

declare(strict_types=1);

namespace avadim\Manticore\QueryBuilder;

use Illuminate\Support\Collection;

class ResultSet
{
    private ?string $command;
    private ?string $sqlQuery;
    private ?string $status = '';
    private ?string $error = null;
    private ?float $execTime;
    private array $meta;
    private array $facets;

    private string $resultType;
    private $resultData;
    private array $columns;
    private array $variables = [];


    /**
     * @param $data
     * @param string|null $status
     */
    public function __construct($data, ?string $status = null)
    {
        $this->command = $data['command'] ?? null;
        $this->sqlQuery = $data['query'] ?? null;
        $this->execTime = $data['exec_time'] ?? null;
        $this->meta = $data['meta'] ?? [];
        $this->facets = $data['facets'] ?? [];

        $this->resultType = $data['result']['type'] ?? '';
        $this->resultData = $data['result']['data'] ?? null;
        if ($this->resultType === 'collection' || $this->resultType === 'array') {
            if (is_array($this->resultData)) {
                $row = reset($this->resultData);
            }
            else {
                $row = $this->first();
            }
            $this->columns = ($row && is_array($row)) ? array_keys($row) : [];
            foreach ($this->resultData as $item) {
                if (isset($item['Variable_name'], $item['Value'])) {
                    $this->setVariable($item['Variable_name'], $item['Value']);
                    if ($item['Variable_name'] === 'settings' && $item['Value']) {
                        foreach (explode("\n", $item['Value']) as $var) {
                            [$k, $v] = array_map('trim', explode('=', $var, 2));
                            $this->setVariable($k, $v);
                        }
                    }
                }
            }
        }
        else {
            $this->columns = [];
        }

        if (!empty($data['response']['error'])) {
            $this->status = 'error';
            $this->error = $data['response']['error'];
        }
        elseif ($status) {
            $this->status = $status;
        }
        else {
            $this->status = 'done';
        }
    }

    /**
     * @param string $name
     * @param mixed $value
     *
     * @return void
     */
    protected function setVariable(string $name, $value)
    {
        if (preg_match('#^(-)?\d+$#', $value)) {
            $this->variables[$name] = (int)$value;
        }
        else {
            $this->variables[$name] = $value;
        }
    }

    /**
     * Returns command
     *
     * @return string|null
     */
    public function command(): ?string
    {
        return $this->command;
    }

    /**
     * Returns SQL query
     *
     * @return string|null
     */
    public function sqlQuery(): ?string
    {
        return $this->sqlQuery;
    }

    /**
     * Returns execution time
     *
     * @return float|null
     */
    public function execTime(): ?float
    {
        return $this->execTime;
    }

    /**
     * Returns array of columns names
     *
     * @return string[]
     */
    public function columns(): array
    {
        return $this->columns;
    }

    /**
     * Collection of rows
     * or Array
     * or Bigint
     * or Boolean
     *
     * @return mixed|null
     */
    public function result()
    {
        return $this->resultData;
    }

    /**
     * Returns count of result rows from SELECT
     *
     * @return int
     */
    public function count(): int
    {
        return $this->meta['total'] ? (int)$this->meta['total'] : 0;
    }

    /**
     * Returns total number of rows that match the condition in table
     *
     * @return int
     */
    public function total(): int
    {
        return $this->meta['total_found'] ? (int)$this->meta['total_found'] : 0;
    }

    /**
     * Returns the first row of rows set
     *
     * @return mixed|null
     */
    public function first()
    {
        if (!empty($this->resultData)) {
            if (is_array($this->resultData)) {
                return reset($this->resultData);
            }
            elseif ($this->resultData instanceof Collection) {
                return $this->resultData->first();
            }
        }

        return null;
    }

    /**
     * Returns the metadata received after SQL request
     *
     * @return array
     */
    public function meta(): array
    {
        return $this->meta ?? [];
    }

    /**
     * Returns facets
     *
     * @param int|null $key
     *
     * @return array
     */
    public function facets(int $key = null): array
    {
        $facets = $this->facets ? array_column($this->facets, 'data') : [];
        if ($key === null) {
            return $facets;
        }

        return $facets[$key];
    }

    /**
     * @param string $type
     * @param mixed $data
     */
    public function setResult(string $type, $data)
    {
        $this->resultType = $type;
        $this->resultData = $data;
    }

    /**
     * Result without errors and warnings
     *
     * @return bool
     */
    public function success(): bool
    {
        return empty($this->data['response']['error']) && empty($this->data['response']['warning']);
    }

    /**
     * The last result of query
     *
     * @return string
     */
    public function status(): ?string
    {
        return $this->status;
    }

    /**
     * @param $name
     *
     * @return mixed|null
     */
    public function variable($name)
    {
        return $this->variables[$name] ?? null;
    }

    /**
     * @return array
     */
    public function variables(): array
    {
        return $this->variables;
    }
}
