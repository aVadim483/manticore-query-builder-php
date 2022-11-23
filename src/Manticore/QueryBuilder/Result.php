<?php

declare(strict_types=1);

namespace avadim\Manticore\QueryBuilder;

use Illuminate\Support\Collection;

class Result
{
    private ?string $command;
    private ?string $query;
    private ?float $execTime;
    private array $meta;

    private string $resultType;
    private $resultData;
    private array $columns;

    /**
     * @param $data
     */
    public function __construct($data)
    {
        $this->command = $data['command'] ?? null;
        $this->query = $data['query'] ?? null;
        $this->execTime = $data['exec_time'] ?? null;
        $this->meta = $data['meta'] ?? [];
        
        $this->resultType = $data['result']['type'];
        $this->resultData = $data['result']['data'];
        if ($this->resultType === 'collection') {
            $row = $this->first();
            $this->columns = $row ? array_keys($row) : [];
        }
        else {
            $this->columns = [];
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
    public function query(): ?string
    {
        return $this->query;
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
        if (!empty($this->resultData)) {
            if (is_array($this->resultData) || ($this->resultData instanceof Collection)) {
                return count($this->resultData);
            }
            return 1;
        }

        return 0;
    }

    /**
     * @return mixed|null
     */
    public function first()
    {
        if ($this->resultData instanceof Collection) {
            return $this->resultData->first();
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
}