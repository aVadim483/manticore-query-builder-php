<?php

declare(strict_types=1);

namespace avadim\Manticore\QueryBuilder;

/** The acknowledged part of a streaming write, independent of the last statement. */
final class BatchResult
{
    private int $confirmedRows;
    private int $completedBatches;
    private ?int $failedPosition;
    private ?array $failedBatch;
    private ?\Throwable $error;
    private ?\Throwable $idError;
    private bool $outcomeUnknown;
    private array $ids;

    public function __construct(
        int $confirmedRows,
        int $completedBatches,
        ?int $failedPosition = null,
        ?array $failedBatch = null,
        ?\Throwable $error = null,
        ?\Throwable $idError = null,
        bool $outcomeUnknown = false,
        array $ids = []
    ) {
        $this->confirmedRows = $confirmedRows;
        $this->completedBatches = $completedBatches;
        $this->failedPosition = $failedPosition;
        $this->failedBatch = $failedBatch;
        $this->error = $error;
        $this->idError = $idError;
        $this->outcomeUnknown = $outcomeUnknown;
        $this->ids = $ids;
    }

    public function success(): bool
    {
        return $this->error === null && $this->idError === null;
    }

    /** Input documents in acknowledged statements; not a count of distinct stored IDs. */
    public function confirmedRows(): int
    {
        return $this->confirmedRows;
    }

    public function completedBatches(): int
    {
        return $this->completedBatches;
    }

    /** Zero-based input position; iterator keys are deliberately not used. */
    public function failedPosition(): ?int
    {
        return $this->failedPosition;
    }

    /** @return array{start:int,end:int}|null inclusive input range of a failed operation */
    public function failedBatch(): ?array
    {
        return $this->failedBatch;
    }

    public function error(): ?\Throwable
    {
        return $this->error;
    }

    /** An ID query failure after acknowledgement does not undo confirmedRows(). */
    public function idError(): ?\Throwable
    {
        return $this->idError;
    }

    /** Whether the failed write may have reached the server without acknowledgement. */
    public function outcomeUnknown(): bool
    {
        return $this->outcomeUnknown;
    }

    /** @return array<int,int|string> populated only with collect_ids=true */
    public function ids(): array
    {
        return $this->ids;
    }
}
