<?php

namespace App;

/**
 * The outcome of resolving "the end block of a UTC day" (see EvmBlockResolver). Four
 * distinct outcomes, because the reconstruction walk reacts differently to each:
 *
 *  - RESOLVED: getBlock() is the last block whose timestamp is strictly before the day's
 *    end (next day 00:00:00 UTC).
 *  - BEFORE_START: no block of the chain's scanned history precedes the end of that day
 *    (the day is before the chain's history-start block). Not an error: such a day simply
 *    has no state to read.
 *  - DAY_NOT_COMPLETE: the chain's head has not reached the end of that day yet (today, or
 *    a future date), so its end block does not exist. Never stored, never guessed.
 *  - ERROR: every provider failed; getError() says how. Retrying later may succeed.
 */
final class EvmBlockResolution
{
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_BEFORE_START = 'before_start';
    public const STATUS_DAY_NOT_COMPLETE = 'day_not_complete';
    public const STATUS_ERROR = 'error';

    private function __construct(
        private string $status,
        private ?int $block = null,
        private ?EvmRpcError $error = null,
    ) {
    }

    public static function resolved(int $block): self
    {
        return new self(self::STATUS_RESOLVED, $block);
    }

    public static function beforeStart(): self
    {
        return new self(self::STATUS_BEFORE_START);
    }

    public static function dayNotComplete(): self
    {
        return new self(self::STATUS_DAY_NOT_COMPLETE);
    }

    public static function error(EvmRpcError $error): self
    {
        return new self(self::STATUS_ERROR, null, $error);
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isResolved(): bool
    {
        return $this->status === self::STATUS_RESOLVED;
    }

    public function isError(): bool
    {
        return $this->status === self::STATUS_ERROR;
    }

    /** The end block, only when isResolved(). */
    public function getBlock(): ?int
    {
        return $this->block;
    }

    public function getError(): ?EvmRpcError
    {
        return $this->error;
    }
}
