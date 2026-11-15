<?php

declare(strict_types=1);

namespace avadim\Manticore\QueryBuilder;

trait HybridSearchTrait
{
    /** The composite search captures META before casting or calling a query logger. */
    private bool $captureSearchMeta = false;

    public function hybridSearch(string $text, string $column, array $vector, array $options = []): HybridResult
    {
        $options = HybridResult::normalizeOptions($options);
        self::knnIdentifier($column);
        KnnOptions::vector($vector);
        $this->assertHybridBase();
        $base = clone $this;
        $types = $base->columnTypes();
        $reserved = ['__mqb_hybrid_id', '__mqb_hybrid_text_score', '__mqb_hybrid_distance'];
        if (array_intersect($reserved, array_map('strtolower', array_keys($types)))) {
            throw new \LogicException('Table columns conflict with reserved hybrid transport aliases.');
        }
        if ($options['vector_weight'] > 0 && isset($types[$column]) && $types[$column] !== 'float_vector') {
            throw new \InvalidArgumentException('The hybrid KNN attribute must be a float_vector.');
        }
        $base->captureSearchMeta = true;
        $base->select('*');
        $base->distanceColumns['__mqb_hybrid_id'] = 'TO_STRING(id)';

        $textQuery = clone $base;
        $escapedText = $options['escape'] ? self::escapeMatch($text) : $text;
        $textQuery->match($escapedText)->withScore()->limit($options['text_candidates']);
        $textQuery->distanceColumns['__mqb_hybrid_text_score'] = 'WEIGHT()';
        $textQuery->orderBy('__mqb_hybrid_text_score', 'desc')->orderBy('id');

        $vectorQuery = clone $base;
        $vectorQuery->knn($column, $vector, $options['vector_candidates'], $options['knn_options'])->withoutScore();
        $vectorQuery->distanceColumns['__mqb_hybrid_distance'] = 'KNN_DIST()';
        $vectorQuery->orderBy('__mqb_hybrid_distance')->orderBy('id');

        foreach (['text' => $textQuery, 'vector' => $vectorQuery] as $name => $query) {
            $window = $options[$name . '_candidates'];
            if (isset($query->options['max_matches'])) {
                if (!is_int($query->options['max_matches']) || $query->options['max_matches'] < $window) {
                    throw new \InvalidArgumentException('Explicit max_matches must cover each hybrid candidate window.');
                }
            }
            else {
                $query->option('max_matches', max(1000, $window));
            }
            // Both trees are compiled before the first branch is sent to the server.
            $query->toSql();
        }
        $textResult = $options['text_weight'] > 0 && $text !== '' ? $this->executeHybridBranch($textQuery) : null;
        $vectorResult = $options['vector_weight'] > 0 ? $this->executeHybridBranch($vectorQuery) : null;
        return HybridResult::fuse($textResult, $vectorResult, $options);
    }

    public function hybridGet(string $text, string $column, array $vector, array $options = []): array
    {
        return $this->hybridSearch($text, $column, $vector, $options)->rows();
    }

    public function hybridFirst(string $text, string $column, array $vector, array $options = []): ?array
    {
        return $this->hybridSearch($text, $column, $vector, $options)->first();
    }

    private function executeHybridBranch(Query $query): ResultSet
    {
        $result = $query->exec();
        // A manually constructed Query has a value slot, unlike a Connection's shared
        // slot. Publish both successful and failed branches on the original as well.
        $this->resultSlot['last'] = $result;
        if (!$result->success()) {
            throw new QueryErrorException((string)$result->error(), $result->sqlQuery());
        }
        return $result;
    }

    private function assertHybridBase(): void
    {
        if (!$this->_sqlTable() || $this->sql !== null || ($this->command !== null && $this->command !== 'SELECT')
            || ($this->select && $this->select !== ['*']) || $this->match !== null || $this->knn !== null
            || $this->joins || $this->group || $this->having || $this->order || $this->limit || $this->facets
            || $this->highlight || $this->distanceColumns || $this->hasComposedFullText()
            || array_intersect(array_keys($this->options), ['fusion_method', 'fusion_weights', 'rank_constant', 'window_size'])) {
            throw new \LogicException('Hybrid requires a plain common attribute query without projection, MATCH/KNN, grouping, ordering or result windows.');
        }
        if ($this->conditions->hasRawConditions()) {
            throw new \LogicException('Hybrid common predicates must be structured attribute conditions, not raw SQL.');
        }
    }
}
