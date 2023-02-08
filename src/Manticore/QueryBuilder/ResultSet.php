<?php

declare(strict_types=1);

namespace avadim\Manticore\QueryBuilder;

use Illuminate\Support\Collection;

class ResultSet
{
    private ?string $command;
    private ?string $sqlQuery;
    private ?string $status = '';
    private ?float $execTime;
    private array $meta;
    private array $facets;

    private string $resultType;
    private $resultData;
    private array $columns;
    private array $variables;


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

        $this->resultType = $data['result']['type'];
        $this->resultData = $data['result']['data'];
        if ($this->resultType === 'collection' || $this->resultType === 'array') {
            if (is_array($this->resultData)) {
                $row = reset($this->resultData);
            }
            else {
                $row = $this->first();
            }
            $this->columns = $row ? array_keys($row) : [];
            foreach ($this->resultData as $item) {
                if (isset($item['Variable_name'], $item['Value'])) {
                    $this->variables[$item['Variable_name']] = $item['Value'];
                }
            }
        }
        else {
            $this->columns = [];
        }

        if (!empty($data['response']['error'])) {
            $this->status = 'error';
        }
        elseif ($status) {
            $this->status = $status;
        }
        else {
            $this->status = 'done';
        }
    }

    /**
     * @return string|null
     */
    public function command(): ?string
    {
        return $this->command;
    }

    /**
     * @return string|null
     */
    public function sqlQuery(): ?string
    {
        return $this->sqlQuery;
    }

    /**
     * @return float|null
     */
    public function execTime(): ?float
    {
        return $this->execTime;
    }

    /**
     * Array of columns names
     *
     * @return string[]
     */
    public function columns(): array
    {
        return $this->columns;
    }

    /**
     * Collection of rows
     *
     * @return mixed|null
     */
    public function result()
    {
        return $this->resultData;
    }

    /**
     * @return int
     */
    public function count(): int
    {
        return $this->meta['total'] ? (int)$this->meta['total'] : 0;
    }

    /**
     * @return int
     */
    public function total(): int
    {
        return $this->meta['total_found'] ? (int)$this->meta['total_found'] : 0;
    }

    /**
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
     * @return array
     */
    public function meta(): array
    {
        return $this->meta ?? [];
    }

    /**
     * @return array
     */
    public function facets(): array
    {
        return $this->facets ? array_column($this->facets, 'data') : [];
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
}
