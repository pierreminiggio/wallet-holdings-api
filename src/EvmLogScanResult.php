<?php

namespace App;

/**
 * What one EvmLogScanner::scan() call achieved. Every status except COMPLETE is resumable:
 * the state it carries is a valid resume point (no hole), so the caller persists it and the
 * next call continues from it.
 */
final class EvmLogScanResult
{
    /** The scan reached the requested block. */
    public const STATUS_COMPLETE = 'complete';

    /** The deadline passed before the requested block was reached. */
    public const STATUS_TIME_BUDGET = 'time_budget';

    /** stopAtFirstLog was set and a chunk contained Transfer logs touching the wallet. */
    public const STATUS_FIRST_ACTIVITY = 'first_activity';

    /** No provider could serve the scan right now (outage, auth, or too many unresolvable blocks in a row). */
    public const STATUS_PROVIDER_ERROR = 'provider_error';

    /**
     * @param list<array{block: int, error: string}> $gaps blocks recorded as unresolved by THIS call
     */
    public function __construct(
        private string $status,
        private EvmLogScanState $state,
        private array $gaps,
        private int $chunks,
        private ?string $error = null,
    ) {
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isComplete(): bool
    {
        return $this->status === self::STATUS_COMPLETE;
    }

    public function getState(): EvmLogScanState
    {
        return $this->state;
    }

    /** @return list<array{block: int, error: string}> */
    public function getGaps(): array
    {
        return $this->gaps;
    }

    /** Number of chunks successfully fetched by this call. */
    public function getChunks(): int
    {
        return $this->chunks;
    }

    public function getError(): ?string
    {
        return $this->error;
    }
}
