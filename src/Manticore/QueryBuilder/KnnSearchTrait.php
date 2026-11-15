<?php

declare(strict_types=1);

namespace avadim\Manticore\QueryBuilder;

/** Additive KNN API; legacy whereKnn() and ranker() retain their signatures. */
trait KnnSearchTrait
{
    /** @var array|null Only applies while this extended KNN condition is still current. */
    private ?array $extendedKnnState = null;

    /** Extended numeric KNN. Replaces the previous KNN condition and sets LIMIT k. */
    public function knn(string $column, array $vector, int $k = 10, array $options = []): Query
    {
        self::knnIdentifier($column);
        if ($k <= 0) {
            throw new \InvalidArgumentException('KNN requires positive k.');
        }
        $vector = KnnOptions::vector($vector);
        $options = KnnOptions::normalize($options);
        $this->validateKnownKnnDimensions($column, $vector);
        $values = array_map(static function ($value) {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        }, $vector);
        $this->knn = 'knn(' . $column . ', ' . $k . ', (' . implode(',', $values) . ')' . KnnOptions::toSql($options) . ')';
        $this->extendedKnnState = ['sql' => $this->knn, 'column' => $column, 'vector' => $vector];
        $this->limit($k);
        return $this;
    }

    /** Select KNN_DIST() without losing it to a later select() call. */
    public function withKnnDistance(string $alias = '_knn_distance'): Query
    {
        self::knnIdentifier($alias);
        if (strpos($alias, '__mqb_') === 0 || in_array($alias, ['_score', '_knn_dist'], true)
            || isset($this->exprColumns[$alias])
            || (isset($this->distanceColumns[$alias]) && $this->distanceColumns[$alias] !== 'KNN_DIST()')) {
            throw new \InvalidArgumentException('Reserved KNN distance alias: ' . $alias);
        }
        $this->distanceColumns[$alias] = 'KNN_DIST()';
        return $this;
    }

    /** Manticore always sorts KNN by distance ascending; other directions are rejected. */
    public function orderByKnnDistance(string $direction = 'asc'): Query
    {
        if (strtolower($direction) !== 'asc') {
            throw new \InvalidArgumentException('Manticore KNN supports nearest-first distance ordering only.');
        }
        $alias = '__mqb_knn_order_distance';
        if (isset($this->exprColumns[$alias]) && $this->exprColumns[$alias] !== 'KNN_DIST()') {
            throw new \InvalidArgumentException('The internal KNN ordering alias is already in use.');
        }
        $this->exprColumns[$alias] = 'KNN_DIST()';
        return $this->orderBy($alias, 'asc');
    }

    private static function knnIdentifier(string $identifier): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $identifier)) {
            throw new \InvalidArgumentException('KNN requires a simple attribute or alias name.');
        }
    }

    /** Called before SELECT so schema probes cannot replace search metadata. */
    private function validateKnnExecution(): void
    {
        $aliases = [];
        foreach ($this->distanceColumns as $alias => $expression) {
            if ($expression === 'KNN_DIST()') {
                $aliases[] = $alias;
            }
        }
        if (isset($this->exprColumns['__mqb_knn_order_distance'])) {
            $aliases[] = '__mqb_knn_order_distance';
        }
        $currentExtended = $this->extendedKnnState !== null && $this->extendedKnnState['sql'] === $this->knn;
        if (!$aliases && !$currentExtended) {
            return;
        }
        $types = $this->columnTypes();
        $columnNames = array_map('strtolower', array_keys($types));
        foreach ($aliases as $alias) {
            if (in_array(strtolower($alias), $columnNames, true)) {
                throw new \LogicException('A KNN expression alias conflicts with a table column: ' . $alias);
            }
            foreach ($this->select as $projection) {
                if (preg_match('/(?:^|\s+as\s+|\s+)`?' . preg_quote($alias, '/') . '`?$/i', trim($projection))) {
                    throw new \LogicException('A KNN expression alias conflicts with the SELECT projection: ' . $alias);
                }
            }
        }
        if ($currentExtended) {
            $this->validateKnownKnnDimensions($this->extendedKnnState['column'], $this->extendedKnnState['vector']);
        }
    }

    /** Check cached DESCRIBE evidence only; constructing a query never opens a schema probe. */
    private function validateKnownKnnDimensions(string $column, array $vector): void
    {
        $describe = $this->indexPool[$this->_sqlTable()]['describe'] ?? null;
        if (!$describe instanceof ResultSet) {
            return;
        }
        foreach ($describe->result() ?: [] as $row) {
            if (($row['Field'] ?? null) !== $column) {
                continue;
            }
            if (($row['Type'] ?? null) !== 'float_vector') {
                throw new \InvalidArgumentException('The KNN attribute must be a float_vector.');
            }
            $properties = (string)($row['Properties'] ?? '');
            if (preg_match('/(?:knn_dims|dims)\s*[=:]\s*[\'\"]?(\d+)/i', $properties, $match)
                && (int)$match[1] !== count($vector)) {
                throw new \InvalidArgumentException('The KNN vector dimension differs from the cached schema.');
            }
            if (preg_match('/hnsw_similarity\s*[=:]\s*[\'\"]?cosine\b/i', $properties)
                && !array_filter($vector, static function ($value) { return $value != 0; })) {
                throw new \InvalidArgumentException('Cosine KNN requires a nonzero vector.');
            }
        }
    }
}
