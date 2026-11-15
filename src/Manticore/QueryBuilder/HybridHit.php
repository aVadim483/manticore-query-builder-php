<?php

declare(strict_types=1);

namespace avadim\Manticore\QueryBuilder;

/** One document in a bounded reciprocal-rank-fusion result. */
final class HybridHit
{
    private array $data;

    /** @internal Constructed by HybridResult::fuse(). */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /** @return int|string An unsigned decimal string when outside PHP's integer range. */
    public function id()
    {
        return $this->data['id'];
    }

    public function document(): array
    {
        return $this->data['document'];
    }

    public function score(): float
    {
        return $this->data['score'];
    }

    public function textRank(): ?int
    {
        return $this->data['textRank'];
    }

    public function vectorRank(): ?int
    {
        return $this->data['vectorRank'];
    }

    public function textScore(): ?float
    {
        return $this->data['textScore'];
    }

    public function distance(): ?float
    {
        return $this->data['distance'];
    }

    public function toArray(): array
    {
        return $this->data;
    }
}
