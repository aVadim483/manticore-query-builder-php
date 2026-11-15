<?php

declare(strict_types=1);

namespace avadim\Manticore\QueryBuilder;

/** Materialized candidate window. Its size is not the global union total. */
final class HybridResult implements \Countable, \IteratorAggregate, \JsonSerializable
{
    /** @var HybridHit[] */
    private array $hits;
    private ?Meta $textMeta;
    private ?Meta $vectorMeta;
    private array $options;

    private function __construct(array $hits, ?Meta $textMeta, ?Meta $vectorMeta, array $options)
    {
        $this->hits = $hits;
        $this->textMeta = $textMeta;
        $this->vectorMeta = $vectorMeta;
        $this->options = $options;
    }

    /** Validate options before either search branch is executed. */
    public static function normalizeOptions(array $options): array
    {
        $defaults = ['text_weight' => 1, 'vector_weight' => 1, 'rank_constant' => 60,
            'text_candidates' => 100, 'vector_candidates' => 100, 'escape' => true, 'knn_options' => []];
        if (array_diff(array_keys($options), array_keys($defaults))) {
            throw new \InvalidArgumentException('Unknown hybrid search option.');
        }
        $options += $defaults;
        foreach (['text_weight', 'vector_weight'] as $name) {
            $value = $options[$name];
            if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value < 0 || $value > 1000000) {
                throw new \InvalidArgumentException('Hybrid weights must be finite numbers between 0 and 1000000.');
            }
        }
        foreach (['text_candidates', 'vector_candidates'] as $name) {
            if (!is_int($options[$name]) || $options[$name] < 1 || $options[$name] > 10000) {
                throw new \InvalidArgumentException('Hybrid candidate windows must be integers between 1 and 10000.');
            }
        }
        if ($options['text_weight'] + $options['vector_weight'] <= 0
            || !is_int($options['rank_constant']) || $options['rank_constant'] < 0 || $options['rank_constant'] > 1000000
            || !is_bool($options['escape']) || !is_array($options['knn_options'])) {
            throw new \InvalidArgumentException('Invalid hybrid rank constant, weights, escaping or KNN options.');
        }
        $options['knn_options'] = KnnOptions::normalize($options['knn_options']);
        return $options;
    }

    /** Fuse already captured branches without SQL; useful for reproducible ranking checks. */
    public static function fuse(?ResultSet $text, ?ResultSet $vector, array $options = []): self
    {
        $options = self::normalizeOptions($options);
        $text = $options['text_weight'] > 0 ? $text : null;
        $vector = $options['vector_weight'] > 0 ? $vector : null;
        $hits = [];
        foreach (['text' => $text, 'vector' => $vector] as $branch => $result) {
            if ($result === null) {
                continue;
            }
            if (!$result->success()) {
                throw new QueryErrorException((string)$result->error(), $result->sqlQuery());
            }
            $rows = $result->result();
            if (!is_array($rows) || count($rows) > $options[$branch . '_candidates']) {
                throw new \LogicException('Hybrid branch rows exceed their declared candidate window.');
            }
            $seen = [];
            foreach (array_values($rows) as $rank => $row) {
                if (!is_array($row) || !isset($row['id'])) {
                    throw new \LogicException('Every hybrid document must contain an unsigned document ID.');
                }
                // SQL's ID type can be transported as a signed 64-bit number. TO_STRING
                // preserves every bit even when PDO would overflow an unsigned numeric ID.
                $id = self::unsignedId($row['__mqb_hybrid_id'] ?? $row['id']);
                $key = 'id:' . $id;
                if (isset($seen[$key])) {
                    throw new \LogicException('A hybrid branch returned duplicate document IDs.');
                }
                $seen[$key] = true;
                $row['id'] = self::phpId($id);
                $textScore = $row['__mqb_hybrid_text_score'] ?? ($row['_score'] ?? null);
                $distance = $row['__mqb_hybrid_distance'] ?? ($row['_knn_dist'] ?? null);
                unset($row['__mqb_hybrid_id'], $row['__mqb_hybrid_text_score'], $row['__mqb_hybrid_distance']);
                $hit = $hits[$key] ?? ['id' => $row['id'], 'document' => $row, 'score' => 0.0,
                    'textRank' => null, 'vectorRank' => null, 'textScore' => null, 'distance' => null];
                $hit['score'] += $options[$branch . '_weight'] / ($options['rank_constant'] + $rank + 1);
                $hit[$branch . 'Rank'] = $rank + 1;
                if ($branch === 'text') {
                    $hit['textScore'] = $textScore === null ? null : (float)$textScore;
                }
                else {
                    $hit['distance'] = $distance === null ? null : (float)$distance;
                }
                $hits[$key] = $hit;
            }
        }
        $hits = array_map(static function ($hit) { return new HybridHit($hit); }, array_values($hits));
        usort($hits, static function (HybridHit $a, HybridHit $b) {
            return ($b->score() <=> $a->score()) ?: self::compareDecimal((string)$a->id(), (string)$b->id());
        });
        return new self($hits, $text !== null && $text->hasMeta() ? $text->metaObject() : null,
            $vector !== null && $vector->hasMeta() ? $vector->metaObject() : null, $options);
    }

    /** @return HybridHit[] */
    public function hits(): array { return $this->hits; }

    public function rows(): array
    {
        return array_map(static function (HybridHit $hit) { return $hit->document(); }, $this->hits);
    }

    public function first(): ?array { return $this->hits ? $this->hits[0]->document() : null; }
    public function firstHit(): ?HybridHit { return $this->hits[0] ?? null; }
    public function count(): int { return count($this->hits); }
    public function windowTotal(): int { return count($this->hits); }
    public function totalFound(): ?int { return null; }
    public function textMeta(): ?Meta { return $this->textMeta; }
    public function vectorMeta(): ?Meta { return $this->vectorMeta; }
    public function options(): array { return $this->options; }
    public function getIterator(): \Traversable { return new \ArrayIterator($this->hits); }

    /** Export ranked hits and captured metadata without executing another query. */
    public function toArray(): array
    {
        return [
            'hits' => array_map(static function (HybridHit $hit): array { return $hit->toArray(); }, $this->hits),
            'window_total' => $this->windowTotal(),
            'total_found' => null,
            'text_meta' => $this->textMeta !== null ? $this->textMeta->toArray() : null,
            'vector_meta' => $this->vectorMeta !== null ? $this->vectorMeta->toArray() : null,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** A page of the materialized window, with no additional SQL or global total. */
    public function paginateWindow(int $perPage = 20, int $page = 1): array
    {
        if ($perPage < 1 || $page < 1 || $perPage > 10000 || $page > intdiv(PHP_INT_MAX, $perPage)) {
            throw new \InvalidArgumentException('Window page and page size must be positive and within range.');
        }
        return ['data' => array_slice($this->rows(), ($page - 1) * $perPage, $perPage),
            'page' => $page, 'per_page' => $perPage, 'window_total' => $this->windowTotal(), 'total_found' => null];
    }

    private static function compareDecimal(string $a, string $b): int
    {
        return (strlen($a) <=> strlen($b)) ?: strcmp($a, $b);
    }

    private static function phpId(string $id)
    {
        return self::compareDecimal($id, (string)PHP_INT_MAX) <= 0 ? (int)$id : $id;
    }

    private static function unsignedId($value): string
    {
        if (!is_int($value) && !is_string($value)) {
            throw new \LogicException('Hybrid document IDs must be integers or exact decimal strings.');
        }
        $value = (string)$value;
        if (!preg_match('/^-?[0-9]+$/D', $value)) {
            throw new \LogicException('Hybrid document IDs must be unsigned 64-bit integers.');
        }
        if ($value[0] === '-') {
            $magnitude = ltrim(substr($value, 1), '0') ?: '0';
            if ($magnitude === '0' || self::compareDecimal($magnitude, '9223372036854775808') > 0) {
                throw new \LogicException('Invalid signed 64-bit ID transport value.');
            }
            $a = '18446744073709551616';
            $b = str_pad($magnitude, strlen($a), '0', STR_PAD_LEFT);
            $borrow = 0;
            $value = '';
            for ($i = strlen($a) - 1; $i >= 0; $i--) {
                $digit = (int)$a[$i] - (int)$b[$i] - $borrow;
                $borrow = $digit < 0 ? 1 : 0;
                $value = (string)($digit < 0 ? $digit + 10 : $digit) . $value;
            }
        }
        $value = ltrim($value, '0') ?: '0';
        if (self::compareDecimal($value, '18446744073709551615') > 0) {
            throw new \LogicException('Hybrid document ID exceeds unsigned 64-bit range.');
        }
        return $value;
    }
}
